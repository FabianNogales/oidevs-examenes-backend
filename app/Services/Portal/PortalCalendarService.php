<?php

namespace App\Services\Portal;

use App\Models\PortalEvent;
use App\Services\Audit\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalCalendarService
{
    public function paginate(array $filters, bool $public): LengthAwarePaginator
    {
        $query = PortalEvent::with('category');
        if ($public) {
            $query->where('status', 'PUBLISHED');
        } elseif (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (($filters['search'] ?? '') !== '') {
            // Literal search: SQL wildcards supplied by visitors are not patterns.
            $term = mb_strtolower($filters['search']);
            $query->where(function ($query) use ($term) {
                $driver = DB::connection()->getDriverName();
                $expression = $driver === 'pgsql' ? 'strpos(LOWER(%s), ?) > 0' : 'instr(LOWER(%s), ?) > 0';
                foreach (['title', 'description', 'location'] as $column) {
                    $query->orWhereRaw(sprintf($expression, $column), [$term]);
                }
            });
        }
        if (isset($filters['from'], $filters['to'])) {
            $start = CarbonImmutable::parse($filters['from'], 'America/La_Paz')->startOfDay()->utc();
            $end = CarbonImmutable::parse($filters['to'], 'America/La_Paz')->addDay()->startOfDay()->utc();
            $format = DB::connection()->getDriverName() === 'pgsql' ? 'Y-m-d H:i:sP' : 'Y-m-d H:i:s';
            $query->where('starts_at', '<', $end->format($format))->where('ends_at', '>', $start->format($format));
        }

        return $query->orderBy('starts_at')->orderBy('id')->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function find(int $id, bool $public = false): PortalEvent
    {
        return PortalEvent::with('category')->when($public, fn ($query) => $query->where('status', 'PUBLISHED'))->findOrFail($id);
    }

    public function save(array $data, Request $request, ?int $id = null): PortalEvent
    {
        return DB::transaction(function () use ($data, $request, $id) {
            $event = $id === null ? new PortalEvent : PortalEvent::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($id !== null) {
                $this->checkRevision($event, $data['revision']);
            }
            $before = $event->exists ? $event->getAttributes() : null;
            unset($data['revision']);
            foreach (['starts_at', 'ends_at'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = CarbonImmutable::parse($data[$field])->utc();
                }
            }
            $event->fill($data);
            $this->validateInterval($event);
            if ($id === null) {
                $event->created_by = $request->user()->id;
                $event->status = 'DRAFT';
                $event->revision = 1;
            } else {
                $event->revision++;
            }
            $event->updated_by = $request->user()->id;
            $event->save();
            $this->audit($event, $request, $id === null ? 'PORTAL_EVENT_CREATED' : 'PORTAL_EVENT_UPDATED', $before);

            return $event->refresh()->load('category');
        });
    }

    public function changeStatus(int $id, array $data, Request $request): PortalEvent
    {
        return DB::transaction(function () use ($id, $data, $request) {
            $event = PortalEvent::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($event, $data['revision']);
            if ($data['status'] === 'PUBLISHED' && ! $event->category->is_active) {
                throw ValidationException::withMessages(['category_id' => ['La categoría no está activa.']]);
            }
            $before = $event->getAttributes();
            $event->status = $data['status'];
            if ($event->status === 'PUBLISHED' && $event->published_at === null) {
                $event->published_at = now();
            }
            $event->revision++;
            $event->updated_by = $request->user()->id;
            $event->save();
            $this->audit($event, $request, 'PORTAL_EVENT_STATUS_CHANGED', $before);

            return $event->refresh()->load('category');
        });
    }

    private function checkRevision(PortalEvent $event, int $revision): void
    {
        if ($event->revision !== $revision) {
            throw new HttpResponseException(response()->json([
                'code' => 'REVISION_CONFLICT',
                'message' => 'El evento fue modificado. Recarga su información antes de continuar.',
                'current_revision' => $event->revision,
            ], 409));
        }
    }

    private function validateInterval(PortalEvent $event): void
    {
        if ($event->ends_at->lessThanOrEqualTo($event->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => ['La finalización debe ser posterior al inicio.']]);
        }
        if ($event->all_day) {
            foreach (['starts_at', 'ends_at'] as $field) {
                if ($event->$field->setTimezone('America/La_Paz')->format('H:i:s') !== '00:00:00') {
                    throw ValidationException::withMessages([$field => ['Los eventos de día completo deben comenzar y finalizar a medianoche de America/La_Paz; el fin es excluido.']]);
                }
            }
        }
    }

    private function audit(PortalEvent $event, Request $request, string $action, ?array $before): void
    {
        app(AuditLogService::class)->log($request, $request->user()->id, $action, 'PortalEvent', $event->id, $before, $event->getAttributes());
    }
}
