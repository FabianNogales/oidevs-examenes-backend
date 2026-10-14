<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Subject;
use App\Models\Teacher;
use App\Services\AcademicTerms\AcademicTermOfferingGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class AcademicTermOfferingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_or_unknown_term_never_runs_writer(): void
    {
        $term = $this->term('INACTIVE');
        foreach ([$term->id, 99999] as $id) {
            $ran = false;
            try {
                $this->guard()->write($id, function () use (&$ran) {
                    $ran = true;
                    Subject::create(['code' => 'PARTIAL', 'name' => 'Parcial', 'status' => 'ACTIVE']);
                });
                $this->fail('Expected rejection.');
            } catch (HttpResponseException $exception) {
                $this->assertSame(422, $exception->getResponse()->getStatusCode());
                $response = json_decode($exception->getResponse()->getContent(), true);
                $this->assertArrayHasKey('academic_term_id', $response['errors']);
                $this->assertSame($id === $term->id ? 'ACADEMIC_TERM_INACTIVE' : 'VALIDATION_ERROR', $response['code']);
            }
            $this->assertFalse($ran);
        }
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('course_offerings', 0);
    }

    public function test_active_period_allows_real_offering_and_deactivation_keeps_history(): void
    {
        $term = $this->term('ACTIVE');
        $offering = $this->guard()->write($term->id, function ($selected) {
            $this->assertSame('ACTIVE', $selected->status);

            return CourseOffering::create($this->offeringPayload($selected->id));
        });
        $before = $offering->fresh()->getAttributes();
        $term->update(['status' => 'INACTIVE']);
        $this->assertSame($before, $offering->fresh()->getAttributes());
        $this->assertTrue($offering->fresh()->academicTerm->is($term));
    }

    public function test_reassignment_to_inactive_term_preserves_original_offering(): void
    {
        $first = $this->term('ACTIVE');
        $offering = $this->guard()->write($first->id, fn () => CourseOffering::create($this->offeringPayload($first->id)));
        $inactive = AcademicTerm::create(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'INACTIVE']);
        try {
            $this->guard()->write($inactive->id, fn () => $offering->update(['academic_term_id' => $inactive->id]));
            $this->fail('Expected inactive period rejection.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
        }
        $this->assertSame($first->id, $offering->fresh()->academic_term_id);
    }

    public function test_failure_rolls_back_subject_teacher_and_offering_created_by_writer(): void
    {
        $term = $this->term('ACTIVE');
        try {
            $this->guard()->write($term->id, function () use ($term) {
                CourseOffering::create($this->offeringPayload($term->id));
                throw new \RuntimeException('Writer failed');
            });
            $this->fail('Expected failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Writer failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('course_offerings', 0);
        $this->assertDatabaseCount('subjects', 0);
        $this->assertDatabaseCount('teachers', 0);
    }

    private function offeringPayload(int $termId): array
    {
        return ['academic_term_id' => $termId,
            'subject_id' => Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE'])->id,
            'teacher_id' => Teacher::factory()->create()->id, 'status' => 'ACTIVE'];
    }

    private function term(string $status): AcademicTerm
    {
        return AcademicTerm::create(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => $status]);
    }

    private function guard(): AcademicTermOfferingGuard
    {
        return app(AcademicTermOfferingGuard::class);
    }
}
