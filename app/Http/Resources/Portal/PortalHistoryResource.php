<?php

namespace App\Http\Resources\Portal;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Throwable;

/** Administrative portal history only; the global audit resource is unchanged. */
class PortalHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'actor_id' => $this->user_id,
            'actor_name' => $this->user?->display_name,
            'created_at' => $this->created_at?->toISOString(),
            'old_values' => $this->snapshot($this->old_values),
            'new_values' => $this->snapshot($this->new_values),
        ];
    }

    private function snapshot(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }
        $fields = match ($this->entity_type) {
            'PortalEvent' => ['title', 'description', 'category_id', 'starts_at', 'ends_at', 'all_day', 'location', 'status', 'visibility', 'published_at', 'revision'],
            'PortalNews' => ['title', 'summary', 'content', 'status', 'published_at', 'published_by', 'revision'],
            'PortalInformationContent' => ['kind', 'key', 'is_published', 'published_at', 'revision'],
            'PortalService' => ['name', 'description', 'category_id', 'icon_key', 'destination_type', 'destination', 'requires_auth', 'sort_order', 'status', 'revision'],
            default => [],
        };
        $result = Arr::only($values, $fields);
        if ($this->entity_type === 'PortalService') {
            // Translate both historical storage names and current API names without altering audit rows.
            foreach (['icon_key' => 'icon', 'destination' => 'url', 'sort_order' => 'display_order'] as $old => $current) {
                if (array_key_exists($old, $result)) {
                    $result[$current] = $result[$old];
                }
            }
            unset($result['category_id'], $result['icon_key'], $result['destination'], $result['sort_order'], $result['destination_type'], $result['requires_auth']);
        }
        if ($this->entity_type === 'PortalNews') {
            // History describes attachment changes without exposing private paths
            // or promising access to files removed by the agreed replacement flow.
            $result['has_image'] = ! empty($values['image_path']);
        }
        if ($this->entity_type === 'PortalInformationContent') {
            foreach (['draft', 'published'] as $field) {
                $payload = $values[$field] ?? null;
                if (is_string($payload)) {
                    $payload = json_decode($payload, true);
                }
                $allowed = ($values['kind'] ?? null) === 'SECTION'
                    ? ['title', 'description', 'links', 'sort_order'] : ['question', 'answer', 'sort_order'];
                $result[$field] = is_array($payload) ? Arr::only($payload, $allowed) : null;
                if (is_array($result[$field]['links'] ?? null)) {
                    $result[$field]['links'] = array_values(array_map(fn ($link) => Arr::only($link, ['label', 'url']), array_filter($result[$field]['links'], 'is_array')));
                }
            }
        }
        foreach (['all_day', 'is_published', 'requires_auth'] as $field) {
            if (array_key_exists($field, $result)) {
                $result[$field] = filter_var($result[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['starts_at', 'ends_at', 'published_at'] as $field) {
            if (isset($result[$field])) {
                try {
                    $result[$field] = CarbonImmutable::parse($result[$field], 'UTC')->setTimezone('America/La_Paz')->format('Y-m-d\TH:i:sP');
                } catch (Throwable) {
                    $result[$field] = null;
                }
            }
        }

        return $result;
    }
}
