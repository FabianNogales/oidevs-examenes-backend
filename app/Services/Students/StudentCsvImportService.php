<?php

namespace App\Services\Students;

use App\Enums\RoleName;
use App\Models\User;
use App\Models\Student;
use App\Models\Role;
use App\Models\Career;
use App\Services\Auth\InitialPasswordService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class StudentCsvImportService
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const REQUIRED_COLUMNS = [
        'sis_code',
        'identity_number',
        'first_names',
        'last_names',
        'email',
        'career',
        'profile_photo',
    ];

    private InitialPasswordService $initialPasswordService;

    public function __construct(
    InitialPasswordService $initialPasswordService){
        $this->initialPasswordService = $initialPasswordService;
    }

    public function validate(UploadedFile $file): array
    {
        $errors = [];

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            $errors[] = 'El archivo supera el tamaño máximo permitido de 10 MB.';
        }

        if ($file->getClientOriginalExtension() !== 'csv') {
            $errors[] = 'El archivo debe tener extensión CSV.';
        }

        if (! $file->isValid()) {
            $errors[] = 'El archivo no es válido.';
        }

        if (! empty($errors)) {
            return [
                'valid' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_rows' => 0,
                'errors' => $errors,
                'rows' => [],
            ];
        }

        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return [
                'valid' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_rows' => 0,
                'errors' => ['No se pudo abrir el archivo.'],
                'rows' => [],
            ];
        }

        $headers = fgetcsv($handle);

        if ($headers === false) {
            fclose($handle);

            return [
                'valid' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_rows' => 0,
                'errors' => ['El archivo CSV está vacío.'],
                'rows' => [],
            ];
        }

        $headers = array_map(
            fn ($header) => trim($header),
            $headers
        );

        if ($headers !== self::REQUIRED_COLUMNS) {
            fclose($handle);

            return [
                'valid' => false,
                'total_rows' => 0,
                'valid_rows' => 0,
                'error_rows' => 0,
                'errors' => [
                    'Las columnas del CSV no coinciden con las columnas requeridas.'
                ],
                'rows' => [],
            ];
        }

        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && trim($data[0]) === '') {
                continue;
            }

            $data = array_combine(
    self::REQUIRED_COLUMNS,
    $data
);

$rowErrors = $this->validateStudentData($data);

$rows[] = [
    'row' => count($rows) + 2,
    'data' => $data,
    'valid' => empty($rowErrors),
    'errors' => $rowErrors,
];
        }
        $duplicateErrors = $this->validateDuplicates($rows);

foreach ($duplicateErrors as $index => $errors) {
    $rows[$index]['valid'] = false;

    foreach ($errors as $error) {
        $rows[$index]['errors'][] = $error;
    }
}
$existingErrors = $this->validateExistingStudents($rows);

foreach ($existingErrors as $index => $errors) {
    $rows[$index]['valid'] = false;

    foreach ($errors as $error) {
        $rows[$index]['errors'][] = $error;
    }
}
        fclose($handle);

        $errorRows = 0;

foreach ($rows as $row) {
    if (! $row['valid']) {
        $errorRows++;
    }
}

return [
    'valid' => $errorRows === 0,
    'total_rows' => count($rows),
    'valid_rows' => count($rows) - $errorRows,
    'error_rows' => $errorRows,
    'errors' => [],
    'rows' => $rows,
];
    }
    private function validateStudentData(array $data): array
{
    $errors = [];

    if ($data['sis_code'] === '') {
        $errors[] = 'El código SIS es obligatorio.';
    }

    if ($data['identity_number'] === '') {
        $errors[] = 'El CI es obligatorio.';
    }

    if ($data['first_names'] === '') {
        $errors[] = 'Los nombres son obligatorios.';
    }

    if ($data['last_names'] === '') {
        $errors[] = 'Los apellidos son obligatorios.';
    }

    if ($data['email'] === '') {
        $errors[] = 'El email es obligatorio.';
    } elseif (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no tiene un formato válido.';
    } elseif (! str_ends_with(
        strtolower($data['email']),
        '@umss.edu.bo'
    )) {
        $errors[] = 'El email debe pertenecer al dominio institucional @umss.edu.bo.';
    }

    if ($data['career'] === '') {
        $errors[] = 'La carrera es obligatoria.';
    } elseif (! Career::where('name', $data['career'])->exists()) {
        $errors[] = 'La carrera indicada no existe.';
    }

    if ($data['profile_photo'] === '') {
        $errors[] = 'La foto de perfil es obligatoria.';
    }

    return $errors;
}
private function validateDuplicates(array $rows): array
{
    $errors = [];

    $sisCodes = [];
    $identityNumbers = [];
    $emails = [];

    foreach ($rows as $index => $row) {
        if (! $row['valid']) {
            continue;
        }

        $data = $row['data'];

        if (isset($sisCodes[$data['sis_code']])) {
            $errors[$index][] = 'El código SIS está duplicado dentro del archivo.';
        } else {
            $sisCodes[$data['sis_code']] = true;
        }

        if (isset($identityNumbers[$data['identity_number']])) {
            $errors[$index][] = 'El CI está duplicado dentro del archivo.';
        } else {
            $identityNumbers[$data['identity_number']] = true;
        }

        $email = strtolower($data['email']);

        if (isset($emails[$email])) {
            $errors[$index][] = 'El email está duplicado dentro del archivo.';
        } else {
            $emails[$email] = true;
        }
    }

    return $errors;
}
private function validateExistingStudents(array $rows): array
{
    $errors = [];

    foreach ($rows as $index => $row) {
        if (! $row['valid']) {
            continue;
        }

        $data = $row['data'];

        if (Student::where('sis_code', $data['sis_code'])->exists()) {
            $errors[$index][] = 'El código SIS ya existe en la base de datos.';
        }

        if (Student::where('identity_number', $data['identity_number'])->exists()) {
            $errors[$index][] = 'El CI ya existe en la base de datos.';
        }
        if (User::where('email', $data['email'])->exists()) {
            $errors[$index][] = 'El email ya existe en la base de datos.';
        }
    }

    return $errors;
}
public function import(UploadedFile $file): array
{
    $validation = $this->validate($file);

    $imported = 0;
    $failed = 0;

    foreach ($validation['rows'] as $row) {
        if (! $row['valid']) {
            continue;
        }

        try {
            DB::transaction(function () use ($row) {
                $data = $row['data'];

                $career = Career::where(
                    'name',
                    $data['career']
                )->firstOrFail();

                $studentRole = Role::where(
                    'name',
                    RoleName::ESTUDIANTE->value
                )->firstOrFail();

                $user = User::forceCreate([
                    'email' => $data['email'],
                    'password' => 'temporary',
                    'status' => 'ACTIVE',
                    'profile_photo' => $data['profile_photo'],
                ]);

                $this->initialPasswordService->initialize(
                    $user,
                    $data['identity_number']
                );

                $user->roles()->attach(
                    $studentRole->id,
                    [
                        'assigned_at' => now(),
                        'status' => 'ACTIVE',
                    ]
                );

                Student::create([
                    'user_id' => $user->id,
                    'sis_code' => $data['sis_code'],
                    'identity_number' => $data['identity_number'],
                    'first_names' => $data['first_names'],
                    'last_names' => $data['last_names'],
                    'career_id' => $career->id,
                    'status' => 'ACTIVE',
                ]);
            });

            $imported++;
        } catch (\Throwable $exception) {
            $failed++;
        }
    }

    $validation['imported_rows'] = $imported;
    $validation['failed_rows'] = $failed;

    return $validation;
}
}