<?php

namespace Tests\Feature\Teachers;

use App\Http\Requests\Api\V1\Teachers\StoreTeacherRequest;
use App\Http\Requests\Api\V1\Teachers\UpdateTeacherRequest;
use App\Http\Requests\Api\V1\Teachers\UpdateTeacherStatusRequest;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TeacherRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
        ]);

        Route::post('/testing/teachers', fn (StoreTeacherRequest $request) => response()->json($request->validated()));
        Route::put('/testing/teachers/{teacher}', fn (UpdateTeacherRequest $request, Teacher $teacher) => response()->json($request->validated()));
        Route::patch('/testing/teachers/{teacher}/status', fn (UpdateTeacherStatusRequest $request, Teacher $teacher) => response()->json($request->validated()));
    }

    public function test_store_teacher_requires_main_fields(): void
    {
        $this->postJson('/testing/teachers', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'institutional_code',
                'identity_number',
                'first_names',
                'last_names',
                'email',
            ]);
    }

    public function test_store_teacher_rejects_invalid_external_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'duplicate@umss.edu.bo']);

        $this->postJson('/testing/teachers', $this->teacherPayload([
            'email' => 'invalid-email',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/testing/teachers', $this->teacherPayload([
            'email' => 'external@gmail.com',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/testing/teachers', $this->teacherPayload([
            'email' => 'duplicate@umss.edu.bo',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_store_teacher_rejects_duplicate_institutional_code_and_identity_number(): void
    {
        Teacher::factory()->create([
            'institutional_code' => 'DOC-100',
            'identity_number' => '1234567',
        ]);

        $this->postJson('/testing/teachers', $this->teacherPayload([
            'institutional_code' => 'DOC-100',
        ]))->assertUnprocessable()->assertJsonValidationErrors('institutional_code');

        $this->postJson('/testing/teachers', $this->teacherPayload([
            'identity_number' => '1234567',
        ]))->assertUnprocessable()->assertJsonValidationErrors('identity_number');
    }

    public function test_update_teacher_allows_own_unique_values_and_rejects_other_teacher_values(): void
    {
        $teacher = Teacher::factory()->create([
            'institutional_code' => 'DOC-200',
            'identity_number' => '200200',
        ]);
        $teacher->user->forceFill(['email' => 'teacher.one@umss.edu.bo'])->save();

        $otherTeacher = Teacher::factory()->create([
            'institutional_code' => 'DOC-201',
            'identity_number' => '201201',
        ]);
        $otherTeacher->user->forceFill(['email' => 'teacher.two@umss.edu.bo'])->save();

        $this->putJson('/testing/teachers/'.$teacher->id, [
            'institutional_code' => 'DOC-200',
            'identity_number' => '200200',
            'email' => 'teacher.one@umss.edu.bo',
        ])->assertOk();

        $this->putJson('/testing/teachers/'.$teacher->id, [
            'institutional_code' => 'DOC-201',
            'identity_number' => '201201',
            'email' => 'teacher.two@umss.edu.bo',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'institutional_code',
                'identity_number',
                'email',
            ]);
    }

    public function test_update_teacher_status_accepts_only_active_or_inactive(): void
    {
        $teacher = Teacher::factory()->create();

        $this->patchJson('/testing/teachers/'.$teacher->id.'/status', [
            'status' => 'inactive',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'INACTIVE');

        $this->patchJson('/testing/teachers/'.$teacher->id.'/status', [
            'status' => 'DELETED',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function teacherPayload(array $overrides = []): array
    {
        return array_merge([
            'institutional_code' => 'DOC-999',
            'identity_number' => '9999999',
            'first_names' => 'Test',
            'last_names' => 'Teacher',
            'email' => 'test.teacher@umss.edu.bo',
        ], $overrides);
    }
}
