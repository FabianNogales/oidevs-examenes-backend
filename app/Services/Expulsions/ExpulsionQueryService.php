<?php

namespace App\Services\Expulsions;

use App\Models\Expulsion;
use App\Models\Student;
use App\Models\User;
use App\Services\Entries\ExamEntryAvailability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ExpulsionQueryService
{
    public function __construct(private readonly ExpulsionAccess $access) {}

    private function id(string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($value === false, 404);

        return $value;
    }

    private function search(Builder $query, string $search): Builder
    {
        $text = mb_strtolower(trim($search));
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';

        return $query->where(function (Builder $match) use ($pattern) {
            $match->whereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(sis_code) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(identity_number) LIKE ? ESCAPE '!'", [$pattern]);
        });
    }

    private function paginateQuery(Builder $query, array $filters): LengthAwarePaginator
    {
        if (isset($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $query->whereHas('student', fn (Builder $student) => $this->search($student, $filters['search']));
        }

        return $query->orderByDesc('expelled_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function paginate(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $this->access->exam($examId, $actor);

        return $this->paginateQuery(Expulsion::withContext()->where('exam_id', $examId), $filters);
    }

    public function find(int $examId, string $id, User $actor): Expulsion
    {
        $this->access->exam($examId, $actor);

        return Expulsion::withContext()->where('exam_id', $examId)->findOrFail($this->id($id));
    }

    public function students(int $examId, User $actor, array $filters): LengthAwarePaginator
    {
        $exam = $this->access->exam($examId, $actor);
        $query = $this->access->students($exam);
        if (isset($filters['student_id'])) {
            $query->whereKey($filters['student_id']);
        }
        if (trim((string) ($filters['search'] ?? '')) !== '') {
            $this->search($query, $filters['search']);
        }
        $page = $query->select(['students.id', 'first_names', 'last_names', 'sis_code', 'identity_number', 'status'])
            ->selectSub(Expulsion::select('id')->where('exam_id', $examId)->whereColumn('student_id', 'students.id'), 'expulsion_id')
            ->orderBy('students.id')->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $reason = ExamEntryAvailability::reason($exam);
        $page->getCollection()->transform(fn (Student $student) => $student->only(['id', 'first_names', 'last_names', 'sis_code', 'identity_number', 'status']) + [
            'condition' => $student->expulsion_id === null ? 'NOT_EXPELLED' : 'EXPELLED',
            'expulsion_id' => $student->expulsion_id === null ? null : (int) $student->expulsion_id,
            'can_expel' => $student->expulsion_id === null && $reason === null,
            'expulsion_block_reason' => $student->expulsion_id !== null ? 'STUDENT_ALREADY_EXPELLED' : $reason,
        ]);

        return $page;
    }

    public function studentHistory(int $examId, string $studentId, User $actor, array $filters): LengthAwarePaginator
    {
        $exam = $this->access->exam($examId, $actor);
        $id = $this->id($studentId);
        abort_unless($this->access->students($exam)->whereKey($id)->exists(), 404, 'Estudiante no relacionado con este examen.');

        return $this->paginateQuery(Expulsion::withContext()->where('student_id', $id)
            ->whereHas('exam.courseOffering.teacher', fn ($teacher) => $teacher->where('user_id', $actor->id)),
            array_diff_key($filters, ['student_id' => true]));
    }
}
