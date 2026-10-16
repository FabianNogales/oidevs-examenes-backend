<?php

namespace App\Services\CourseOfferings;

use App\Models\CourseOffering;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AcademicTerm;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Role;
use App\Models\Subject;
use App\Services\AcademicTerms\AcademicTermOfferingGuard;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class CourseOfferingService
{
    public function __construct(private readonly AcademicTermOfferingGuard $periods, private readonly AuditLogService $audit, private readonly AcademicTermIntegrity $integrity) {}

    public function bulk(array $data, Request $request): Collection
    {
        try {
            return $this->periods->write($data['academic_term_id'], function () use ($data, $request) {
                if (AcademicTerm::where('status', 'ACTIVE')->limit(2)->get()->count() > 1) {
                    $this->reject(409, 'ACADEMIC_TERM_STATE_INCONSISTENT', 'Existen varios periodos activos; revisa los periodos antes de asignar materias.', ['academic_term_id' => ['Solo puede existir un periodo activo.']]);
                }
                $teacher = $this->lockTeacher($data['teacher_id']);
                $sorted = $data['subject_ids'];
                sort($sorted, SORT_NUMERIC);
                $subjects = collect();
                foreach (array_chunk($sorted, 500) as $chunk) {
                    $subjects = $subjects->concat(Subject::whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get());
                }
                $subjects = $subjects->keyBy('id');
                $errors = [];
                foreach ($data['subject_ids'] as $index => $id) {
                    if (! isset($subjects[$id]) || $subjects[$id]->status !== 'ACTIVE') {
                        $errors['subject_ids.'.$index] = ['La materia debe existir y estar activa.'];
                    }
                }
                if ($errors !== []) {
                    $this->reject(422, 'COURSE_OFFERING_SUBJECT_INACTIVE', 'Revisa las materias seleccionadas.', $errors);
                }
                $duplicateIds = [];
                foreach (array_chunk($sorted, 500) as $chunk) {
                    $duplicateIds = array_merge($duplicateIds, CourseOffering::where('teacher_id', $teacher->id)
                        ->where('academic_term_id', $data['academic_term_id'])->whereIn('subject_id', $chunk)->pluck('subject_id')->all());
                }
                foreach ($data['subject_ids'] as $index => $id) {
                    if (in_array($id, $duplicateIds, true)) {
                        $errors['subject_ids.'.$index] = ['El docente ya tiene esta materia en el periodo, aunque la oferta esté inactiva.'];
                    }
                }
                if ($errors !== []) {
                    $this->reject(409, 'COURSE_OFFERING_DUPLICATE', 'La asignación ya existe.', $errors);
                }
                $ids = [];
                foreach ($data['subject_ids'] as $subjectId) {
                    $offering = CourseOffering::create(['teacher_id' => $teacher->id, 'subject_id' => $subjectId,
                        'academic_term_id' => $data['academic_term_id'], 'status' => 'ACTIVE']);
                    $this->audit->log($request, $request->user()->id, 'COURSE_OFFERING_CREATED', 'COURSE_OFFERING', $offering->id,
                        null, $offering->only(['teacher_id', 'subject_id', 'academic_term_id', 'status']));
                    $ids[] = $offering->id;
                }

                $result = new Collection;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $result = $result->merge(CourseOffering::withContractRelations()->whereIn('id', $chunk)->get());
                }

                return $result->sortBy('id')->values();
            });
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'course_offerings_teacher_subject_term_unique') || str_contains($exception->getMessage(), 'UNIQUE constraint failed: course_offerings.')) {
                $this->reject(409, 'COURSE_OFFERING_DUPLICATE', 'La asignación ya existe.', ['subject_ids' => ['Una materia ya está asignada a este docente en el periodo.']]);
            }
            report($exception);
            $this->reject(500, 'INTERNAL_ERROR', 'No se pudo completar la asignación.', []);
        } catch (\Throwable $exception) {
            report($exception);
            $this->reject(500, 'INTERNAL_ERROR', 'No se pudo completar la asignación.', []);
        }
    }

    public function update(int $id, array $data, Request $request): CourseOffering
    {
        return $this->mutate(function () use ($id, $data, $request) {
            $offering = $this->locked($id);
            $old = $this->snapshot($offering);
            $changed = false;
            foreach (['teacher_id', 'subject_id', 'academic_term_id'] as $field) {
                $changed = $changed || $offering->$field !== $data[$field];
            }
            if (! $changed) {
                return $this->find($id);
            }
            if ($offering->enrollments()->exists() || $offering->exams()->exists()) {
                $this->reject(409, 'COURSE_OFFERING_HAS_HISTORY', 'No puedes cambiar la combinación de una oferta con inscripciones o exámenes.',
                    ['subject_id' => ['La oferta tiene información histórica asociada.'], 'academic_term_id' => ['No puedes trasladar una oferta con historial a otro periodo.']]);
            }
            return $this->periods->write($data['academic_term_id'], function () use ($offering, $data, $request, $old, $id) {
                $this->lockEnabledReferences($data);
                if (CourseOffering::where('teacher_id', $data['teacher_id'])->where('subject_id', $data['subject_id'])
                    ->where('academic_term_id', $data['academic_term_id'])->where('id', '!=', $id)->exists()) {
                    $this->reject(409, 'COURSE_OFFERING_DUPLICATE', 'La asignación ya existe.', ['subject_id' => ['El docente ya tiene esta materia en el periodo.']]);
                }
                $offering->fill(array_intersect_key($data, array_flip(['teacher_id', 'subject_id', 'academic_term_id'])))->save();
                $this->audit->log($request, $request->user()->id, 'COURSE_OFFERING_UPDATED', 'COURSE_OFFERING', $id, $old, $this->snapshot($offering));

                return $this->find($id);
            });
        });
    }

    public function changeStatus(int $id, string $status, Request $request): CourseOffering
    {
        return $this->mutate(function () use ($id, $status, $request) {
            $offering = $this->locked($id);
            if (! in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                $this->reject(422, 'VALIDATION_ERROR', 'El estado debe ser ACTIVE o INACTIVE.', ['status' => ['Estado inválido.']]);
            }
            if ($offering->status === $status) {
                return $this->find($id);
            }
            $old = $this->snapshot($offering);
            $write = function () use ($offering, $status, $request, $old, $id) {
                if ($status === 'ACTIVE') {
                    $this->lockEnabledReferences($old);
                }
                $offering->status = $status;
                $offering->save();
                $this->audit->log($request, $request->user()->id,
                    $status === 'ACTIVE' ? 'COURSE_OFFERING_ACTIVATED' : 'COURSE_OFFERING_DEACTIVATED',
                    'COURSE_OFFERING', $id, $old, $this->snapshot($offering));

                return $this->find($id);
            };

            return $status === 'ACTIVE' ? $this->periods->write($offering->academic_term_id, $write) : $write();
        });
    }

    private function mutate(\Closure $operation): CourseOffering
    {
        try {
            return $this->integrity->transaction($operation);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'course_offerings_teacher_subject_term_unique') || str_contains($exception->getMessage(), 'UNIQUE constraint failed: course_offerings.')) {
                $this->reject(409, 'COURSE_OFFERING_DUPLICATE', 'La asignación ya existe.', ['subject_id' => ['La combinación ya está registrada.']]);
            }
            report($exception);
            $this->reject(500, 'INTERNAL_ERROR', 'No se pudo modificar la oferta académica.', []);
        } catch (\Throwable $exception) {
            report($exception);
            $this->reject(500, 'INTERNAL_ERROR', 'No se pudo modificar la oferta académica.', []);
        }
    }

    private function lockTeacher(int $id): Teacher
    {
        $teacher = Teacher::whereKey($id)->lockForUpdate()->first();
        $user = $teacher ? User::whereKey($teacher->user_id)->lockForUpdate()->first() : null;
        $roles = Role::where('name', RoleName::DOCENTE->value)->orderBy('id')->lockForUpdate()->get();
        $assignments = $user ? DB::table('role_user')->where('user_id', $user->id)->whereIn('role_id', $roles->modelKeys())->lockForUpdate()->get() : collect();
        $activeRoleIds = $roles->where('status', 'ACTIVE')->modelKeys();
        $hasRole = $assignments->contains(fn ($assignment) => $assignment->status === 'ACTIVE' && in_array((int) $assignment->role_id, $activeRoleIds, true));
        if (! $teacher || $teacher->status !== UserStatus::ACTIVE || ! $user || ! $user->isActive() || ! $hasRole) {
            $this->reject(422, 'COURSE_OFFERING_TEACHER_INACTIVE', 'El docente, su cuenta y su rol deben estar habilitados.', ['teacher_id' => ['Selecciona un docente activo con cuenta y rol DOCENTE habilitados.']]);
        }

        return $teacher;
    }

    private function lockEnabledReferences(array $data): void
    {
        if (AcademicTerm::where('status', 'ACTIVE')->limit(2)->get()->count() > 1) {
            $this->reject(409, 'ACADEMIC_TERM_STATE_INCONSISTENT', 'Existen varios periodos activos.', ['academic_term_id' => ['Revisa los periodos antes de continuar.']]);
        }
        $this->lockTeacher($data['teacher_id']);
        $subject = Subject::whereKey($data['subject_id'])->lockForUpdate()->first();
        if (! $subject || $subject->status !== 'ACTIVE') {
            $this->reject(422, 'COURSE_OFFERING_SUBJECT_INACTIVE', 'La materia debe existir y estar activa.', ['subject_id' => ['Selecciona una materia activa.']]);
        }
    }

    private function locked(int $id): CourseOffering
    {
        $offering = CourseOffering::whereKey($id)->lockForUpdate()->first();
        if (! $offering) {
            $this->reject(404, 'NOT_FOUND', 'La oferta académica solicitada no existe.', []);
        }

        return $offering;
    }

    private function snapshot(CourseOffering $offering): array
    {
        return $offering->only(['teacher_id', 'subject_id', 'academic_term_id', 'status']);
    }

    private function reject(int $status, string $code, string $message, array $errors): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code, 'errors' => (object) $errors], $status));
    }

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
