<?php

namespace App\Services\Students;

use App\Models\User;
use App\Models\Student;
use App\Models\Career;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function __construct(private readonly StudentRegistrationService $registration) {}

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

        $headerStart = ftell($handle);
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
        $rowNumber = 1 + $this->consumedLineCount($handle, $headerStart);

        while (true) {
            $recordStart = ftell($handle);
            $values = fgetcsv($handle);

            if ($values === false) {
                break;
            }

            $currentRow = $rowNumber;
            $rowNumber += $this->consumedLineCount($handle, $recordStart);
            $values = array_map(fn ($value) => trim((string) $value), $values);
            $rowErrors = [];

            if (count(array_filter($values, fn (string $value) => $value !== '')) === 0) {
                $rowErrors[] = 'La fila está vacía.';
            } elseif (count($values) !== count(self::REQUIRED_COLUMNS)) {
                $rowErrors[] = 'La fila no contiene la cantidad de columnas esperada.';
            }

            // Mantener las claves del contrato incluso en registros incompletos.
            $data = array_combine(
                self::REQUIRED_COLUMNS,
                array_slice(array_pad($values, count(self::REQUIRED_COLUMNS), ''), 0, count(self::REQUIRED_COLUMNS))
            );
            $data['email'] = strtolower($data['email']);

            if ($rowErrors === []) {
                $rowErrors = $this->validateStudentData($data);
            }

            $rows[] = [
                'row' => $currentRow,
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
    /** Contar líneas físicas, incluyendo saltos dentro de campos CSV entre comillas. */
    private function consumedLineCount($handle, int $start): int
    {
        $end = ftell($handle);
        fseek($handle, $start);
        $record = fread($handle, $end - $start);

        return max(1, preg_match_all('/\r\n|\r|\n/', $record));
    }

    private function validateStudentData(array $data): array
{
    $errors = [];

    if ($data['sis_code'] === '') {
        $errors[] = 'El código SIS es obligatorio.';
    }

    if ($data['identity_number'] === '') {
        $errors[] = 'El CI es obligatorio.';
    } elseif (! preg_match('/\A[0-9]+\z/', $data['identity_number'])) {
        $errors[] = 'El CI debe contener únicamente números.';
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

    // Las columnas string correspondientes usan el límite predeterminado de 255.
    $fieldLabels = [
        'sis_code' => 'El código SIS',
        'identity_number' => 'El CI',
        'first_names' => 'Los nombres',
        'last_names' => 'Los apellidos',
        'email' => 'El correo',
        'career' => 'La carrera',
        'profile_photo' => 'La referencia de la foto de perfil',
    ];

    foreach ($fieldLabels as $field => $label) {
        if (mb_strlen($data[$field], 'UTF-8') > 255) {
            $errors[] = $label.' no debe superar los 255 caracteres.';
        }
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
        if (User::whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
            $errors[$index][] = 'El email ya existe en la base de datos.';
        }
    }

    return $errors;
}
    public function import(UploadedFile $file, ?User $actor = null, ?Request $request = null): array
    {
        $validation = $this->validate($file);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $results = [];

        foreach ($validation['rows'] as $row) {
            $result = [
                'row' => $row['row'],
                'sis_code' => $row['data']['sis_code'],
                'status' => 'SKIPPED',
                'errors' => $row['errors'],
                'student_id' => null,
            ];

            if (! $row['valid']) {
                $skipped++;
                $results[] = $result;
                continue;
            }

            try {
                $career = Career::where('name', $row['data']['career'])->firstOrFail();
                $student = $this->registration->create($row['data'], $career, $actor, $request, 'IMPORT');

                $result['status'] = 'IMPORTED';
                $result['student_id'] = $student->id;
                $imported++;
            } catch (\Throwable $exception) {
                $failed++;
                $result['status'] = 'FAILED';
                $result['errors'] = [
                    $exception instanceof \DomainException
                        ? 'El rol ESTUDIANTE activo no está disponible. No se creó el estudiante.'
                        : 'No se pudo registrar el estudiante. La creación de esta fila fue revertida.',
                ];

                // No incluir SQL, credenciales ni datos personales en el diagnóstico.
                Log::error('Falló la creación de una fila de importación de estudiantes.', [
                    'row' => $row['row'],
                    'exception_type' => $exception::class,
                ]);
            }

            $results[] = $result;
        }

        $validation['imported_rows'] = $imported;
        $validation['skipped_rows'] = $skipped;
        $validation['failed_rows'] = $failed;
        $validation['results'] = $results;

        return $validation;
    }
}
