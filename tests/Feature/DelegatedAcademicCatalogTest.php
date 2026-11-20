<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Career;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Room;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admin\RolePermissionCatalog;
use App\Services\AcademicTerms\AcademicTermService;
use App\Services\Subjects\SubjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CourseOfferingFixture;
use Tests\TestCase;

class DelegatedAcademicCatalogTest extends TestCase
{
    use RefreshDatabase, CourseOfferingFixture;

    private Career $career;
    private Career $inactiveCareer;
    private Subject $subject;
    private AcademicTerm $term;

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.connections.sqlite');
        if (! $app->environment('testing') || $app->configurationIsCached()
            || $app['config']->get('database.default') !== 'sqlite' || $connection['database'] !== ':memory:'
            || ! empty($connection['url']) || $app['config']->get('session.driver') !== 'array'
            || $app['config']->get('cache.default') !== 'array') {
            throw new \RuntimeException('Catalog tests require testing, SQLite :memory: and array session/cache.');
        }
        foreach ($app['db']->connection()->getPdo()->query('PRAGMA database_list')->fetchAll(\PDO::FETCH_ASSOC) as $database) {
            if ($database['file'] !== '') {
                throw new \RuntimeException('Persistent test connection rejected.');
            }
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->career = Career::create(['code' => 'DEL', 'name' => 'Carrera delegada', 'status' => 'ACTIVE']);
        $this->inactiveCareer = Career::create(['code' => 'HIST', 'name' => 'Carrera histórica', 'status' => 'INACTIVE']);
        $this->subject = Subject::create(['code' => 'HIST-01', 'name' => 'Materia histórica', 'status' => 'INACTIVE']);
        $this->subject->careers()->attach($this->inactiveCareer->id);
        $this->term = AcademicTerm::create(['name' => 'I/2028', 'start_date' => '2028-01-01',
            'end_date' => '2028-06-30', 'status' => 'INACTIVE']);
    }

