<?php

namespace App\Services\Discipline;

use App\Models\{DisciplineCase, Student, User};
use App\Http\Resources\Expulsions\ExpulsionResource;
use App\Services\Incidents\IncidentVocabulary;
use BackedEnum;
use Illuminate\Http\Request;

class DisciplinePresenter
{
    public function present(DisciplineCase $case, Request $request, array $permissions): array
    {
        $source = $case->source();
        abort_unless($source, 404);
        $student = $case->studentId() ? Student::find($case->studentId()) : null;
        $measure = $case->measure()->withContext()->first();
        $qr = $case->source_type === 'QR';
        $legacy = $case->source_type === 'LEGACY_EXPULSION';
        $type = $source->type instanceof BackedEnum ? $source->type->value : $source->type;
        $actor = $qr ? $source->user_id : ($source->reported_by ?? $source->expelled_by);

        return ['id' => $case->id, 'exam_id' => $case->exam_id,
            'kind' => $case->source_type === 'INFRACTION' ? 'INFRACTION' : ($legacy ? 'LEGACY_EXPULSION' : 'INCIDENT'),
            'source_type' => $case->source_type, 'source_id' => $case->source_id,
            'type' => $qr ? 'WRONG_EXAM' : ($legacy ? 'EXPULSION' : IncidentVocabulary::type($type)),
            'description' => $qr ? 'Intento de ingreso rechazado: QR de otro examen.' : ($source->description ?? $source->reason),
            'observations' => $case->observations ?? $source->observations,
            'status' => $qr ? 'REGISTERED' : IncidentVocabulary::status($source->status ?? 'REPORTED'),
            'student' => $student?->only(['id', 'first_names', 'last_names', 'sis_code', 'identity_number']),
            'reported_by' => $actor ? ['id' => (int) $actor, 'name' => User::find($actor)?->display_name] : null,
            'occurred_at' => $case->occurred_at->toISOString(),
            'measure_applied' => $measure !== null,
            'measure' => $measure && $permissions['can_manage'] ? (new ExpulsionResource($measure))->resolve($request) : null,
            'permissions' => ['can_expel' => $permissions['can_expel'] && $student !== null && $measure === null,
                'can_update' => $permissions['can_manage'] && $case->source_type === 'INFRACTION']];
    }
}
