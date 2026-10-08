<?php

namespace App\Services\Students;

use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StudentService
{
    private const MAX_PER_PAGE = 100;

    /**
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Student>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), self::MAX_PER_PAGE);

        return Student::query()
            ->with(['user', 'career'])
            ->search($filters['search'] ?? null)
            ->orderBy('last_names')
            ->orderBy('first_names')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends(array_intersect_key($filters, array_flip(['search', 'per_page'])));
    }
}
