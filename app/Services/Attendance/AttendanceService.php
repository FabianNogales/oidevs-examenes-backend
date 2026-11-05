<?php

namespace App\Services\Attendance;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AttendanceService
{
    public function __construct(private readonly AttendanceAccess $access, private readonly AttendanceCollaboratorActivity $activity) {}

    private function roster(Exam $exam): Builder
    {
        return DB::table('enrollments as en')->join('students as s', 's.id', '=', 'en.student_id')
            ->where('en.course_offering_id', $exam->course_offering_id)->where('en.status', 'ACTIVE')
            ->leftJoin('exam_entries as entry', function ($join) use ($exam) {
                $join->on('entry.student_id', '=', 's.id')->where('entry.exam_id', $exam->id)->where('entry.status', 'VALID');
            });
    }

    public function snapshot(int $examId, User $actor, array $filters = [], bool $includeStudents = false, bool $includeCollaborators = false): array
    {
        try {
            return DB::transaction(function () use ($examId, $actor, $filters, $includeStudents, $includeCollaborators) {
                // A single snapshot covers both counters and pagination without blocking entry writes.
                if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }
                $snapshotAt = now()->toISOString();
                $exam = $this->access->exam($examId, $actor);
                $base = $this->roster($exam);
                $counts = (clone $base)->selectRaw('COUNT(*) AS total, COUNT(entry.id) AS registered')->first();
                $total = (int) $counts->total;
                $registered = (int) $counts->registered;
                $response = [
                    'exam' => ['id' => $exam->id, 'name' => $exam->name, 'status' => $exam->status],
                    'summary' => ['total_students' => $total, 'registered' => $registered, 'pending' => $total - $registered,
                        'attendance_percentage' => $total === 0 ? 0 : round($registered * 100 / $total, 2)],
                    'roster_scope' => 'ACTIVE_ENROLLMENTS', 'source' => 'SERVER', 'is_current' => true,
                    'poll_after_seconds' => 5,
                    'offline' => ['pending_count' => null, 'available_on_server' => false, 'included_in_official_attendance' => false],
                    'exclusive_lists' => ['available' => false, 'reason' => 'EXCLUSIVE_LISTS_NOT_CONFIGURED'],
                ];
                if ($includeCollaborators) {
                    $response['collaborators'] = $this->activity->collect($exam, $base);
                }
                if ($includeStudents) {
                    $view = $filters['view'] ?? 'all';
                    if ($view === 'registered') {
                        $base->whereNotNull('entry.id');
                    } elseif ($view === 'pending') {
                        $base->whereNull('entry.id');
                    }
                    $search = trim((string) ($filters['search'] ?? ''));
                    if ($search !== '') {
                        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
                        $base->where(function (Builder $query) use ($pattern) {
                            $query->whereRaw("LOWER(s.first_names || ' ' || s.last_names) LIKE ? ESCAPE '!'", [$pattern])
                                ->orWhereRaw("LOWER(s.sis_code) LIKE ? ESCAPE '!'", [$pattern])
                                ->orWhereRaw("LOWER(s.identity_number) LIKE ? ESCAPE '!'", [$pattern]);
                        });
                    }
                    $page = $base->select('s.id as student_id', 's.first_names', 's.last_names', 's.sis_code', 's.identity_number',
                        's.status as student_status', 'entry.id as entry_id', 'entry.entered_at', 'entry.verified_by', 'entry.verification_method')
                        ->orderBy('s.id')->paginate((int) ($filters['per_page'] ?? 15), ['*'], 'page', (int) ($filters['page'] ?? 1));
                    $response['data'] = $page->getCollection()->map(fn ($row) => [
                        'student_id' => (int) $row->student_id, 'first_names' => $row->first_names, 'last_names' => $row->last_names,
                        'sis_code' => $row->sis_code, 'identity_number' => $row->identity_number, 'student_status' => $row->student_status,
                        'attendance_status' => $row->entry_id === null ? 'PENDING' : 'SERVER_CONFIRMED',
                        'entry' => $row->entry_id === null ? null : [
                            'id' => (int) $row->entry_id, 'entered_at' => Carbon::parse($row->entered_at, config('app.timezone'))->toISOString(),
                            'verified_by' => (int) $row->verified_by, 'verification_method' => $row->verification_method,
                        ],
                    ])->values()->all();
                    $response['meta'] = ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                        'total' => $page->total(), 'last_page' => $page->lastPage()];
                }
                $response['last_updated_at'] = $snapshotAt;

                return $response;
            });
        } catch (HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(response()->json(['code' => 'ATTENDANCE_UNAVAILABLE',
                'message' => 'No se pudo recuperar la asistencia vigente. Reintenta la consulta.',
                'data' => null, 'is_current' => false, 'last_updated_at' => null], 503)
                ->header('Cache-Control', 'private, no-store, max-age=0'));
        }
    }
}
