<?php

namespace App\Services\Discipline;

use App\Models\{DisciplineCase, Exam};
use App\Enums\RoleName;
use App\Services\Entries\ExamEntryAvailability;
use App\Services\Incidents\{IncidentAccessService, IncidentRegistrationService};
use App\Services\Infractions\InfractionService;
use App\Services\Expulsions\{ExpulsionAccess, ExpulsionRegistrationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisciplineService
{
    public function context(int $examId, Request $request): array
    {
        $exam = Exam::with('courseOffering.teacher', 'courseOffering.subject')->findOrFail($examId);
        $actor = $request->user()->fresh();
        $allowed = app(IncidentAccessService::class)->permissions($exam, $actor);
        $owner = $actor->hasRole(RoleName::DOCENTE) && (int) $exam->courseOffering->teacher->user_id === $actor->id;

        return ['exam' => ['id' => $exam->id, 'name' => $exam->name, 'subject_name' => $exam->courseOffering->subject->name],
            'permissions' => ['can_create' => $allowed['can_create'], 'can_manage' => $owner,
                'can_export' => $allowed['can_export'], 'can_expel' => $owner && ExamEntryAvailability::reason($exam) === null]];
    }

    public function find(int $examId, int $id, Request $request): DisciplineCase
    {
        $context = $this->context($examId, $request);
        $query = DisciplineCase::where('exam_id', $examId);
        if (! $context['permissions']['can_manage']) $query->whereIn('source_type', ['INCIDENT', 'QR']);

        return $query->findOrFail($id);
    }

    public function create(int $examId, array $data, Request $request): DisciplineCase
    {
        return DB::transaction(function () use ($examId, $data, $request) {
            Exam::whereKey($examId)->lockForUpdate()->firstOrFail();
            $context = $this->context($examId, $request);
            abort_unless($context['permissions']['can_create'], 403);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $previous = DisciplineCase::where('request_actor_id', $request->user()->id)->where('request_key', $data['request_key'])->first();
            if ($previous) {
                abort_unless($previous->exam_id === $examId && hash_equals($previous->request_hash, $hash), 409, 'La clave corresponde a otro registro.');
                return $previous;
            }
            $payload = array_intersect_key($data, array_flip(['student_id', 'type', 'description', 'observations']));
            if ($data['kind'] === 'INFRACTION') {
                abort_unless($context['permissions']['can_manage'], 403);
                $source = app(InfractionService::class)->create($examId, $payload, $request);
            } else {
                $payload['type'] = $payload['type'] === 'WRONG_EXAM' ? 'WRONG_EXAM_QR' : $payload['type'];
                $source = app(IncidentRegistrationService::class)->register($examId, $payload, $request);
            }
            $case = DisciplineCase::where('source_type', $data['kind'])->where('source_id', $source->id)->firstOrFail();
            $case->update(['observations' => $data['observations'] ?? null, 'request_actor_id' => $request->user()->id, 'request_key' => $data['request_key'], 'request_hash' => $hash]);
            if ($data['decision'] === 'EXPEL') $this->expel($examId, $case->id, $data['reason'], $request);

            return $case->refresh();
        });
    }

    public function expel(int $examId, int $caseId, string $reason, Request $request): DisciplineCase
    {
        return DB::transaction(function () use ($examId, $caseId, $reason, $request) {
            app(ExpulsionAccess::class)->exam($examId, $request->user(), lock: true);
            $case = DisciplineCase::where('exam_id', $examId)->lockForUpdate()->findOrFail($caseId);
            $existing = $case->measure()->first();
            if ($existing) {
                abort_unless($existing->reason === trim($reason), 409, 'El caso ya tiene una medida con otra justificación.');
                return $case;
            }
            abort_unless($case->studentId(), 422, 'La expulsión requiere un estudiante identificado.');
            app(ExpulsionRegistrationService::class)->register($examId, [
                'student_id' => $case->studentId(), 'discipline_case_id' => $case->id,
                'infraction_id' => $case->source_type === 'INFRACTION' ? $case->source_id : null,
                'reason' => trim($reason),
            ], $request);

            return $case->refresh();
        });
    }
}
