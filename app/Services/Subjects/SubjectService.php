<?php

namespace App\Services\Subjects;

use App\Models\Subject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SubjectService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Subject::query()->with(['careers' => fn ($query) => $query->orderBy('careers.name')->orderBy('careers.id')]);
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['career_id'])) {
            $query->whereHas('careers', fn ($query) => $query->where('careers.id', $filters['career_id']));
        }

        // Ascending ID keeps the catalogue stable, including historical subjects.
        $perPage = (int) ($filters['per_page'] ?? 15);
        $total = $query->count();
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($total / $perPage)));

        return $query->orderBy('subjects.id')->paginate($perPage, ['*'], 'page', $page)->withQueryString();
    }
}
