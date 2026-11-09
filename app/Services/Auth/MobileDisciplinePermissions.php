<?php

namespace App\Services\Auth;

/** Proposed exact allowlist for review. NOT wired into the central token policy. */
class MobileDisciplinePermissions
{
    public const ABILITIES = ['incidents:read', 'incidents:report', 'discipline:manage'];

    public function requiredAbility(string $operation): ?string
    {
        return match ($operation) {
            'GET api/v1/exams/{exam_id}/discipline-context',
            'GET api/v1/exams/{exam_id}/incidents',
            'GET api/v1/exams/{exam_id}/incidents/{incident_id}' => 'incidents:read',
            'POST api/v1/exams/{exam_id}/offline/incidents' => 'incidents:report',
            'GET api/v1/exams/{exam_id}/infractions', 'GET api/v1/exams/{exam_id}/infractions/options',
            'GET api/v1/exams/{exam_id}/infractions/students', 'GET api/v1/exams/{exam_id}/infractions/{infraction_id}',
            'POST api/v1/exams/{exam_id}/infractions', 'PATCH api/v1/exams/{exam_id}/infractions/{infraction_id}/status',
            'GET api/v1/exams/{exam_id}/expulsions', 'GET api/v1/exams/{exam_id}/expulsions/students',
            'GET api/v1/exams/{exam_id}/expulsions/eligible-infractions', 'POST api/v1/exams/{exam_id}/expulsions',
            'GET api/v1/exams/{exam_id}/entry-control/entries',
            'POST api/v1/exams/{exam_id}/entry-control/entries/{entry_id}/annulment' => 'discipline:manage',
            default => null,
        };
    }
}
