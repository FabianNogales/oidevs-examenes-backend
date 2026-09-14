<?php

namespace Tests\Feature\Teachers;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'eida.institutional_email_domains' => ['umss.edu.bo'],
            'session.driver' => 'file',
        ]);
        Session::setDefaultDriver('file');
    }

    public function test_administrator_can_manage_teacher_endpoints(): void
    {
        $admin = $this->createUserWithRole(RoleName::ADMINISTRADOR);
        $this->createRole(RoleName::DOCENTE);
        $teacher = Teacher::factory()->create([
            'institutional_code' => 'DOC-100',
            'identity_number' => '100100',
            'first_names' => 'Ana',
            'last_names' => 'Rojas',
        ]);
        $teacher->user->forceFill(['email' => 'ana.rojas@umss.edu.bo'])->save();

        $this->actingAsCurrentSession($admin)
            ->getJson('/api/v1/admin/teachers')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/v1/admin/teachers/'.$teacher->id)
            ->assertOk()
            ->assertJsonPath('data.id', $teacher->id)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.active_session_id')
            ->assertDontSee($teacher->user->password);

        $this->postJson('/api/v1/admin/teachers', $this->teacherPayload([
            'institutional_code' => 'DOC-101',
            'identity_number' => '101101',
            'email' => 'created.teacher@umss.edu.bo',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.email', 'created.teacher@umss.edu.bo')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.active_session_id');

        $this->putJson('/api/v1/admin/teachers/'.$teacher->id, [
            'institutional_code' => 'DOC-102',
            'identity_number' => '102102',
            'first_names' => 'Ana Maria',
            'last_names' => 'Rojas Soto',
            'email' => 'updated.teacher@umss.edu.bo',
        ])
            ->assertOk()
            ->assertJsonPath('data.institutional_code', 'DOC-102')
            ->assertJsonPath('data.email', 'updated.teacher@umss.edu.bo');

        $this->patchJson('/api/v1/admin/teachers/'.$teacher->id.'/status', [
            'status' => UserStatus::INACTIVE->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', UserStatus::INACTIVE->value);
    }

    public function test_guest_cannot_access_teacher_admin_endpoints(): void
    {
        $this->getJson('/api/v1/admin/teachers')
            ->assertUnauthorized();
    }

    public function test_docente_cannot_access_teacher_admin_endpoints(): void
    {
        $user = $this->createUserWithRole(RoleName::DOCENTE);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/admin/teachers')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_estudiante_cannot_access_teacher_admin_endpoints(): void
    {
        $user = $this->createUserWithRole(RoleName::ESTUDIANTE);

        $this->actingAsCurrentSession($user)
            ->getJson('/api/v1/admin/teachers')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_first_access_administrator_cannot_access_teacher_admin_endpoints(): void
    {
        $admin = $this->createUserWithRole(RoleName::ADMINISTRADOR, mustChangePassword: true);

        $this->actingAsCurrentSession($admin)
            ->getJson('/api/v1/admin/teachers')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_replaced_session_cannot_access_teacher_admin_endpoints(): void
    {
        $admin = $this->createUserWithRole(
            RoleName::ADMINISTRADOR,
            false,
            'replaced.admin@umss.edu.bo',
        );
        $sessionA = $this->loginFromNewSession($admin);
        $this->loginFromNewSession($admin);

        $this->useSessionCookie($sessionA)
            ->getJson('/api/v1/admin/teachers')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'SESSION_REPLACED');
    }

    private function createUserWithRole(
        RoleName $roleName,
        bool $mustChangePassword = false,
        ?string $email = null,
    ): User {
        $attributes = [
            'status' => UserStatus::ACTIVE->value,
            'must_change_password' => $mustChangePassword,
            'password' => 'Password1',
        ];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        $user = User::factory()->create($attributes);
        $role = $this->createRole($roleName);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'status' => UserStatus::ACTIVE->value,
        ]);

        return $user;
    }

    private function loginFromNewSession(User $user): string
    {
        $this->useSessionCookie(Str::random(40));

        $this->postJson('/login', [
            'identifier' => $user->email,
            'password' => 'Password1',
        ])->assertOk();

        return $user->refresh()->active_session_id;
    }

    private function useSessionCookie(string $sessionId, bool $persistCurrentSession = true): self
    {
        if ($persistCurrentSession && $this->app['session']->isStarted()) {
            $this->app['session']->save();
        }

        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->app['session']->setId($sessionId);
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this
            ->withCredentials()
            ->withHeader('Origin', 'http://127.0.0.1:5173')
            ->withCookie(config('session.cookie'), $sessionId);
    }

    private function createRole(RoleName $roleName): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => $roleName->value],
            [
                'description' => $roleName->value,
                'status' => UserStatus::ACTIVE->value,
            ],
        );
    }

    private function actingAsCurrentSession(User $user): self
    {
        $this->startSession();

        $user->forceFill([
            'active_session_id' => $this->app['session']->getId(),
        ])->save();

        return $this->actingAs($user);
    }

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