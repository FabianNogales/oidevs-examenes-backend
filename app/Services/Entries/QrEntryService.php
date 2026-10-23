<?php

namespace App\Services\Entries;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\Student;
use App\Models\StudentQrToken;
use App\Models\User;
use App\Services\Collaborators\ExamCollaboratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QrEntryService
{
    public function __construct(
        private QrTokenValidator $tokens,
        private EntryControlService $control,
        private WrongExamAuditService $wrongExamAudit,
    ) {}

    public function process(int $examId, User $user, array $parameters): JsonResponse
    {
        $wrongExam = null;
        $response = $this->register($examId, $user, $parameters, wrongExam: $wrongExam);

        // Persist only after the scan transaction has completed and released its locks.
        if ($wrongExam !== null) {
            $this->wrongExamAudit->record(...$wrongExam);
        }

        return $response;
    }

    public function processManual(int $examId, int $studentId, User $user, array $parameters): JsonResponse
    {
        return $this->register($examId, $user, $parameters, $studentId);
    }

    /** @param array{actorId: int, requestedExamId: int, qrExamId: int, studentId: int}|null $wrongExam */
    private function register(
        int $examId, User $user, array $parameters, ?int $manualStudentId = null, ?array &$wrongExam = null,
    ): JsonResponse
    {
        return DB::transaction(function () use ($examId, $user, $parameters, $manualStudentId, &$wrongExam) {
            // Serialize registrations for this exam, then read current conditions under row locks.
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            // Keep user mutations excluded while allowing HU12 audit/FK key-share checks.
            $user = User::whereKey($user->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
            $owner = (int) $exam->courseOffering?->teacher?->user_id === $user->id && $user->hasRole(RoleName::DOCENTE);
            $assignment = ExamCollaborator::where('exam_id', $exam->id)->where('user_id', $user->id)
                ->lockForUpdate()->first();
            abort_unless($user->isActive() && ($owner || ($assignment?->status === 'ACTIVE'
                && ExamCollaborator::examIsAvailable($exam)
                && app(ExamCollaboratorService::class)->isCandidate($exam, $user->id))), 403);

            $studentId = $manualStudentId;
            if ($manualStudentId === null) {
                $claims = $this->tokens->decode($parameters['qr_token']);
                if (! $claims) {
                    return $this->result('INVALID_QR', 'QR inválido.', null, 422);
                }
                $token = StudentQrToken::where('token', $claims['jti'])->lockForUpdate()->first();
                if (! $token || $token->status !== 'ACTIVE' || $token->revoked_at !== null) {
                    return $this->result('INVALID_QR', 'QR inválido.', null, 422);
                }
                if ((int) $token->exam_id !== $exam->id) {
                    $wrongExam = ['actorId' => (int) $user->id, 'requestedExamId' => (int) $exam->id,
                        'qrExamId' => (int) $token->exam_id, 'studentId' => (int) $token->student_id];

                    return $this->result('WRONG_EXAM', 'QR de otro examen.', null, 422);
                }
                $studentId = (int) $token->student_id;
            }
            $student = Student::whereKey($studentId)->lockForUpdate()->first();
            if (! $student) {
                if ($manualStudentId !== null) {
                    return $this->studentNotFound();
                }

                return $this->result('INVALID_QR', 'QR inválido.', null, 422);
            }
            $enrollments = DB::table('enrollments')->where('student_id', $student->id)
                ->where('course_offering_id', $exam->course_offering_id)->lockForUpdate()->get();
            $eligibilities = DB::table('exam_eligibilities')->where('student_id', $student->id)
                ->where('exam_id', $exam->id)->lockForUpdate()->get();
            if ($enrollments->isEmpty() && $eligibilities->isEmpty()) {
                if ($manualStudentId !== null) {
                    return $this->studentNotFound();
                }

                return $this->result('DENIED', 'Ingreso no autorizado.', null, 422);
            }
            $data = $this->control->show($exam, $student->id, ['room_id' => $parameters['room_id']])['data'];
            $entry = DB::table('exam_entries')->where('exam_id', $exam->id)->where('student_id', $student->id)
                ->where('status', 'VALID')->first();
            $data = $this->scanData($data, $entry);
            if ($entry) {
                return $this->result('ALREADY_REGISTERED', 'Ingreso ya registrado.', $data, 409);
            }
            if (! ExamCollaborator::examIsAvailable($exam)
                && ! in_array('EXAM_UNAVAILABLE', array_column($data['blockers'], 'code'), true)) {
                $data['blockers'][] = ['code' => 'EXAM_UNAVAILABLE', 'message' => 'El examen no está disponible para registrar ingresos.'];
            }
            if ($data['blockers'] !== []) {
                return $this->result('DENIED', 'Ingreso no autorizado.', $data, 422);
            }
            $entryId = DB::table('exam_entries')->insertGetId([
                'exam_id' => $exam->id, 'student_id' => $student->id, 'room_id' => $parameters['room_id'],
                'verified_by' => $user->id, 'verification_method' => $manualStudentId !== null ? 'MANUAL' : 'QR', 'entered_at' => now(),
                'status' => 'VALID', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $data['entry_status'] = 'REGISTERED';
            $data['entry'] = $this->entryData(DB::table('exam_entries')->find($entryId), $user);

            return $this->result('AUTHORIZED', $manualStudentId !== null ? 'Ingreso manual registrado exitosamente.' : 'Ingreso autorizado.', $data, 201);
        });
    }

    private function studentNotFound(): JsonResponse
    {
        return response()->json(['message' => 'Estudiante no encontrado.'], 404);
    }

    private function result(string $result, string $message, ?array $data, int $status): JsonResponse
    {
        return response()->json(['result' => $result, 'message' => $message,
            'processed_at' => now()->toISOString(), 'data' => $data], $status);
    }

    private function scanData(array $data, ?object $entry): array
    {
        return [
            'student' => array_intersect_key($data, array_flip(['student_id', 'first_names', 'last_names', 'sis_code', 'identity_number', 'profile_photo_url'])),
            'student_status' => $data['student_status'], 'enrollment_status' => $data['enrollment_status'],
            'eligibility_status' => $data['eligibility_status'], 'room_status' => $data['room_status'],
            'reason_code' => $data['reason_code'], 'reason' => $data['reason'], 'observations' => $data['observations'],
            'entry_status' => $data['entry_status'], 'blockers' => $data['blockers'],
            'entry' => $entry ? $this->entryData($entry) : null,
        ];
    }

    private function entryData(object $entry, ?User $verifier = null): array
    {
        $verifier ??= User::with(['teacher', 'student'])->find($entry->verified_by);

        return ['id' => (int) $entry->id, 'exam_id' => (int) $entry->exam_id,
            'student_id' => (int) $entry->student_id, 'room_id' => (int) $entry->room_id,
            'entered_at' => Carbon::parse($entry->entered_at, config('app.timezone'))->toISOString(),
            'verification_method' => $entry->verification_method, 'verified_by' => (int) $entry->verified_by,
            'verified_by_name' => $verifier?->display_name, 'status' => $entry->status];
    }

    public function history(Exam $exam, array $parameters): array
    {
        $query = DB::table('exam_entries as e')->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('e.exam_id', $exam->id);
        $search = trim((string) preg_replace('/\s+/u', ' ', $parameters['search'] ?? ''));
        if ($search !== '') {
            $words = explode(' ', mb_strtolower($search));
            $query->where(function ($filter) use ($words, $search) {
                foreach (['first_names', 'last_names', 'sis_code', 'identity_number'] as $column) {
                    $filter->orWhereRaw("LOWER(s.{$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                }
                $filter->orWhere(function ($names) use ($words) {
                    foreach ($words as $word) {
                        $names->where(function ($name) use ($word) {
                            $name->whereRaw('LOWER(s.first_names) LIKE ?', ['%'.$word.'%'])
                                ->orWhereRaw('LOWER(s.last_names) LIKE ?', ['%'.$word.'%']);
                        });
                    }
                });
            });
        }
        $page = $query->select('e.*', 's.first_names', 's.last_names', 's.sis_code', 's.identity_number', 'u.profile_photo')
            ->orderByDesc('e.entered_at')->orderByDesc('e.id')
            ->paginate((int) ($parameters['per_page'] ?? 10), ['*'], 'page', (int) ($parameters['page'] ?? 1));
        $verifiers = User::with(['teacher', 'student'])->whereIn('id', $page->getCollection()->pluck('verified_by')->unique())->get()->keyBy('id');

        return ['data' => $page->getCollection()->map(function ($row) use ($verifiers) {
            $photo = $row->profile_photo;

            return ['student' => ['student_id' => (int) $row->student_id, 'first_names' => $row->first_names,
                'last_names' => $row->last_names, 'sis_code' => $row->sis_code, 'identity_number' => $row->identity_number,
                'profile_photo_url' => $photo ? (preg_match('#^https?://#', $photo) ? $photo : asset('storage/'.$photo)) : null],
                'entry' => $this->entryData($row, $verifiers->get($row->verified_by))];
        })->values(), 'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }
}
