<?php

namespace App\Services\CourseOfferings;

use App\Models\CourseOffering;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CourseOfferingService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = CourseOffering::query()->withContractRelations();
        foreach (['teacher_id', 'academic_term_id', 'status'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereHas('subject', function ($subject) use ($pattern) {
                    $subject->whereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
                })->orWhereHas('teacher', function ($teacher) use ($pattern) {
                    $teacher->where(function ($teacher) use ($pattern) {
                        foreach (['first_names', 'last_names', 'institutional_code'] as $field) {
                            $teacher->orWhereRaw("LOWER($field) LIKE ? ESCAPE '!'", [$pattern]);
                        }
                        $teacher->orWhereRaw("LOWER(first_names || ' ' || last_names) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereHas('user', fn ($user) => $user->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]));
                    });
                });
            });
        }
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($query->count() / 15)));

        return $query->orderByDesc('course_offerings.id')->paginate(15, ['*'], 'page', $page)->withQueryString();
    }

    public function find(int $id): CourseOffering
    {
        $offering = CourseOffering::withContractRelations()->find($id);
        if (! $offering) {
            throw new HttpResponseException(response()->json(['message' => 'La oferta académica solicitada no existe.', 'code' => 'NOT_FOUND'], 404));
        }

        return $offering;
    }
}
