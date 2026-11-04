<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamCollaborator;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\ExamEntryAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineAssignmentService
{
    public function __construct(private OfflineExamAccess $access, private AuditLogService $audit) {}

    public function prepare(int $examId, Request $request): array
    {
        return DB::transaction(function () use ($examId, $request) {
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
            $this->access->authorizeOwner($exam, $actor);
            if ($reason = ExamEntryAvailability::preparationReason($exam)) {
                $this->access->fail($reason, 'El examen no permite preparar listas offline.');
            }
            $responsibles = ExamCollaborator::where('exam_id', $examId)->where('status', 'ACTIVE')
                ->orderBy('user_id')->get()->map(fn ($row) => User::find($row->user_id))
                ->filter(fn ($user) => $user && $this->access->authorizationHash($exam, $user) !== null)->values();
            if ($responsibles->isEmpty()) {
                $responsibles = collect([$actor]);
            }
            $students = DB::table('students as s')->join('enrollments as en', 'en.student_id', '=', 's.id')
                ->where('en.course_offering_id', $exam->course_offering_id)->where('en.status', 'ACTIVE')
                ->select('s.id', 's.last_names', 's.first_names', 's.sis_code')->get()
                // Stable Unicode lowercasing and binary comparison avoid DB collation-dependent boundaries.
                ->sort(function ($a, $b) {
                    foreach (['last_names', 'first_names', 'sis_code'] as $field) {
                        $order = strcmp(mb_strtolower($a->$field, 'UTF-8'), mb_strtolower($b->$field, 'UTF-8'));
                        if ($order !== 0) {
                            return $order;
                        }
                    }

                    return $a->id <=> $b->id;
                })->values();
            $authorizations = $responsibles->map(fn ($user) => [$user->id, $this->access->authorizationHash($exam, $user)])->all();
            $examHash = $this->access->examHash($exam);
            $inputHash = hash('sha256', json_encode([$examHash, $authorizations, $students->all()], JSON_THROW_ON_ERROR));
            $distribution = ExamOfflineDistribution::where('exam_id', $examId)->first();
            if ($distribution) {
                if (! hash_equals($distribution->input_hash, $inputHash)) {
                    $this->access->fail('DISTRIBUTION_FROZEN', 'La distribución ya existe y sus datos cambiaron. La versión 1 no permite regenerarla ni reasignarla.');
                }

                return $this->distribution($examId);
            }
            $distribution = ExamOfflineDistribution::create(['exam_id' => $examId, 'version' => 1,
                'prepared_by' => $actor->id, 'input_hash' => $inputHash, 'exam_hash' => $examHash, 'prepared_at' => now()]);
            $offset = 0;
            foreach ($responsibles as $index => $responsible) {
                $list = ExamOfflineList::create(['distribution_id' => $distribution->id, 'responsible_id' => $responsible->id,
                    'authorization_hash' => $authorizations[$index][1], 'position' => $index + 1]);
                $size = intdiv($students->count(), $responsibles->count()) + ($index < $students->count() % $responsibles->count() ? 1 : 0);
                foreach ($students->slice($offset, $size)->values() as $position => $student) {
                    DB::table('exam_offline_members')->insert(['exam_id' => $examId, 'list_id' => $list->id,
                        'student_id' => $student->id, 'position' => $position + 1]);
                }
                $offset += $size;
            }
            $this->audit->log($request, $actor->id, 'OFFLINE_LISTS_PREPARED', 'ExamOfflineDistribution', $distribution->id,
                null, ['exam_id' => $examId, 'version' => 1, 'students' => $students->count(), 'lists' => $responsibles->count()]);

            return $this->distribution($examId);
        }, 3);
    }

    public function assignments(int $examId, Request $request): array
    {
        $exam = Exam::findOrFail($examId);
        $this->access->authorizeOwner($exam, $request->user());

        return $this->distribution($examId);
    }

    private function distribution(int $examId): array
    {
        $distribution = ExamOfflineDistribution::where('exam_id', $examId)->firstOrFail();
        $lists = ExamOfflineList::where('distribution_id', $distribution->id)->orderBy('position')->get();
        $members = DB::table('exam_offline_members as m')->join('students as s', 's.id', '=', 'm.student_id')
            ->where('m.exam_id', $examId)->orderBy('m.position')
            ->get(['m.list_id', 's.id as student_id', 's.last_names', 's.first_names', 's.sis_code'])->groupBy('list_id');

        return ['exam_id' => $examId, 'version' => $distribution->version, 'prepared_at' => $distribution->prepared_at->toISOString(),
            'lists' => $lists->map(function ($list) use ($members) {
                $students = $members->get($list->id, collect())->values();

                return ['list_id' => $list->id, 'responsible_id' => (int) $list->responsible_id,
                    'responsible_name' => User::find($list->responsible_id)?->display_name,
                    'student_count' => $students->count(),
                    'surname_range' => ['from' => $students->first()?->last_names, 'to' => $students->last()?->last_names],
                    'students' => $students];
            })->all()];
    }
}
