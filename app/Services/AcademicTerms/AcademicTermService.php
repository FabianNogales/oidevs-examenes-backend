<?php

namespace App\Services\AcademicTerms;

use App\Models\AcademicTerm;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;

class AcademicTermService
{
    public function __construct(private readonly AcademicTermIntegrity $integrity, private readonly AuditLogService $audit) {}

    public function save(array $data, Request $request, ?int $id = null): AcademicTerm
    {
        try {
            return $this->integrity->transaction(function () use ($data, $request, $id) {
                if ($id !== null) {
                    $this->find($id);
                }
                $data = $this->integrity->validatePayload($data, $id === null);
                $term = $this->integrity->assertCanSave($data, $id);
                $creating = $term === null;
                $old = $creating ? null : $this->snapshot($term);
                $term ??= new AcademicTerm;
                $term->fill($data);
                if (! $creating && ! $term->isDirty()) {
                    return $term;
                }
                $term->save();
                $this->audit->log($request, $request->user()->id,
                    $creating ? 'ACADEMIC_TERM_CREATED' : 'ACADEMIC_TERM_UPDATED', 'ACADEMIC_TERM', $term->id,
                    $old, $this->snapshot($term));

                return $term;
            });
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'academic_terms_name_unique') || str_contains($message, 'UNIQUE constraint failed: academic_terms.name')) {
                throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.', 'code' => 'ACADEMIC_TERM_NAME_DUPLICATE',
                    'errors' => ['name' => ['Ya existe un periodo con este nombre.']]], 422));
            }
            if (str_contains($message, 'academic_terms_date_range_check')) {
                throw new HttpResponseException(response()->json(['message' => 'Revisa los campos indicados.', 'code' => 'VALIDATION_ERROR',
                    'errors' => ['end_date' => ['La fecha de fin no puede ser anterior a la fecha de inicio.']]], 422));
            }
            $this->internalError($exception);
        } catch (\Throwable $exception) {
            $this->internalError($exception);
        }
    }

    private function snapshot(AcademicTerm $term): array
    {
        return ['name' => $term->name, 'start_date' => $term->start_date->toDateString(),
            'end_date' => $term->end_date->toDateString(), 'status' => $term->status];
    }

    private function internalError(\Throwable $exception): never
    {
        report($exception);
        throw new HttpResponseException(response()->json(['message' => 'No se pudo completar la operación.', 'code' => 'INTERNAL_ERROR'], 500));
    }

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
