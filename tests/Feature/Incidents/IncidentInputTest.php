<?php

namespace Tests\Feature\Incidents;

use App\Enums\IncidentType;
use App\Http\Requests\Incidents\StoreIncidentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IncidentInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Validation harness only: no production route or persistence is enabled by T01.
        Route::post('/_test/incidents', fn (StoreIncidentRequest $request) => response()->json($request->validated()));
    }

    public function test_each_category_accepts_a_manual_report_without_identifiable_student(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (IncidentType::cases() as $type) {
            $this->postJson('/_test/incidents', ['type' => $type->value, 'description' => '  Reporte del control de ingreso.  '])
                ->assertOk()->assertExactJson(['type' => $type->value, 'description' => 'Reporte del control de ingreso.']);
        }
    }

    public function test_null_student_is_allowed(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/_test/incidents', ['type' => 'ENVIRONMENT', 'description' => 'Falla de iluminación.', 'student_id' => null])
            ->assertOk()->assertJsonPath('student_id', null);
    }

    public function test_missing_fields_and_whitespace_description_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/_test/incidents', [])->assertUnprocessable()->assertJsonValidationErrors(['type', 'description']);
        $this->postJson('/_test/incidents', ['type' => 'ENVIRONMENT', 'description' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_unknown_categories_and_non_text_descriptions_are_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/_test/incidents', ['type' => 'CONFIRMED_INFRACTION', 'description' => ['unexpected']])
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'description']);
    }

    public function test_nonexistent_student_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/_test/incidents', ['type' => 'IDENTIFICATION', 'description' => 'Identificación ilegible.', 'student_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
    }

    public function test_client_cannot_supply_origin_exam_actor_timestamp_or_disciplinary_decision(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['origin', 'exam_id', 'reported_by', 'created_at', 'confirmed_infraction', 'unexpected'] as $field) {
            $this->postJson('/_test/incidents', ['type' => 'SUSPECTED_IMPERSONATION', 'description' => 'Identidad por verificar.', $field => 'forged'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/_test/incidents', ['type' => 'ENVIRONMENT', 'description' => 'Sin iluminación.'])
            ->assertForbidden();
    }
}
