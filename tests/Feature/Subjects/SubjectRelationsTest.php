<?php

namespace Tests\Feature\Subjects;

use App\Models\Career;
use App\Models\CourseOffering;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_is_shared_without_duplication_and_pivot_has_timestamps(): void
    {
        $subject = Subject::create(['code' => 'MAT101', 'name' => 'Matemáticas', 'status' => 'ACTIVE']);
        $first = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $second = Career::create(['code' => 'INF', 'name' => 'Informática', 'status' => 'INACTIVE']);

        $subject->careers()->syncWithoutDetaching([$first->id, $second->id]);
        $subject->careers()->syncWithoutDetaching([$first->id]);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $subject->careers->modelKeys());
        $this->assertTrue($first->subjects->sole()->is($subject));
        $this->assertTrue($second->subjects->sole()->is($subject));
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('career_subject', 2);
        foreach ($subject->careers as $career) {
            $this->assertNotNull($career->pivot->created_at);
            $this->assertNotNull($career->pivot->updated_at);
        }
    }

    public function test_historical_subject_without_careers_keeps_its_offerings(): void
    {
        $subject = Subject::create(['code' => 'OLD101', 'name' => 'Materia anterior', 'status' => 'INACTIVE']);
        $teacher = Teacher::factory()->create();
        $term = DB::table('academic_terms')->insertGetId([
            'name' => '2026-II', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE',
        ]);
        $offering = CourseOffering::create([
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'academic_term_id' => $term, 'status' => 'ACTIVE',
        ]);

        $loaded = Subject::with(['careers', 'courseOfferings'])->findOrFail($subject->id);

        $this->assertTrue($loaded->careers->isEmpty());
        $this->assertTrue($loaded->courseOfferings->sole()->is($offering));
        $this->assertTrue($offering->subject->is($loaded));
        $this->assertDatabaseCount('career_subject', 0);
    }

    public function test_removing_catalog_association_preserves_both_entities(): void
    {
        $subject = Subject::create(['code' => 'MAT101', 'name' => 'Matemáticas', 'status' => 'ACTIVE']);
        $career = Career::create(['code' => 'SIS', 'name' => 'Sistemas', 'status' => 'ACTIVE']);
        $subject->careers()->attach($career->id);

        $subject->careers()->detach($career->id);

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertDatabaseHas('careers', ['id' => $career->id]);
        $this->assertDatabaseCount('career_subject', 0);
    }
}
