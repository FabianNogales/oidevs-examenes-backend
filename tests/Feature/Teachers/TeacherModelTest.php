<?php

namespace Tests\Feature\Teachers;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_belongs_to_user(): void
    {
        $teacher = Teacher::factory()->create();

        $this->assertInstanceOf(User::class, $teacher->user);
        $this->assertTrue($teacher->user->is($teacher->user()->first()));
    }

    public function test_user_has_one_teacher(): void
    {
        $teacher = Teacher::factory()->create();

        $this->assertInstanceOf(Teacher::class, $teacher->user->teacher);
        $this->assertTrue($teacher->is($teacher->user->teacher));
    }
}
