<?php

namespace App\Services\Entries;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EntryControlService
{
    private function query(Exam $exam): Builder
    {
        return DB::table('students as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('enrollments as en', function ($join) use ($exam) {
                $join->on('en.student_id', '=', 's.id')->where('en.course_offering_id', $exam->course_offering_id);
            })
            ->leftJoin('exam_eligibilities as el', function ($join) use ($exam) {
                $join->on('el.student_id', '=', 's.id')->where('el.exam_id', $exam->id);
            })
            ->leftJoin('exam_entries as ent', function ($join) use ($exam) {
                $join->on('ent.student_id', '=', 's.id')->where('ent.exam_id', $exam->id)->where('ent.status', 'VALID');
            });
    }

    private function associatedQuery(Exam $exam): Builder
    {
        return $this->query($exam)->where(function ($query) {
            $query->whereNotNull('en.id')->orWhereNotNull('el.id');
        });
    }

    private function columns(): array
    {
        return [
            's.id as student_id', 's.first_names', 's.last_names', 's.sis_code', 's.identity_number',
            's.status as student_status', 'u.profile_photo',
            'en.status as enrollment_status', 'el.status as eligibility_status',
            'el.reason_code', 'el.reason', 'el.observations', 'ent.id as entry_id',
            'ent.entered_at', 'ent.verification_method', 'ent.verified_by',
        ];
    }

    public function examData(Exam $exam): array
    {
        $exam->loadMissing(['courseOffering.subject', 'room']);

        return [
            'id' => $exam->id,
            'name' => $exam->name,
            'subject_name' => $exam->courseOffering?->subject?->name,
            'exam_date' => $exam->exam_date,
            'start_time' => $exam->start_time,
            'duration_minutes' => (int) $exam->duration_minutes,
            'evaluation_type' => $exam->evaluation_type,
            'status' => $exam->status,
            'entry_available' => ExamEntryAvailability::reason($exam) === null,
            'entry_unavailable_reason' => ExamEntryAvailability::reason($exam),
            'room' => $exam->room ? $exam->room->only(['id', 'code', 'name', 'location']) : null,
        ];
    }

    public function index(Exam $exam, array $parameters): array
    {
        $base = $this->associatedQuery($exam);
        $counts = (clone $base)->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN ent.id IS NOT NULL THEN 1 ELSE 0 END) AS registered,
             SUM(CASE WHEN ent.id IS NULL AND s.status = ? AND en.status = ? AND el.status = ? THEN 1 ELSE 0 END) AS pending',
            ['ACTIVE', 'ACTIVE', 'ELIGIBLE']
        )->first();
        $total = (int) $counts->total;
        $registered = (int) $counts->registered;
        $pending = (int) $counts->pending;
        $search = trim((string) preg_replace('/\s+/u', ' ', $parameters['search'] ?? ''));
        if ($search !== '') {
            $words = explode(' ', mb_strtolower($search));
            $term = '%'.mb_strtolower($search).'%';
            $base->where(function ($query) use ($term, $words) {
                foreach (['first_names', 'last_names', 'sis_code', 'identity_number'] as $column) {
                    $query->orWhereRaw("LOWER(s.{$column}) LIKE ?", [$term]);
                }
                $query->orWhere(function ($names) use ($words) {
                    foreach ($words as $word) {
                        $names->where(function ($query) use ($word) {
                            $query->whereRaw('LOWER(s.first_names) LIKE ?', ['%'.$word.'%'])
                                ->orWhereRaw('LOWER(s.last_names) LIKE ?', ['%'.$word.'%']);
                        });
                    }
                });
            });
        }
        $page = $base->select($this->columns())->orderBy('s.id')
            ->paginate((int) ($parameters['per_page'] ?? 10), ['*'], 'page', (int) ($parameters['page'] ?? 1));
        $records = $page->getCollection();
        $verifiers = $this->verifiers($records);
        $roomId = (int) ($parameters['room_id'] ?? $exam->room_id);

        return [
            'exam' => $this->examData($exam),
            'summary' => ['total_students' => $total, 'registered' => $registered, 'pending' => $pending,
                'not_applicable' => $total - $registered - $pending],
            'data' => $records->map(fn ($record) => $this->studentData($record, $exam, $roomId, $verifiers))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    public function show(Exam $exam, int $studentId, array $parameters): array
    {
        $record = $this->query($exam)->where('s.id', $studentId)->select($this->columns())->first();
        abort_unless($record, 404, 'Estudiante no encontrado.');
        $verifiers = $this->verifiers(collect([$record]));

        return [
            'exam' => $this->examData($exam),
            'data' => $this->studentData($record, $exam, (int) ($parameters['room_id'] ?? $exam->room_id), $verifiers),
        ];
    }

    private function verifiers(Collection $records): Collection
    {
        return User::with(['teacher', 'student'])->whereIn('id', $records->pluck('verified_by')->filter()->unique())
            ->get()->keyBy('id');
    }

    private function studentData(object $record, Exam $exam, int $roomId, Collection $verifiers): array
    {
        $enrollment = in_array($record->enrollment_status, ['ACTIVE', 'INACTIVE'], true) ? $record->enrollment_status : null;
        $eligibility = in_array($record->eligibility_status, ['ELIGIBLE', 'INELIGIBLE'], true) ? $record->eligibility_status : null;
        $associated = $record->enrollment_status !== null || $record->eligibility_status !== null;
        $roomStatus = ! $associated ? 'NOT_APPLICABLE' : ((int) $exam->room_id === $roomId ? 'MATCH' : 'MISMATCH');
        $blockers = [];
        $messages = [
            'STUDENT_INACTIVE' => 'El estudiante se encuentra inactivo.',
            'NOT_ENROLLED' => 'El estudiante no tiene una inscripción activa en la oferta del examen.',
            'ELIGIBILITY_PENDING' => 'El estudiante no tiene una habilitación evaluada para este examen.',
            'INELIGIBLE' => 'El estudiante se encuentra inhabilitado para este examen.',
            'ROOM_MISMATCH' => 'El ambiente controlado no corresponde al ambiente asignado al examen.',
            'ALREADY_REGISTERED' => 'El estudiante ya tiene un ingreso registrado para este examen.',
            'EXAM_UNAVAILABLE' => 'El examen no está disponible para registrar ingresos.',
        ];
        $failed = [
            'STUDENT_INACTIVE' => $record->student_status !== 'ACTIVE',
            'NOT_ENROLLED' => $enrollment !== 'ACTIVE',
            'ELIGIBILITY_PENDING' => $eligibility === null,
            'INELIGIBLE' => $eligibility === 'INELIGIBLE',
            'ROOM_MISMATCH' => $roomStatus === 'MISMATCH',
            'ALREADY_REGISTERED' => $record->entry_id !== null,
            'EXAM_UNAVAILABLE' => ExamEntryAvailability::reason($exam) !== null,
        ];
        foreach ($failed as $code => $condition) {
            if ($condition) {
                $blockers[] = ['code' => $code, 'message' => $messages[$code]];
            }
        }
        $canBePending = $record->student_status === 'ACTIVE' && $enrollment === 'ACTIVE' && $eligibility === 'ELIGIBLE';
        $photo = $record->profile_photo;

        return [
            'student_id' => (int) $record->student_id,
            'first_names' => $record->first_names, 'last_names' => $record->last_names,
            'sis_code' => $record->sis_code, 'identity_number' => $record->identity_number,
            'profile_photo_url' => $photo
                ? ((str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) ? $photo : asset('storage/'.$photo))
                : null,
            'student_status' => $record->student_status, 'enrollment_status' => $enrollment,
            'eligibility_status' => $eligibility, 'reason_code' => $record->reason_code,
            'reason' => $record->reason, 'observations' => $record->observations,
            'assigned_room_id' => $associated ? (int) $exam->room_id : null,
            'controlled_room_id' => $roomId, 'room_status' => $roomStatus,
            'entry_status' => $record->entry_id !== null ? 'REGISTERED' : ($canBePending ? 'PENDING' : 'NOT_APPLICABLE'),
            'entry' => $record->entry_id !== null ? [
                'id' => (int) $record->entry_id,
                'entered_at' => Carbon::parse($record->entered_at, config('app.timezone'))->toISOString(),
                'verification_method' => $record->verification_method,
                'verified_by' => (int) $record->verified_by,
                'verified_by_name' => $verifiers->get($record->verified_by)?->display_name,
            ] : null,
            'meets_entry_conditions' => $blockers === [],
            'blockers' => $blockers,
        ];
    }
}
