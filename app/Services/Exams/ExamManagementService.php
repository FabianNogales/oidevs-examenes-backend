<?php

namespace App\Services\Exams;

use App\Models\Exam;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class ExamManagementService
{
    public function __construct(private readonly ExamManagementPolicy $policy) {}

    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->owned($actor)->withManagementRelations();
        $view = $filters['view'] ?? 'upcoming';
        $this->filterByView($query, $view);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($search) => $search->whereRaw("LOWER(exams.name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('courseOffering.subject', fn ($subject) => $subject
                    ->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])));
        }
        $direction = $view === 'upcoming' ? 'asc' : 'desc';

        return $query->orderBy('exams.exam_date', $direction)->orderBy('exams.start_time', $direction)
            ->orderBy('exams.id', $direction)->paginate(10, ['exams.*'], 'page', (int) ($filters['page'] ?? 1))->withQueryString();
    }

    /** Reuse the teacher's time/state semantics for authorized administrative read views. */
    public function filterByView(Builder $query, string $view): Builder
    {
        $now = CarbonImmutable::now(ExamManagementPolicy::TIMEZONE)->format('Y-m-d H:i:s.u');
        $end = $this->endExpression();
        if ($view === 'upcoming') {
            $query->whereIn('exams.status', [...ExamManagementPolicy::SCHEDULED_STATUSES, 'IN_PROGRESS'])->whereRaw($end.' > ?', [$now]);
        } elseif ($view === 'past') {
            $query->where('exams.status', '!=', 'CANCELLED')->where(fn ($past) => $past
                ->whereIn('exams.status', ['COMPLETED', 'FINISHED'])->orWhereRaw($end.' <= ?', [$now]));
        } elseif ($view === 'cancelled') {
            $query->where('exams.status', 'CANCELLED');
        }
        return $query;
    }

    public function find(User $actor, string|int $id): Exam
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->notFound();
        }
        $exam = $this->owned($actor)->withManagementRelations()->find($id);
        if (! $exam || ! $this->policy->canView($actor, $exam)) {
            $this->notFound();
        }

        return $exam;
    }

    private function owned(User $actor): Builder
    {
        return Exam::query()->whereHas('courseOffering.teacher', fn ($teacher) => $teacher->where('user_id', $actor->id));
    }

    private function endExpression(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "(exams.exam_date + exams.start_time + exams.duration_minutes * INTERVAL '1 minute')",
            'sqlite' => "datetime(exams.exam_date || ' ' || exams.start_time, '+' || exams.duration_minutes || ' minutes')",
            default => throw new \LogicException('El historial de exámenes requiere PostgreSQL o SQLite.'),
        };
    }

    private function notFound(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'El examen no existe o no está disponible para tu usuario.', 'code' => 'EXAM_NOT_FOUND'], 404));
    }
}
