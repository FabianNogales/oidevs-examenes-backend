<?php

namespace App\Services\Incidents;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

class IncidentFilters
{
    public function apply(Builder $query, array $filters, bool $publicVocabulary = false): Builder
    {
        foreach (['type', 'status', 'origin', 'student_id'] as $key) {
            if (isset($filters[$key])) {
                $value = (string) $filters[$key];
                if ($key === 'status' && $publicVocabulary) {
                    $query->whereRaw(IncidentVocabulary::statusSql().' = ?', [$value]);
                } else {
                    $value = match ($key) {
                        'type' => $value === 'WRONG_EXAM' ? 'WRONG_EXAM_QR' : $value,
                        'origin' => $value === 'AUTOMATIC' ? 'AUTOMATIC_QR' : $value,
                        default => $value,
                    };
                    $query->where('incidents.'.$key, $value);
                }
            }
        }
        foreach (['date', 'date_from', 'date_to'] as $key) {
            if (! isset($filters[$key])) {
                continue;
            }
            $day = CarbonImmutable::parse($filters[$key], 'America/La_Paz')->startOfDay();
            if ($key !== 'date_to') {
                $query->where('incidents.created_at', '>=', $day->setTimezone(config('app.timezone'))->toDateTimeString());
            }
            if ($key !== 'date_from') {
                $query->where('incidents.created_at', '<', $day->addDay()->setTimezone(config('app.timezone'))->toDateTimeString());
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $words = preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY);
            $literal = fn (string $text) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
            $query->where(function (Builder $match) use ($search, $words, $literal, $publicVocabulary) {
                $match->whereRaw("LOWER(COALESCE(involved.sis_code, '')) LIKE ? ESCAPE '!'", [$literal(mb_strtolower($search))])
                    ->orWhereRaw("LOWER(COALESCE(involved.identity_number, '')) LIKE ? ESCAPE '!'", [$literal(mb_strtolower($search))])
                    ->orWhere(function (Builder $names) use ($words, $literal) {
                        foreach ($words as $word) {
                            $names->whereRaw("LOWER(COALESCE(involved.first_names, '') || ' ' || COALESCE(involved.last_names, '')) LIKE ? ESCAPE '!'", [$literal($word)]);
                        }
                    });
                if (preg_match('/^#?(qr-)?([1-9][0-9]*)$/', $search, $id)) {
                    $automatic = $id[1] === 'qr-';
                    $sourceId = $id[2];
                    if ($publicVocabulary && ! $automatic && filter_var($sourceId, FILTER_VALIDATE_INT) !== false
                        && (int) $sourceId > IncidentIdentity::AUTOMATIC_OFFSET) {
                        $automatic = true;
                        $sourceId = (string) ((int) $sourceId - IncidentIdentity::AUTOMATIC_OFFSET);
                    }
                    $match->orWhere(function (Builder $identifier) use ($sourceId, $automatic) {
                        $identifier->whereRaw('CAST(incidents.source_id AS TEXT) = ?', [$sourceId])
                            ->where('incidents.origin', $automatic ? 'AUTOMATIC_QR' : 'MANUAL');
                    });
                }
            });
        }

        return $query;
    }
}
