<?php

namespace Tests\Unit\Services\Exams;

use App\Enums\EvaluationType;
use App\Models\AcademicTerm;
use App\Services\Exams\ExamSchedulePolicy;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExamSchedulePolicyTest extends TestCase
{
    private ExamSchedulePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ExamSchedulePolicy;
    }

    public function test_evaluation_type_enum_includes_recovery_and_only_partial_supports_partial_number(): void
    {
        $this->assertSame(['partial', 'final', 'makeup', 'recovery'], EvaluationType::values());
        $this->assertSame('in:partial,final,makeup,recovery', EvaluationType::validationRule());
        $this->assertTrue(EvaluationType::PARTIAL->supportsPartialNumber());
        $this->assertFalse(EvaluationType::FINAL->supportsPartialNumber());
        $this->assertFalse(EvaluationType::MAKEUP->supportsPartialNumber());
        $this->assertFalse(EvaluationType::RECOVERY->supportsPartialNumber());
    }

    public static function reservedMinutesProvider(): array
    {
        return [
            'zero' => [0, 0],
            'negative' => [-10, 0],
            '1 min reserves 1 period' => [1, 90],
            '60 min reserves 1 period' => [60, 90],
            '90 min reserves 1 period' => [90, 90],
            '91 min reserves 2 periods' => [91, 180],
            '120 min reserves 2 periods' => [120, 180],
            '180 min reserves 2 periods' => [180, 180],
            '181 min reserves 3 periods' => [181, 270],
            '270 min reserves 3 periods' => [270, 270],
        ];
    }

    #[DataProvider('reservedMinutesProvider')]
    public function test_reserved_minutes_rounds_up_to_90_minute_blocks(int $duration, int $expected): void
    {
        $this->assertSame($expected, $this->policy->reservedMinutes($duration));
    }

    public function test_institutional_start_times_are_strictly_validated(): void
    {
        $expected = [
            '06:45:00', '08:15:00', '09:45:00', '11:15:00', '12:45:00',
            '14:15:00', '15:45:00', '17:15:00', '18:45:00', '20:15:00',
        ];
        $this->assertCount(10, ExamSchedulePolicy::ALLOWED_START_TIMES);
        foreach ($expected as $time) {
            $this->assertTrue($this->policy->isAllowedStartTime($time));
        }
        foreach (['06:44:00', '08:00:00', '10:00:00', '21:45:00', '22:00:00'] as $invalid) {
            $this->assertFalse($this->policy->isAllowedStartTime($invalid));
        }
    }

    public static function dayBoundaryProvider(): array
    {
        return [
            '06:45 with 270 min ends 11:15' => ['06:45:00', 270, true],
            '17:15 with 270 min ends 21:45' => ['17:15:00', 270, true],
            '18:45 with 180 min ends 21:45' => ['18:45:00', 180, true],
            '18:45 with 181 min reserves 270 ends 23:15' => ['18:45:00', 181, false],
            '20:15 with 90 min ends 21:45' => ['20:15:00', 90, true],
            '20:15 with 91 min reserves 180 ends 23:15' => ['20:15:00', 91, false],
            '20:15 with 120 min reserves 180 ends 23:15' => ['20:15:00', 120, false],
        ];
    }

    #[DataProvider('dayBoundaryProvider')]
    public function test_fits_within_institutional_day_uses_reserved_block_end(string $start, int $duration, bool $expected): void
    {
        $this->assertSame($expected, $this->policy->fitsWithinInstitutionalDay($start, $duration));
    }

    public function test_resolve_partial_number_is_null_for_non_partial_types(): void
    {
        $this->assertNull($this->policy->resolvePartialNumber('recovery', 2, 'Recuperatorio 2'));
        $this->assertNull($this->policy->resolvePartialNumber('final', 1, 'Final 1'));
        $this->assertNull($this->policy->resolvePartialNumber('makeup', 3, 'Segunda Instancia 3'));
        $this->assertSame(2, $this->policy->resolvePartialNumber('partial', 2, 'Examen'));
        $this->assertSame(3, $this->policy->resolvePartialNumber('partial', null, '3er Parcial'));
        $this->assertNull($this->policy->resolvePartialNumber('partial', null, 'Parcial General'));
    }

    public function test_assert_within_academic_term_allows_inclusive_bounds_and_rejects_outside(): void
    {
        $term = new AcademicTerm([
            'name' => 'II/2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-15',
        ]);

        $this->policy->assertWithinAcademicTerm($term, '2026-08-01');
        $this->policy->assertWithinAcademicTerm($term, '2026-10-15');
        $this->policy->assertWithinAcademicTerm($term, '2026-12-15');

        foreach (['2026-07-31', '2026-12-16'] as $outsideDate) {
            try {
                $this->policy->assertWithinAcademicTerm($term, $outsideDate);
                $this->fail("Expected ValidationException for date {$outsideDate}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('exam_date', $exception->errors());
            }
        }
    }
}
