<?php

namespace App\Services\CourseOfferings;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\CourseOffering;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CourseOfferingCsvParser
{
    private const HEADERS = ['correo_docente', 'codigo_materia'];

    public function report(UploadedFile $file, int $academicTermId): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'El archivo debe usar UTF-8 válido.']);
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $content);
            rewind($stream);
            if (fgetcsv($stream, 0, ',', '"', '') !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'El encabezado debe ser correo_docente,codigo_materia, en ese orden. El periodo se selecciona fuera del CSV.']);
            }
            $rows = [];
            $number = 2;
            while (($start = ftell($stream)) !== false && ($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $raw = substr($content, $start, ftell($stream) - $start);
                $data = ['correo_docente' => (string) ($cells[0] ?? ''), 'codigo_materia' => (string) ($cells[1] ?? '')];
                $values = ['email' => trim($data['correo_docente']), 'code' => strtoupper(trim($data['codigo_materia']))];
                $errors = Validator::make($values, ['email' => ['bail', 'required', 'string', 'max:255', 'email:rfc'],
                    'code' => ['bail', 'required', 'string', 'max:50', 'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/']],
                    ['email.*' => 'El correo del docente debe ser válido.', 'code.*' => 'El código de materia es obligatorio y debe tener el formato del catálogo.'])->errors()->all();
                if (count($cells) !== 2) {
                    $errors[] = 'La fila debe contener exactamente dos columnas.';
                }
                if ($this->hasInvalidQuotes($raw)) {
                    $errors[] = 'La fila contiene comillas CSV sin cerrar o mal ubicadas.';
                }
                // HU19 numbers logical CSV records, even when a quoted field spans lines.
                $rows[] = ['row_number' => $number++, 'data' => $data, 'status' => 'VALID', 'errors' => $errors];
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'El archivo CSV no contiene filas de datos.']);
        }
        $emails = array_values(array_unique(array_map(fn ($row) => mb_strtolower(trim($row['data']['correo_docente'])), $rows)));
        $codes = array_values(array_unique(array_map(fn ($row) => strtoupper(trim($row['data']['codigo_materia'])), $rows)));
        $teachers = collect();
        foreach (array_chunk($emails, 500) as $chunk) {
            $teachers = $teachers->concat(Teacher::with(['user.roles'])->whereHas('user', fn ($user) => $user->whereIn(DB::raw('LOWER(email)'), $chunk))->get());
        }
        $existing = [];
        foreach (array_chunk($teachers->pluck('id')->all(), 500) as $chunk) {
            foreach (CourseOffering::where('academic_term_id', $academicTermId)->whereIn('teacher_id', $chunk)->get(['teacher_id', 'subject_id']) as $offering) {
                $existing[$offering->teacher_id.':'.$offering->subject_id] = true;
            }
        }
        $teachers = $teachers->groupBy(fn ($teacher) => mb_strtolower($teacher->user->email));
        $subjects = collect();
        foreach (array_chunk($codes, 500) as $chunk) {
            $subjects = $subjects->concat(Subject::whereIn(DB::raw('UPPER(code)'), $chunk)->get());
        }
        $subjects = $subjects->groupBy(fn ($subject) => strtoupper($subject->code));
        $seen = [];
        foreach ($rows as &$row) {
            $email = mb_strtolower(trim($row['data']['correo_docente']));
            $code = strtoupper(trim($row['data']['codigo_materia']));
            $teacherMatches = $teachers->get($email);
            $subjectMatches = $subjects->get($code);
            $teacher = $teacherMatches?->first();
            $subject = $subjectMatches?->first();
            if (! $teacher) {
                $row['errors'][] = 'El docente no existe.';
            } elseif ($teacherMatches->count() !== 1) {
                $row['errors'][] = 'El correo coincide con varios docentes; requiere revisión.';
            } else {
                $hasRole = $teacher->user->roles->contains(fn ($role) => $role->name === RoleName::DOCENTE->value && $role->status === 'ACTIVE' && $role->pivot->status === 'ACTIVE');
                if ($teacher->status !== UserStatus::ACTIVE || ! $teacher->user->isActive() || ! $hasRole) {
                    $row['errors'][] = 'El docente, su cuenta y su rol deben estar habilitados.';
                }
            }
            if (! $subject) {
                $row['errors'][] = 'La materia no existe.';
            } elseif ($subjectMatches->count() !== 1) {
                $row['errors'][] = 'El código coincide con varias materias; requiere revisión.';
            } elseif ($subject->status !== 'ACTIVE') {
                $row['errors'][] = 'La materia está inactiva.';
            }
            $pair = $teacher && $subject ? $teacher->id.':'.$subject->id : null;
            if ($row['errors'] !== []) {
                $row['status'] = 'ERROR';
            } elseif (isset($seen[$pair])) {
                $row['status'] = 'OMITTED';
                $row['errors'][] = 'Asignación repetida en el archivo.';
            } elseif (isset($existing[$pair])) {
                $row['status'] = 'OMITTED';
                $row['errors'][] = 'La asignación ya existe en este periodo, activa o inactiva.';
            }
            if ($row['status'] !== 'ERROR') {
                $seen[$pair] = true;
            }
        }
        unset($row);
        $counts = array_count_values(array_column($rows, 'status'));

        return ['summary' => ['total' => count($rows), 'valid' => $counts['VALID'] ?? 0,
            'invalid' => $counts['ERROR'] ?? 0, 'omitted' => $counts['OMITTED'] ?? 0], 'rows' => $rows];
    }

    private function hasInvalidQuotes(string $record): bool
    {
        $state = 'start';
        for ($i = 0, $length = strlen($record); $i < $length; $i++) {
            $char = $record[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($record[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
            } elseif ($char === ',') {
                $state = 'start';
            } elseif ($char === "\r" || $char === "\n") {
                continue;
            } elseif ($state === 'start' && $char === '"') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                return true;
            } else {
                $state = 'plain';
            }
        }

        return $state === 'quoted';
    }
}
