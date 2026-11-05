<?php

namespace App\Services\Infractions;

use App\Models\Infraction;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InfractionService
{
    public function __construct(private readonly InfractionAccess $access, private readonly AuditLogService $audit) {}

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409));
    }

    private function atomic(\Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (HttpExceptionInterface|HttpResponseException|ModelNotFoundException|ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(response()->json(['code' => 'INTERNAL_ERROR',
                'message' => 'No se pudo guardar la infracción. Consulta su estado antes de reintentar.'], 500));
        }
    }

    private function snapshot(Infraction $infraction): array
    {
        return $infraction->only(['exam_id', 'student_id', 'type', 'description', 'observations', 'reported_by', 'status'])
            + ['occurred_at' => $infraction->occurred_at->toISOString()];
    }

    private function evidence(Infraction $infraction, Request $request, CarbonImmutable $version): array
    {
        $actor = User::with(['teacher', 'student'])->findOrFail($request->user()->id);

        return $this->snapshot($infraction) + ['version_updated_at' => $version->toISOString(),
            'performed_at' => $version->toISOString(),
            'performed_by' => ['id' => (int) $actor->id, 'name' => $actor->display_name]];
    }

    private function hydrateDetail(int $id): Infraction
    {
        $infraction = Infraction::withContext()->findOrFail($id);
        $infraction->setRelation('contractTimeline', $infraction->changes()->with(['user.teacher', 'user.student'])
            ->orderBy('created_at')->orderBy('id')->get());

        return $infraction;
    }

    public function detail(int $examId, int $id, User $actor): Infraction
    {
        return DB::transaction(function () use ($examId, $id, $actor) {
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $this->find($examId, $id, $actor);

            return $this->hydrateDetail($id);
        });
    }

    public function context(int $examId, User $actor): array
    {
        $exam = $this->access->exam($examId, $actor);

        return ['exam' => ['id' => (int) $exam->id, 'name' => $exam->name,
            'subject_name' => $exam->courseOffering->subject->name], 'permissions' => ['can_create' => true]];
    }

    public function create(int $examId, array $data, Request $request): Infraction
    {
        return $this->atomic(function () use ($examId, $data, $request) {
            $exam = $this->access->exam($examId, $request->user(), lock: true);
            Student::whereKey($data['student_id'])->lockForUpdate()->firstOrFail();
            $this->access->assertStudent($exam, (int) $data['student_id']);
            $infraction = Infraction::create([
                'exam_id' => $exam->id, 'student_id' => $data['student_id'], 'type' => $data['type'],
                'description' => $data['description'], 'observations' => $data['observations'] ?? null,
                'reported_by' => $request->user()->id, 'occurred_at' => now(), 'status' => 'REPORTED',
            ]);
            $this->audit->log($request, $request->user()->id, 'INFRACTION_REPORTED', 'Infraction', $infraction->id,
                newValues: $this->evidence($infraction, $request, InfractionRevision::next()));

            return $this->hydrateDetail($infraction->id);
        });
    }

    public function update(int $examId, int $id, array $data, Request $request, bool $changeStatus = false): Infraction
    {
        return $this->atomic(function () use ($examId, $id, $data, $request, $changeStatus) {
            $this->access->exam($examId, $request->user(), lock: true);
            $infraction = Infraction::where('exam_id', $examId)->lockForUpdate()->findOrFail($id);
            $latest = $infraction->changes()->orderByDesc('id')->first();
            $revision = (int) ($latest?->id ?? 0);
            $current = InfractionRevision::current($infraction, $latest);
            if ((isset($data['expected_revision']) && (int) $data['expected_revision'] !== $revision)
                || (isset($data['expected_updated_at']) && ! CarbonImmutable::parse($data['expected_updated_at'])->equalTo($current))) {
                $this->conflict('INFRACTION_VERSION_CONFLICT', 'La infracción cambió. Recarga sus datos antes de continuar.');
            }
            if ($infraction->status === 'RESOLVED') {
                $this->conflict('INFRACTION_RESOLVED', 'La infracción está resuelta y conserva su información histórica.');
            }
            $before = $this->snapshot($infraction);
            if ($changeStatus) {
                $allowed = match ($infraction->status) {
                    'REPORTED' => ['CONFIRMED', 'RESOLVED'],
                    'CONFIRMED' => ['RESOLVED'],
                    default => [],
                };
                if (! in_array($data['status'], $allowed, true)) {
                    $this->conflict('INFRACTION_TRANSITION_NOT_ALLOWED', 'Este cambio de estado no está permitido.');
                }
                $infraction->status = $data['status'];
            } else {
                $infraction->fill(array_intersect_key($data, array_flip(['type', 'description', 'observations'])));
            }
            if ($infraction->isDirty()) {
                $infraction->save();
                $after = $this->evidence($infraction, $request, InfractionRevision::next($current));
                if ($changeStatus) {
                    $after['reason'] = $data['reason'];
                }
                $this->audit->log($request, $request->user()->id,
                    $changeStatus ? 'INFRACTION_STATUS_CHANGED' : 'INFRACTION_UPDATED', 'Infraction', $infraction->id, $before, $after);
            }

            return $this->hydrateDetail($infraction->id);
        });
    }

    public function find(int $examId, int $id, User $actor): Infraction
    {
        $this->access->exam($examId, $actor);

        return Infraction::withContext()->where('exam_id', $examId)->findOrFail($id);
    }

    private function filter(Builder $query, array $filters): Builder
    {
        foreach (['type', 'status', 'student_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $query->whereHas('student', fn ($student) => $this->searchStudent($student, $filters['search']));
        }

        return $query;
    }

    private function searchStudent(Builder $query, string $search): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($search))).'%';

        return $query->where(function ($q) use ($pattern) {
            $q->whereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(sis_code) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(identity_number) LIKE ? ESCAPE '!'", [$pattern]);
        });
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->access->exam($examId, $actor);

        return $this->validPage($this->filter(Infraction::withContext()->where('exam_id', $examId), $filters)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1)));
    }

    public function students(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->access->students($this->access->exam($examId, $actor));
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $this->searchStudent($query, $filters['search']);
        }

        return $query->select(['id', 'first_names', 'last_names', 'sis_code', 'identity_number', 'status'])->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function studentHistory(int $examId, int $studentId, User $actor, array $filters): LengthAwarePaginator
    {
        $exam = $this->access->exam($examId, $actor);
        $this->access->assertStudent($exam, $studentId);
        $query = Infraction::withContext()->where('student_id', $studentId)
            ->whereHas('exam.courseOffering.teacher', fn ($teacher) => $teacher->where('user_id', $actor->id));

        return $this->validPage($this->filter($query, array_diff_key($filters, ['student_id' => true]))->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1)));
    }

    private function validPage(LengthAwarePaginator $page): LengthAwarePaginator
    {
        if ($page->currentPage() > $page->lastPage()) {
            throw ValidationException::withMessages(['page' => 'La página solicitada está fuera de rango.']);
        }

        return $page;
    }

    public function history(int $examId, int $id, User $actor, array $filters): LengthAwarePaginator
    {
        return $this->find($examId, $id, $actor)->changes()->with(['user.teacher', 'user.student'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }
}
