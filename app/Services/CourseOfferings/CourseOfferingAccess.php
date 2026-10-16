<?php

namespace App\Services\CourseOfferings;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AcademicTerm;
use App\Models\CourseOffering;
use App\Models\Role;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AcademicTerms\AcademicTermIntegrity;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class CourseOfferingAccess
{
    public function __construct(private readonly AcademicTermIntegrity $integrity) {}

    public function run(int $id, int $userId, bool $writing, Closure $operation): mixed
    {
        $guarded = function () use ($id, $userId, $writing, $operation) {
            $query = CourseOffering::whereKey($id);
            $offering = ($writing ? $query->lockForUpdate() : $query->sharedLock())->first();
            $teacher = $offering ? Teacher::whereKey($offering->teacher_id) : null;
            $teacher = $writing ? $teacher?->lockForUpdate()->first() : $teacher?->first();
            if (! $offering || ! $teacher || (int) $teacher->user_id !== $userId) {
                throw new HttpResponseException(response()->json(['message' => 'Forbidden - You do not own this course offering.'], 403));
            }
            if ($writing) {
                $user = User::whereKey($userId)->lockForUpdate()->first();
                $roles = Role::where('name', RoleName::DOCENTE->value)->orderBy('id')->lockForUpdate()->get();
                $pivots = DB::table('role_user')->where('user_id', $userId)->whereIn('role_id', $roles->modelKeys())->lockForUpdate()->get();
                $activeRoles = $roles->where('status', 'ACTIVE')->modelKeys();
                $hasRole = $pivots->contains(fn ($pivot) => $pivot->status === 'ACTIVE' && in_array((int) $pivot->role_id, $activeRoles, true));
                $subject = Subject::whereKey($offering->subject_id)->lockForUpdate()->first();
                $period = AcademicTerm::find($offering->academic_term_id);
                if ($offering->status !== 'ACTIVE' || $teacher->status !== UserStatus::ACTIVE || ! $user?->isActive()
                    || ! $hasRole || $subject?->status !== 'ACTIVE' || $period?->status !== 'ACTIVE') {
                    $this->reject('COURSE_OFFERING_READ_ONLY', 'Esta oferta solo permite consultar su historial.');
                }
                if (AcademicTerm::where('status', 'ACTIVE')->limit(2)->get()->count() > 1) {
                    $this->reject('ACADEMIC_TERM_STATE_INCONSISTENT', 'Existen varios periodos activos. Revisa los periodos antes de continuar.');
                }
            }

            // Keep ownership and state locks until enrollments/exams and their audit commit.
            return $operation($offering);
        };

        return $writing ? $this->integrity->transaction($guarded) : DB::transaction($guarded);
    }

    private function reject(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code,
            'errors' => ['course_offering_id' => [$message]]], 422));
    }
}
