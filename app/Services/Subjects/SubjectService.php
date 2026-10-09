<?php

namespace App\Services\Subjects;

use App\Models\Subject;
use App\Models\Career;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function save(array $data, Request $request, ?Subject $subject = null): Subject
    {
        try {
            return DB::transaction(function () use ($data, $request, $subject) {
                $creating = $subject === null;
                $subject = $creating ? new Subject : Subject::query()->lockForUpdate()->findOrFail($subject->id);
                $old = $creating ? null : $this->snapshot($subject);
                $ids = $data['career_ids'];
                sort($ids, SORT_NUMERIC);
                $careers = Career::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                if ($careers->count() !== count($ids) || $ids === []) {
                    throw ValidationException::withMessages(['career_ids' => 'Selecciona carreras existentes y distintas.']);
                }
                $existing = $creating ? [] : $subject->careers()->pluck('careers.id')->all();
                foreach ($careers as $career) {
                    if ($career->status !== 'ACTIVE' && ! in_array($career->id, $existing, true)) {
                        throw ValidationException::withMessages(['career_ids' => 'No puedes agregar carreras inactivas.']);
                    }
                }
                $code = strtoupper(trim($data['code']));
                $duplicate = Subject::query()->whereRaw('UPPER(code) = ?', [$code]);
                if (! $creating) {
                    $duplicate->where('id', '!=', $subject->id);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages(['code' => 'Ya existe una materia con este código.']);
                }
                $subject->code = $code;
                $subject->name = trim($data['name']);
                if ($creating) {
                    $subject->status = 'ACTIVE';
                }
                $subject->save();
                $subject->careers()->sync($ids);
                $this->audit->log($request, $request->user()->id,
                    $creating ? 'SUBJECT_CREATED' : 'SUBJECT_UPDATED', Subject::class, $subject->id,
                    $old, $this->snapshot($subject));

                return $subject->load(['careers' => fn ($query) => $query->orderBy('careers.name')->orderBy('careers.id')]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'subjects_code_unique') && ! str_contains($exception->getMessage(), 'subjects.code')) {
                throw $exception;
            }
            throw ValidationException::withMessages(['code' => 'Ya existe una materia con este código.']);
        }
    }

    private function snapshot(Subject $subject): array
    {
        return $subject->only(['code', 'name', 'status']) + [
            'career_ids' => $subject->careers()->orderBy('careers.id')->pluck('careers.id')->all(),
        ];
    }

    public function changeStatus(Subject $subject, string $status, Request $request): Subject
    {
        if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            throw ValidationException::withMessages(['status' => 'El estado debe ser ACTIVE o INACTIVE.']);
        }

        return DB::transaction(function () use ($subject, $status, $request) {
            $subject = Subject::query()->lockForUpdate()->findOrFail($subject->id);
            if ($subject->status !== $status) {
                $old = $this->snapshot($subject);
                $subject->status = $status;
                $subject->save();
                $this->audit->log($request, $request->user()->id,
                    $status === 'ACTIVE' ? 'SUBJECT_ACTIVATED' : 'SUBJECT_DEACTIVATED',
                    Subject::class, $subject->id, $old, $this->snapshot($subject));
            }

            return $subject->load(['careers' => fn ($query) => $query->orderBy('careers.name')->orderBy('careers.id')]);
        });
    }

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