    private function operator(array $keys): User
    {
        $role = new Role;
        $role->forceFill(['name' => 'Catálogo '.Role::count(), 'custom_name_key' => 'CATALOGO '.Role::count(),
            'is_custom' => true, 'status' => 'ACTIVE'])->save();
        $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id')->all());
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'ADMINISTRADOR'], ['status' => 'ACTIVE']);
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $user->roles()->attach($role->id, ['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function subjectPayload(array $changes = []): array
    {
        return array_replace(['code' => 'DEL-02', 'name' => 'Materia nueva', 'career_ids' => [$this->career->id]], $changes);
    }

    private function termPayload(array $changes = []): array
    {
        return array_replace(['name' => 'II/2028', 'start_date' => '2028-07-01',
            'end_date' => '2028-12-31', 'status' => 'INACTIVE'], $changes);
    }

    public function test_catalog_contains_only_connected_operations_and_preserves_the_existing_room_keys(): void
    {
        $this->admin();
        $rows = $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonCount(count(RolePermissionCatalog::ENABLED), 'data')->json('data');
        $this->assertEqualsCanonicalizing(array_keys(RolePermissionCatalog::ENABLED), array_column($rows, 'key'));
        foreach ($rows as $row) {
            $this->assertTrue($row['is_enabled']);
            $this->assertTrue($row['is_configurable']);
        }
        $this->assertDatabaseCount('permissions', count(RolePermissionCatalog::ENABLED));
    }

    public function test_subject_view_only_can_read_the_global_catalog_but_not_write_or_load_form_dependencies(): void
    {
        $this->operator(['subjects.view']);
        $this->getJson('/api/v1/admin/subjects')->assertOk()->assertJsonPath('data.0.id', $this->subject->id);
        $this->getJson('/api/v1/admin/subjects/'.$this->subject->id)->assertOk();
        $this->getJson('/api/v1/admin/subjects/careers')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertForbidden();
        $this->putJson('/api/v1/admin/subjects/'.$this->subject->id, $this->subjectPayload())->assertForbidden();
        $this->getJson('/api/v1/admin/subjects/'.$this->subject->id.'/edit')->assertForbidden();
        $this->getJson('/api/v1/admin/subjects/form-careers')->assertForbidden();
        $this->getJson('/api/v1/admin/careers')->assertForbidden();
    }

    public function test_subject_creation_works_without_consultation_or_editing_and_keeps_business_rules(): void
    {
        $this->operator(['subjects.create']);
        $choices = $this->getJson('/api/v1/admin/subjects/form-careers')->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame($this->career->id, $choices[0]['id']);
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->getJson('/api/v1/admin/subjects/careers')->assertForbidden();
        $this->getJson('/api/v1/admin/careers')->assertForbidden();
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertCreated()->assertJsonPath('data.status', 'ACTIVE');
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload(['code' => 'OTRA',
            'career_ids' => [$this->inactiveCareer->id]]))->assertUnprocessable();
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload(['code' => 'STATUS', 'status' => 'INACTIVE']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson('/api/v1/admin/subjects/'.$this->subject->id, $this->subjectPayload())->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBJECT_CREATED']);
        $this->assertDatabaseCount('teachers', 0);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_subject_view_plus_creation_does_not_authorize_editing(): void
    {
        $this->operator(['subjects.view', 'subjects.create']);
        $this->getJson('/api/v1/admin/subjects')->assertOk();
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertCreated();
        $this->putJson('/api/v1/admin/subjects/'.$this->subject->id, $this->subjectPayload())->assertForbidden();
    }

    public function test_subject_update_only_reads_the_target_editor_and_preserves_historical_associations_and_status(): void
    {
        $this->operator(['subjects.update']);
        $target = '/api/v1/admin/subjects/'.$this->subject->id;
        $editor = $this->getJson($target.'/edit')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'careers'], array_keys($editor));
        $this->assertSame($this->inactiveCareer->id, $editor['careers'][0]['id']);
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->getJson($target)->assertForbidden();
        $this->putJson($target, $this->subjectPayload(['career_ids' => [$this->inactiveCareer->id]]))
            ->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBJECT_UPDATED', 'entity_id' => $this->subject->id]);
    }

    public function test_careers_only_exposes_existing_global_consultations(): void
    {
        $this->operator(['careers.view']);
        $this->getJson('/api/v1/admin/careers')->assertOk()->assertJsonCount(2, 'data');
        $data = $this->getJson('/api/v1/admin/careers/'.$this->inactiveCareer->id)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'code', 'name', 'status'], array_keys($data));
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->getJson('/api/v1/admin/academic-terms')->assertForbidden();
    }

    public function test_period_view_only_keeps_list_detail_and_active_queries_without_writes(): void
    {
        $this->operator(['academic_terms.view']);
        $this->getJson('/api/v1/admin/academic-terms')->assertOk();
        $this->getJson('/api/v1/admin/academic-terms/active')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/v1/admin/academic-terms/'.$this->term->id)->assertOk();
        $this->postJson('/api/v1/admin/academic-terms', $this->termPayload())->assertForbidden();
        $this->putJson('/api/v1/admin/academic-terms/'.$this->term->id, $this->termPayload())->assertForbidden();
    }

    public function test_period_creation_without_consultation_or_editing_never_grants_activation(): void
    {
        $this->operator(['academic_terms.create']);
        $this->getJson('/api/v1/admin/academic-terms')->assertForbidden();
        $this->getJson('/api/v1/admin/academic-terms/active')->assertForbidden();
        $created = $this->postJson('/api/v1/admin/academic-terms', $this->termPayload())
            ->assertCreated()->assertJsonPath('data.status', 'INACTIVE')->json('data.id');
        $this->postJson('/api/v1/admin/academic-terms', $this->termPayload(['status' => 'ACTIVE']))->assertForbidden();
        $this->putJson('/api/v1/admin/academic-terms/'.$created, $this->termPayload())->assertForbidden();
        $this->patchJson('/api/v1/admin/academic-terms/'.$created.'/status', ['status' => 'ACTIVE'])->assertForbidden();
        $this->assertDatabaseCount('academic_terms', 2);
    }

    public function test_period_update_only_preserves_status_and_date_integrity_without_catalog_access(): void
    {
        $this->operator(['academic_terms.update']);
        $target = '/api/v1/admin/academic-terms/'.$this->term->id;
        $this->getJson($target.'/edit')->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->getJson($target)->assertForbidden();
        $this->getJson('/api/v1/admin/academic-terms')->assertForbidden();
        $payload = ['name' => 'Periodo corregido', 'start_date' => '2028-01-02', 'end_date' => '2028-06-29'];
        $this->putJson($target, $payload)->assertOk()->assertJsonPath('data.status', 'INACTIVE');
        $this->putJson($target, $payload + ['status' => 'ACTIVE'])->assertUnprocessable();
        $this->putJson($target, array_replace($payload, ['end_date' => '2027-01-01']))->assertUnprocessable();
        $this->assertSame('INACTIVE', $this->term->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_TERM_UPDATED']);
    }

    public function test_offering_consultation_is_global_and_has_its_own_minimal_period_choices(): void
    {
        $fixture = $this->offeringFixture();
        $this->operator(['course_offerings.view']);
        $this->getJson('/api/v1/admin/course-offerings')->assertOk()->assertJsonCount(1, 'data');
        $offering = $this->getJson('/api/v1/admin/course-offerings/'.$fixture['offering']->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('identity_number', $offering['teacher']);
        $this->assertArrayHasKey('email', $offering['teacher']);
        $periods = $this->getJson('/api/v1/admin/course-offerings/periods')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'name', 'status'], array_keys($periods[0]));
        $this->getJson('/api/v1/admin/academic-terms')->assertForbidden();
        $this->getJson('/api/v1/admin/teachers')->assertForbidden();
        $this->postJson('/api/v1/admin/course-offerings/bulk')->assertForbidden();
        $this->putJson('/api/v1/admin/course-offerings/'.$fixture['offering']->id)->assertForbidden();
        $this->patchJson('/api/v1/admin/course-offerings/'.$fixture['offering']->id.'/status')->assertForbidden();
        $this->postJson('/api/v1/admin/course-offerings/import/preview')->assertForbidden();
        $this->postJson('/api/v1/admin/course-offerings/import/confirm')->assertForbidden();
    }

    public function test_delegated_actions_never_open_reserved_endpoints_or_payload_state_changes(): void
    {
        $this->operator(array_values(array_filter(array_keys(RolePermissionCatalog::ENABLED), fn ($key) => ! str_starts_with($key, 'portal.'))));
        foreach (['subjects', 'academic-terms', 'rooms'] as $module) {
            if ($module !== 'academic-terms') {
                $this->postJson('/api/v1/admin/'.$module.'/import/preview')->assertForbidden();
                $this->postJson('/api/v1/admin/'.$module.'/import/confirm')->assertForbidden();
            }
        }
        $this->patchJson('/api/v1/admin/subjects/'.$this->subject->id.'/status', ['status' => 'ACTIVE'])->assertForbidden();
        $this->patchJson('/api/v1/admin/academic-terms/'.$this->term->id.'/status', ['status' => 'ACTIVE'])->assertForbidden();
        foreach (['roles', 'users', 'audit-logs', 'students', 'teachers', 'portal/news', 'portal/services', 'incidents/exams'] as $path) {
            $this->getJson('/api/v1/admin/'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/admin/users/staff')->assertForbidden();
        $this->postJson('/api/v1/admin/roles')->assertForbidden();
    }

    public function test_withdrawal_and_inactive_assignments_take_effect_on_the_next_request(): void
    {
        $user = $this->operator(['subjects.view', 'academic_terms.view', 'careers.view', 'course_offerings.view']);
        $user->load('activeRoles');
        $this->getJson('/api/v1/admin/subjects')->assertOk();
        $role = $user->roles()->firstOrFail();
        $role->permissions()->detach(Permission::where('key', 'subjects.view')->value('id'));
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->getJson('/api/v1/admin/careers')->assertOk();
        $user->roles()->updateExistingPivot($role->id, ['status' => 'INACTIVE']);
        foreach (['careers', 'academic-terms', 'course-offerings'] as $path) {
            $this->getJson('/api/v1/admin/'.$path)->assertForbidden();
        }
    }

    public function test_hu22_reuses_one_assignment_reactivates_it_and_does_not_create_profiles_or_grant_unassigned_actions(): void
    {
        $target = $this->operator(['subjects.view', 'rooms.view']);
        $role = $target->roles()->firstOrFail();
        $admin = $this->admin();
        $target->createToken('hu22-regression');
        $this->deleteJson('/api/v1/admin/users/'.$target->id.'/roles/'.$role->id)->assertOk();
        $this->assertSame(0, $target->tokens()->count());
        Sanctum::actingAs($target->refresh());
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->getJson('/api/v1/admin/rooms')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/admin/users/'.$target->id.'/roles', ['role_id' => $role->id])->assertOk();
        $this->assertSame(1, DB::table('role_user')->where('user_id', $target->id)->where('role_id', $role->id)->count());
        Sanctum::actingAs($target->refresh());
        $this->getJson('/api/v1/admin/subjects')->assertOk();
        $this->getJson('/api/v1/admin/rooms')->assertOk();
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertForbidden();
        $this->assertDatabaseCount('teachers', 0);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_room_operations_still_use_independent_permissions_and_reserve_state_changes(): void
    {
        $room = Room::create(['code' => 'REG-03', 'name' => 'Regresión Aulas', 'status' => 'ACTIVE']);
        $this->operator(['rooms.view', 'subjects.create']);
        $this->getJson('/api/v1/admin/rooms/'.$room->id)->assertOk();
        $this->postJson('/api/v1/admin/rooms', ['code' => 'REG-04', 'name' => 'Otra aula'])->assertForbidden();
        $this->patchJson('/api/v1/admin/rooms/'.$room->id.'/status', ['status' => 'INACTIVE'])->assertForbidden();
        $this->getJson('/api/v1/rooms')->assertOk();
    }

    public function test_admin_preserves_existing_creations_edits_activation_and_import_access_without_explicit_permissions(): void
    {
        $this->admin();
        $this->postJson('/api/v1/admin/subjects', $this->subjectPayload())->assertCreated();
        $this->putJson('/api/v1/admin/subjects/'.$this->subject->id, $this->subjectPayload(['code' => 'EDIT-03']))->assertOk();
        $this->patchJson('/api/v1/admin/subjects/'.$this->subject->id.'/status', ['status' => 'ACTIVE'])->assertOk();
        $this->postJson('/api/v1/admin/subjects/import/preview')->assertUnprocessable();
        $this->postJson('/api/v1/admin/academic-terms', $this->termPayload(['status' => 'ACTIVE']))->assertCreated();
        $this->patchJson('/api/v1/admin/academic-terms/'.$this->term->id.'/status', ['status' => 'INACTIVE'])->assertOk();
    }

    public function test_write_services_recheck_withdrawn_permissions_and_preserve_http_denials(): void
    {
        $user = $this->operator(['subjects.create', 'academic_terms.create']);
        $user->roles()->firstOrFail()->permissions()->detach();
        $request = Request::create('/api/v1/admin/subjects', 'POST');
        $request->setUserResolver(fn () => $user);
        foreach ([fn () => app(SubjectService::class)->save($this->subjectPayload(), $request),
            fn () => app(AcademicTermService::class)->save($this->termPayload(), $request)] as $write) {
            try {
                $write();
                $this->fail('A withdrawn permission must never authorize a write.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('academic_terms', 1);
    }

    public function test_management_api_configures_new_permissions_and_removal_revokes_existing_sessions_and_tokens(): void
    {
        $admin = $this->admin();
        $keys = ['subjects.view', 'academic_terms.create'];
        $roleId = $this->postJson('/api/v1/admin/roles', ['name' => 'Consulta académica', 'permission_keys' => $keys])
            ->assertCreated()->json('data.id');
        $user = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
        $this->postJson('/api/v1/admin/users/'.$user->id.'/roles', ['role_id' => $roleId])->assertOk();
        // This assertion targets /me web; Sanctum::actingAs supplies a mocked personal token.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user->refresh()->withAccessToken(null), 'web');
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.permissions', ['academic_terms.create', 'subjects.view']);
        $this->getJson('/api/v1/admin/subjects')->assertOk();
        $user->createToken('live-catalog-session');
        $marker = $user->active_session_id;
        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/roles/'.$roleId, ['name' => 'Consulta académica', 'permission_keys' => [], 'revision' => 1])->assertOk();
        $this->assertNotSame($marker, $user->refresh()->active_session_id);
        $this->assertSame(0, $user->tokens()->count());
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/subjects')->assertForbidden();
        $this->postJson('/api/v1/admin/academic-terms', $this->termPayload())->assertForbidden();
    }

    public function test_account_and_first_access_guards_remain_and_base_roles_do_not_gain_new_permissions(): void
    {
        $user = $this->operator(['subjects.view']);
        $user->forceFill(['must_change_password' => true])->save();
        $this->getJson('/api/v1/admin/subjects')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $user->forceFill(['must_change_password' => false, 'status' => 'INACTIVE'])->save();
        $this->getJson('/api/v1/admin/subjects')->assertUnauthorized();
        foreach (['DOCENTE', 'ESTUDIANTE'] as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['status' => 'ACTIVE']);
            $actor = User::factory()->create(['status' => 'ACTIVE', 'must_change_password' => false]);
            $actor->roles()->attach($role->id, ['status' => 'ACTIVE']);
            Sanctum::actingAs($actor);
            foreach (['subjects', 'careers', 'academic-terms', 'course-offerings'] as $module) {
                $this->getJson('/api/v1/admin/'.$module)->assertForbidden();
            }
        }
    }
}
