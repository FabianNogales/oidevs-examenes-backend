<?php

namespace Tests\Feature\AcademicTerms;

use App\Models\AcademicTerm;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class AcademicTermIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_validation_normalizes_names_and_accepts_real_leap_and_single_day_dates(): void
    {
        $data = $this->rules()->validatePayload($this->payload(['name' => '  Gestión 2024  ', 'start_date' => '2024-02-29', 'end_date' => '2024-02-29']), true);
        $this->assertSame('Gestión 2024', $data['name']);
        $this->assertSame('2024-02-29', $data['end_date']);
    }

    public function test_invalid_dates_names_and_protected_fields_are_rejected_without_writes(): void
    {
        foreach (['2027-02-29', '2027-13-01', '01/01/2027', '2027-01-01T00:00:00Z', '0000-01-01', '2027-1-1'] as $date) {
            $this->rejected(fn () => $this->rules()->validatePayload($this->payload(['start_date' => $date]), true), 422, 'VALIDATION_ERROR');
        }
        foreach (['!', "Nombre\n", str_repeat('a', 256)] as $name) {
            $this->rejected(fn () => $this->rules()->validatePayload($this->payload(['name' => $name]), true), 422, 'VALIDATION_ERROR');
        }
        $this->rejected(fn () => $this->rules()->validatePayload($this->payload(['end_date' => '2026-12-31']), true), 422, 'VALIDATION_ERROR');
        $this->rejected(fn () => $this->rules()->validatePayload($this->payload(), false), 422, 'VALIDATION_ERROR');
        $this->rejected(fn () => $this->rules()->validatePayload($this->payload() + ['id' => 99], true), 422, 'VALIDATION_ERROR');
        $this->assertDatabaseCount('academic_terms', 0);
    }

    public function test_name_uniqueness_includes_case_trim_and_inactive_periods(): void
    {
        AcademicTerm::create($this->payload(['name' => ' I/2027 ']));
        $data = $this->payload(['name' => 'i/2027', 'start_date' => '2028-01-01', 'end_date' => '2028-06-30']);
        $this->rejected(fn () => $this->rules()->transaction(fn () => $this->rules()->assertCanSave($data)), 422, 'ACADEMIC_TERM_NAME_DUPLICATE');
    }

    public function test_inclusive_overlaps_reject_partial_contained_and_containing_ranges(): void
    {
        $term = AcademicTerm::create($this->payload());
        foreach ([['2026-12-01', '2027-01-01'], ['2027-06-30', '2027-12-31'], ['2027-02-01', '2027-03-01'], ['2026-01-01', '2028-01-01']] as [$start, $end]) {
            $data = $this->payload(['name' => 'Otro periodo', 'start_date' => $start, 'end_date' => $end]);
            $result = $this->rejected(fn () => $this->rules()->transaction(fn () => $this->rules()->assertCanSave($data)), 422, 'ACADEMIC_TERM_OVERLAP');
            $this->assertSame($term->id, $result['conflicting_period']['id']);
        }
        $this->rules()->transaction(fn () => $this->rules()->assertCanSave($this->payload(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31'])));
        $this->rules()->transaction(fn () => $this->assertTrue($this->rules()->assertCanSave($this->payload(), $term->id)->is($term)));
    }

    public function test_second_active_is_rejected_without_deactivating_first(): void
    {
        $first = AcademicTerm::create($this->payload(['status' => 'ACTIVE']));
        $data = $this->payload(['name' => 'II/2027', 'start_date' => '2027-07-01', 'end_date' => '2027-12-31', 'status' => 'ACTIVE']);
        $this->rejected(fn () => $this->rules()->transaction(fn () => $this->rules()->assertCanSave($data)), 409, 'ACADEMIC_TERM_ACTIVE_CONFLICT');
        $second = AcademicTerm::create(array_replace($data, ['status' => 'INACTIVE']));
        $this->rejected(fn () => $this->rules()->transaction(fn () => $this->rules()->assertCanChangeStatus($second->id, 'ACTIVE')), 409, 'ACADEMIC_TERM_ACTIVE_CONFLICT');
        $this->rules()->transaction(fn () => $this->assertTrue($this->rules()->assertCanChangeStatus($first->id, 'ACTIVE')->is($first)));
        $this->assertSame('ACTIVE', $first->fresh()->status);
        $this->assertSame('INACTIVE', $second->fresh()->status);
    }

    public function test_transaction_rolls_back_writes_and_unknown_ids_never_create_records(): void
    {
        $this->rejected(fn () => $this->rules()->transaction(fn () => $this->rules()->assertCanSave($this->payload(), 99999)), 404, 'NOT_FOUND');
        try {
            $this->rules()->transaction(function () {
                $this->rules()->assertCanSave($this->payload());
                AcademicTerm::create($this->payload());
                throw new \RuntimeException('Write failed');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Write failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('academic_terms', 0);
    }

    private function rules(): AcademicTermIntegrity
    {
        return app(AcademicTermIntegrity::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['name' => 'I/2027', 'start_date' => '2027-01-01', 'end_date' => '2027-06-30', 'status' => 'INACTIVE'], $overrides);
    }

    private function rejected(\Closure $operation, int $status, string $code): array
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (HttpResponseException $exception) {
            $this->assertSame($status, $exception->getResponse()->getStatusCode());
            $data = json_decode($exception->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($code, $data['code']);

            return $data;
        }
    }
}
