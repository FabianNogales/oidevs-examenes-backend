<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Models\Career;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Services\Students\StudentCsvImportService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StudentCsvImportTest extends TestCase
{
    use RefreshDatabase;
    public function test_csv_contains_required_columns(): void
    {
        $csv = implode("\n", [
            'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
            '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
        ]);

        $file = UploadedFile::fake()->createWithContent(
            'students.csv',
            $csv
        );

        $service = app(StudentCsvImportService::class);

        $result = $service->validate($file);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('total_rows', $result);
        $this->assertArrayHasKey('valid_rows', $result);
        $this->assertArrayHasKey('error_rows', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('rows', $result);
    }
    public function test_detects_empty_required_student_fields(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        ',1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);
    $this->assertEquals(2, $result['rows'][0]['row']);

    $this->assertContains(
        'El código SIS es obligatorio.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_empty_names_and_last_names(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,,,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'Los nombres son obligatorios.',
        $result['rows'][0]['errors']
    );

    $this->assertContains(
        'Los apellidos son obligatorios.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_invalid_email(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,email-invalido,Sistemas,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);

    $this->assertContains(
        'El email no tiene un formato válido.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_non_institutional_email(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan@gmail.com,Sistemas,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);

    $this->assertContains(
        'El email debe pertenecer al dominio institucional @umss.edu.bo.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_non_existing_career(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,CarreraInexistente,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);

    $this->assertContains(
        'La carrera indicada no existe.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_empty_profile_photo(): void
{
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);

    $this->assertContains(
        'La foto de perfil es obligatoria.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_duplicate_sis_in_csv(): void
{
    Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,juan.jpg',
        '20260001,7654321,Maria,Lopez,maria.lopez@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El código SIS está duplicado dentro del archivo.',
        $result['rows'][1]['errors']
    );
}
public function test_detects_duplicate_identity_number_in_csv(): void
{
    Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,juan.jpg',
        '20260002,1234567,Maria,Lopez,maria.lopez@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El CI está duplicado dentro del archivo.',
        $result['rows'][1]['errors']
    );
}
public function test_detects_duplicate_email_in_csv(): void
{
    Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,juan.jpg',
        '20260002,7654321,Maria,Lopez,juan.perez@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El email está duplicado dentro del archivo.',
        $result['rows'][1]['errors']
    );
}
public function test_detects_existing_sis_in_database(): void
{
    Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $user = User::forceCreate([
    'email' => 'existente@umss.edu.bo',
    'password' => 'Password1',
    'status' => 'ACTIVE',
]);

    Student::create([
        'user_id' => $user->id,
        'sis_code' => '20260001',
        'identity_number' => '1234567',
        'first_names' => 'Juan',
        'last_names' => 'Perez',
        'career_id' => Career::where('name', 'Sistemas')->first()->id,
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,7654321,Maria,Lopez,maria.lopez@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El código SIS ya existe en la base de datos.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_existing_identity_number_in_database(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    $user = User::forceCreate([
        'email' => 'existente@umss.edu.bo',
        'password' => 'Password1',
        'status' => 'ACTIVE',
    ]);

    Student::create([
        'user_id' => $user->id,
        'sis_code' => '20260001',
        'identity_number' => '1234567',
        'first_names' => 'Juan',
        'last_names' => 'Perez',
        'career_id' => $career->id,
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20269999,1234567,Maria,Lopez,maria.lopez@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El CI ya existe en la base de datos.',
        $result['rows'][0]['errors']
    );
}
public function test_detects_existing_email_in_database(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    User::forceCreate([
        'email' => 'existente@umss.edu.bo',
        'password' => 'Password1',
        'status' => 'ACTIVE',
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20269999,7654321,Maria,Lopez,existente@umss.edu.bo,Sistemas,maria.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->validate($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(1, $result['error_rows']);

    $this->assertContains(
        'El email ya existe en la base de datos.',
        $result['rows'][0]['errors']
    );
}
public function test_imports_valid_student(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    Role::create([
    'name' => RoleName::ESTUDIANTE->value,
    'description' => 'Estudiante',
    'status' => UserStatus::ACTIVE->value,
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->import($file);

    $this->assertTrue($result['valid']);
    $this->assertEquals(1, $result['imported_rows']);

    $this->assertDatabaseHas('users', [
        'email' => 'juan.perez@umss.edu.bo',
        'status' => 'ACTIVE',
        'profile_photo' => '20260001.jpg',
    ]);

    $this->assertDatabaseHas('students', [
        'sis_code' => '20260001',
        'identity_number' => '1234567',
        'first_names' => 'Juan',
        'last_names' => 'Perez',
        'career_id' => $career->id,
        'status' => 'ACTIVE',
    ]);
}
public function test_imports_multiple_valid_students(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    Role::create([
    'name' => RoleName::ESTUDIANTE->value,
    'description' => 'Estudiante',
    'status' => UserStatus::ACTIVE->value,
    ]);

    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
        '20260002,7654321,Maria,Lopez,maria.lopez@umss.edu.bo,Sistemas,20260002.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->import($file);

    $this->assertTrue($result['valid']);
    $this->assertEquals(2, $result['imported_rows']);

    $this->assertDatabaseHas('users', [
        'email' => 'juan.perez@umss.edu.bo',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'maria.lopez@umss.edu.bo',
    ]);

    $this->assertDatabaseHas('students', [
        'sis_code' => '20260001',
        'identity_number' => '1234567',
    ]);

    $this->assertDatabaseHas('students', [
        'sis_code' => '20260002',
        'identity_number' => '7654321',
    ]);
}
public function test_imports_valid_rows_and_skips_invalid_rows(): void
{
    $career = Career::create([
        'code' => 'SIS',
        'name' => 'Sistemas',
        'status' => 'ACTIVE',
    ]);

    Role::create([
    'name' => RoleName::ESTUDIANTE->value,
    'description' => 'Estudiante',
    'status' => UserStatus::ACTIVE->value,
    ]);
    
    $csv = implode("\n", [
        'sis_code,identity_number,first_names,last_names,email,career,profile_photo',
        '20260001,1234567,Juan,Perez,juan.perez@umss.edu.bo,Sistemas,20260001.jpg',
        '20260002,7654321,Maria,Lopez,email-invalido,Sistemas,20260002.jpg',
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'students.csv',
        $csv
    );

    $service = app(StudentCsvImportService::class);

    $result = $service->import($file);

    $this->assertFalse($result['valid']);
    $this->assertEquals(2, $result['total_rows']);
    $this->assertEquals(1, $result['valid_rows']);
    $this->assertEquals(1, $result['error_rows']);
    $this->assertEquals(1, $result['imported_rows']);

    $this->assertDatabaseHas('users', [
        'email' => 'juan.perez@umss.edu.bo',
    ]);

    $this->assertDatabaseMissing('users', [
        'email' => 'email-invalido',
    ]);

    $this->assertDatabaseHas('students', [
        'sis_code' => '20260001',
    ]);

    $this->assertDatabaseMissing('students', [
        'sis_code' => '20260002',
    ]);
}
}   