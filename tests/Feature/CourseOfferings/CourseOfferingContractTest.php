<?php

namespace Tests\Feature\CourseOfferings;

use App\Http\Resources\CourseOfferings\CourseOfferingResource;
use App\Models\AcademicTerm;
use App\Models\Career;
use App\Models\CourseOffering;
use App\Models\CourseOfferingImport;
use App\Models\Room;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CourseOfferingContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_keeps_real_teacher_id_and_historical_subject_without_careers(): void
    {
        User::factory()->create();
        $teacher = Teacher::factory()->create(['status' => 'INACTIVE']);
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'INACTIVE']);
        $term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE']);
        $offering = CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id, 'status' => 'INACTIVE']);
        $loaded = CourseOffering::withContractRelations()->findOrFail($offering->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = CourseOfferingResource::make($loaded)->response()->getData(true)['data'];
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertNotSame($teacher->user_id, $teacher->id);
        $this->assertSame($teacher->id, $data['teacher']['id']);
        $this->assertSame('INACTIVE', $data['teacher']['status']);
        $this->assertSame($teacher->user->email, $data['teacher']['email']);
        $this->assertSame([], $data['subject']['careers']);
        $this->assertSame('2027-01-01', $data['academic_term']['start_date']);
        $this->assertSame('INACTIVE', $data['status']);
        $this->assertSame(0, $data['enrollments_count']);
        $this->assertSame(0, $data['exams_count']);
        $this->assertArrayNotHasKey('password', $data['teacher']);
        $this->assertArrayNotHasKey('identity_number', $data['teacher']);
        $this->assertTrue($teacher->courseOfferings->sole()->is($offering));
    }

    public function test_counts_include_inactive_enrollments_and_cancelled_exams(): void
    {
        $teacher = Teacher::factory()->create();
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $term = AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'ACTIVE']);
        $offering = CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subject->id, 'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $subject->careers()->attach($career->id);
        $student = DB::table('students')->insertGetId(['user_id' => User::factory()->create()->id, 'sis_code' => '202700001',
            'identity_number' => '81000001', 'first_names' => 'Ana', 'last_names' => 'Torres', 'career_id' => $career->id, 'status' => 'INACTIVE']);
        DB::table('enrollments')->insert(['course_offering_id' => $offering->id, 'student_id' => $student, 'status' => 'INACTIVE', 'registered_by' => $teacher->user_id]);
        $room = Room::create(['code' => 'A-01', 'status' => 'ACTIVE']);
        DB::table('exams')->insert(['course_offering_id' => $offering->id, 'room_id' => $room->id, 'name' => 'Parcial cancelado',
            'exam_date' => '2027-03-01', 'start_time' => '10:00:00', 'duration_minutes' => 60, 'evaluation_type' => 'partial', 'status' => 'CANCELLED', 'created_by' => $teacher->user_id]);
        $data = CourseOfferingResource::make(CourseOffering::withContractRelations()->findOrFail($offering->id))->response()->getData(true)['data'];
        $this->assertSame(1, $data['enrollments_count']);
        $this->assertSame(1, $data['exams_count']);
        $this->assertSame($career->id, $data['subject']['careers'][0]['id']);
        $this->assertDatabaseCount('course_offerings', 1);
    }

    public function test_import_metadata_preserves_period_owner_reports_and_timezone(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $expires = now()->utc()->addMinutes(30);
        $import = CourseOfferingImport::create(['preview_id' => (string) Str::uuid(), 'user_id' => $user->id,
            'file_hash' => str_repeat('a', 64), 'status' => 'PENDING', 'expires_at' => $expires,
            'preview_report' => ['academic_term_id' => 7, 'summary' => ['total' => 0], 'rows' => []]])->fresh();
        $this->assertSame(7, $import->preview_report['academic_term_id']);
        $this->assertSame($expires->timestamp, $import->expires_at->timestamp);
        $this->assertTrue($import->user->is($user));
        $this->assertNull($import->result_report);
        $this->assertNull($import->completed_at);
        $this->assertDatabaseCount('subject_imports', 0);
        $this->assertDatabaseCount('room_imports', 0);
    }
}
