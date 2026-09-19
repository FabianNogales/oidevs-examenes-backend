<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\Career;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCareerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_one_student(): void
    {
        $user = User::factory()->create();

        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'sis_code' => '20260001',
            'identity_number' => '1234567',
            'first_names' => 'Juan',
            'last_names' => 'Perez',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);

        $this->assertTrue($user->student->is($student));
    }

    public function test_student_belongs_to_user(): void
    {
        $user = User::factory()->create();

        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'sis_code' => '20260002',
            'identity_number' => '7654321',
            'first_names' => 'Maria',
            'last_names' => 'Lopez',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);

        $this->assertTrue($student->user->is($user));
    }

    public function test_student_belongs_to_career(): void
    {
        $user = User::factory()->create();

        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'sis_code' => '20260003',
            'identity_number' => '1111111',
            'first_names' => 'Carlos',
            'last_names' => 'Gomez',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);

        $this->assertTrue($student->career->is($career));
    }

    public function test_career_has_many_students(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $career = Career::create([
            'code' => 'SIS',
            'name' => 'Sistemas',
            'status' => 'ACTIVE',
        ]);

        Student::create([
            'user_id' => $user1->id,
            'sis_code' => '20260004',
            'identity_number' => '2222222',
            'first_names' => 'Ana',
            'last_names' => 'Rojas',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);

        Student::create([
            'user_id' => $user2->id,
            'sis_code' => '20260005',
            'identity_number' => '3333333',
            'first_names' => 'Pedro',
            'last_names' => 'Mamani',
            'career_id' => $career->id,
            'status' => 'ACTIVE',
        ]);

        $this->assertCount(2, $career->students);
    }

    public function test_estudiante_role_exists(): void
    {
        $role = Role::create([
            'name' => RoleName::ESTUDIANTE->value,
            'description' => 'Estudiante',
            'status' => 'ACTIVE',
        ]);

        $this->assertEquals(
            RoleName::ESTUDIANTE->value,
            $role->name
        );
    }
}