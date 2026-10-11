<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Subject;
use App\Models\Teacher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicTermModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_casts_preserve_calendar_dates_and_do_not_derive_status(): void
    {
        $term = AcademicTerm::create([
            'name' => 'Periodo histórico', 'start_date' => '2024-02-29',
            'end_date' => '2024-02-29', 'status' => 'INACTIVE',
        ])->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $term->start_date);
        $this->assertInstanceOf(CarbonImmutable::class, $term->end_date);
        $this->assertSame('2024-02-29', $term->start_date->toDateString());
        $this->assertSame('2024-02-29', $term->end_date->toDateString());
        $this->assertSame('INACTIVE', $term->status);
    }

    public function test_mass_assignment_does_not_accept_protected_fields(): void
    {
        $term = new AcademicTerm;
        $term->fill(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30',
            'status' => 'INACTIVE', 'id' => 999, 'created_at' => '2000-01-01']);

        $this->assertNull($term->id);
        $this->assertNull($term->created_at);
        $this->assertSame('I/2027', $term->name);
    }

    public function test_inactive_period_keeps_historical_offerings_without_global_filter(): void
    {
        $term = AcademicTerm::create(['name' => 'II/2026', 'start_date' => '2026-08-01', 'end_date' => '2026-12-31', 'status' => 'ACTIVE']);
        $subject = Subject::create(['code' => 'INF-01', 'name' => 'Programación I', 'status' => 'ACTIVE']);
        $offering = CourseOffering::create(['subject_id' => $subject->id, 'teacher_id' => Teacher::factory()->create()->id,
            'academic_term_id' => $term->id, 'status' => 'ACTIVE']);
        $before = $offering->fresh()->getAttributes();
        $term->update(['status' => 'INACTIVE']);

        $this->assertTrue($term->fresh()->courseOfferings->sole()->is($offering));
        $this->assertSame($before, $offering->fresh()->getAttributes());
        $this->assertTrue($offering->fresh()->academicTerm->is($term));
        $this->assertSame('INACTIVE', $offering->fresh()->academicTerm->status);
    }
}
