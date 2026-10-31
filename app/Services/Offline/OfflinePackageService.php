<?php

namespace App\Services\Offline;

use App\Models\Exam;
use App\Models\ExamOfflineDistribution;
use App\Models\ExamOfflineList;
use App\Models\ExamOfflinePackage;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Entries\EntryControlService;
use App\Services\Entries\ExamEntryAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflinePackageService
{
    public function __construct(private OfflineExamAccess $access, private AuditLogService $audit) {}

    public function download(int $examId, Request $request): array
    {
        return DB::transaction(function () use ($examId, $request) {
            $exam = Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
            $authorization = $this->access->authorizationHash($exam, $actor);
            abort_unless($authorization, 403, 'No tiene autorización vigente para descargar este paquete.');
            if ($reason = ExamEntryAvailability::preparationReason($exam)) {
                $this->access->fail($reason, 'El examen no permite descargar paquetes offline.');
            }
            $distribution = ExamOfflineDistribution::where('exam_id', $examId)->firstOrFail();
            $list = ExamOfflineList::where('distribution_id', $distribution->id)->where('responsible_id', $actor->id)->first();
            abort_unless($list, 403, 'No tiene una lista propia. El docente puede supervisar la distribución mediante assignments.');
            if (! hash_equals($list->authorization_hash, $authorization)
                || ! hash_equals($distribution->exam_hash, $this->access->examHash($exam))) {
                $this->access->fail('DISTRIBUTION_STALE', 'El examen o la autorización cambió desde la preparación. La lista requiere revisión.');
            }
            $records = DB::table('exam_offline_members as m')->join('students as s', 's.id', '=', 'm.student_id')
                ->leftJoin('enrollments as en', function ($join) use ($exam) {
                    $join->on('en.student_id', '=', 's.id')->where('en.course_offering_id', $exam->course_offering_id);
                })->leftJoin('exam_eligibilities as el', function ($join) use ($examId) {
                    $join->on('el.student_id', '=', 's.id')->where('el.exam_id', $examId);
                })->leftJoin('exam_entries as ent', function ($join) use ($examId) {
                    $join->on('ent.student_id', '=', 's.id')->where('ent.exam_id', $examId)->where('ent.status', 'VALID');
                })->where('m.list_id', $list->id)->orderBy('m.position')
                ->get(['s.id as student_id', 's.first_names', 's.last_names', 's.sis_code', 's.identity_number',
                    's.status as student_status', 'en.status as enrollment_status', 'el.status as eligibility_status',
                    'el.revision as eligibility_revision', 'ent.id as entry_id']);
            $students = $records->map(fn ($row) => [
                'student_id' => (int) $row->student_id, 'first_names' => $row->first_names, 'last_names' => $row->last_names,
                'sis_code' => $row->sis_code, 'identity_number' => $row->identity_number,
                'student_status' => $row->student_status, 'enrollment_status' => $row->enrollment_status,
                'eligibility_status' => $row->eligibility_status,
                'eligibility_revision' => $row->eligibility_revision === null ? null : (int) $row->eligibility_revision,
                'entry_status' => $row->entry_id === null ? 'PENDING' : 'REGISTERED',
            ])->all();
            $snapshot = ['exam_hash' => $distribution->exam_hash, 'version' => $distribution->version,
                'room_id' => $exam->room_id, 'students' => $students];
            $package = ExamOfflinePackage::create(['id' => (string) Str::uuid(), 'list_id' => $list->id,
                'downloaded_by' => $actor->id, 'prepared_at' => now(), 'expires_at' => ExamEntryAvailability::endsAt($exam),
                'snapshot' => $snapshot]);
            $this->audit->log($request, $actor->id, 'OFFLINE_PACKAGE_PREPARED', 'ExamOfflineList', $list->id, null,
                ['exam_id' => $examId, 'package_id' => $package->id, 'version' => $distribution->version, 'student_count' => count($students)]);

            return ['package_id' => $package->id, 'exam_id' => $examId,
                'exam' => app(EntryControlService::class)->examData($exam),
                'list_id' => $list->id, 'version' => $distribution->version, 'responsible_id' => $actor->id,
                'prepared_at' => $package->prepared_at->toISOString(), 'expires_at' => $package->expires_at->toISOString(),
                'timezone' => ExamEntryAvailability::TIMEZONE, 'room_id' => $exam->room_id,
                'surname_range' => ['from' => $students[0]['last_names'] ?? null, 'to' => $students ? $students[array_key_last($students)]['last_names'] : null],
                'qr_validation' => 'SERVER_ON_SYNC', 'students' => $students];
        }, 3);
    }
}
