<?php

namespace App\Services\AcademicTerms;

use App\Models\AcademicTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AcademicTermService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = AcademicTerm::query();
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($query->count() / $perPage)));

        return $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page)->withQueryString();
    }

    public function find(int $id): AcademicTerm
    {
        $term = AcademicTerm::find($id);
        if (! $term) {
            throw new HttpResponseException(response()->json(['message' => 'El periodo solicitado no existe.', 'code' => 'NOT_FOUND'], 404));
        }

        return $term;
    }

    public function active(): ?AcademicTerm
    {
        $terms = AcademicTerm::where('status', 'ACTIVE')->orderBy('id')->limit(2)->get();
        if ($terms->count() > 1) {
            throw new HttpResponseException(response()->json(['message' => 'Existen varios periodos activos; revisa los datos antes de continuar.',
                'code' => 'ACADEMIC_TERM_STATE_INCONSISTENT'], 409));
        }

        return $terms->first();
    }
}
