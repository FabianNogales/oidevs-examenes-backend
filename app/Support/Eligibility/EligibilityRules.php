<?php

namespace App\Support\Eligibility;

use Illuminate\Validation\Rule;

class EligibilityRules
{
    public const REASONS = [
        'INSTITUTIONAL_REQUIREMENT_PENDING' => 'Requisito institucional pendiente',
        'OTHER' => 'Otro motivo',
    ];

    public static function rules(array $data, bool $allowLegacy = false): array
    {
        $ineligible = ($data['status'] ?? null) === 'INELIGIBLE';
        $hasCode = filled($data['reason_code'] ?? null);
        $legacy = $allowLegacy && filled($data['reason'] ?? null);

        return [
            'status' => ['required', 'string', Rule::in(['ELIGIBLE', 'INELIGIBLE'])],
            'reason_code' => [Rule::requiredIf($ineligible && ! $legacy), 'nullable', 'string', Rule::in(array_keys(self::REASONS))],
            'reason' => [Rule::requiredIf($ineligible && ! $hasCode && $allowLegacy), 'nullable', 'string', 'max:500'],
            'observations' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function values(array $data): array
    {
        if ($data['status'] === 'ELIGIBLE') {
            return ['status' => 'ELIGIBLE', 'reason_code' => null, 'reason' => null, 'observations' => null];
        }

        $code = $data['reason_code'] ?? null;

        return [
            'status' => 'INELIGIBLE',
            'reason_code' => $code,
            'reason' => $code ? self::REASONS[$code] : trim($data['reason']),
            'observations' => filled($data['observations'] ?? null) ? trim($data['observations']) : null,
        ];
    }
}
