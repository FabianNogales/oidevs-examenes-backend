<?php

namespace App\Services\Portal;

use App\Models\PortalService;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalServiceCatalog
{
    private function query(bool $public): Builder
    {
        $query = PortalService::with('category');
        if ($public) {
            $paths = array_column(app(PortalServiceOptions::class)->options()['internal_destinations'], 'path');
            $query->where('status', 'ACTIVE')->whereHas('category', fn ($category) => $category->where('is_active', true))
                ->where(function ($query) use ($paths) {
                    $query->where('destination_type', 'EXTERNAL')->orWhere(function ($query) use ($paths) {
                        $query->where('destination_type', 'INTERNAL')->whereIn('destination', $paths);
                    });
                });
        }

        return $query;
    }

    public function paginate(array $filters, bool $public): LengthAwarePaginator
    {
        $query = $this->query($public);
        if (! $public && isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (($filters['search'] ?? '') !== '') {
            $query->where(function ($query) use ($filters) {
                $expression = DB::connection()->getDriverName() === 'pgsql' ? 'strpos(LOWER(%s), ?) > 0' : 'instr(LOWER(%s), ?) > 0';
                foreach (['name', 'description'] as $column) {
                    $query->orWhereRaw(sprintf($expression, $column), [mb_strtolower($filters['search'])]);
                }
            });
        }

        return $query->orderBy('sort_order')->orderBy('id')->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function find(int $id, bool $public = false): PortalService
    {
        return $this->query($public)->findOrFail($id);
    }

    public function save(array $data, Request $request, ?int $id = null): PortalService
    {
        return DB::transaction(function () use ($data, $request, $id) {
            $item = $id === null ? new PortalService : PortalService::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($id !== null) {
                $this->checkRevision($item, $data['revision']);
            }
            $before = $item->exists ? $item->getAttributes() : null;
            unset($data['revision']);
            if ($id === null) {
                $data += ['sort_order' => 0];
                if (! array_key_exists('requires_auth', $data)) {
                    $option = collect(app(PortalServiceOptions::class)->options()['internal_destinations'])->firstWhere('path', $data['destination']);
                    $data['requires_auth'] = $data['destination_type'] === 'INTERNAL' ? ($option['requires_auth'] ?? false) : false;
                }
            }
            $item->fill($data);
            $this->validate($item);
            if ($id === null) {
                $item->status = 'INACTIVE';
                $item->revision = 1;
                $item->created_by = $request->user()->id;
            } else {
                $item->revision++;
            }
            $item->updated_by = $request->user()->id;
            $item->save();
            $this->audit($item, $request, $id === null ? 'PORTAL_SERVICE_CREATED' : 'PORTAL_SERVICE_UPDATED', $before);

            return $item->refresh()->load('category');
        });
    }

    public function changeStatus(int $id, array $data, Request $request): PortalService
    {
        return DB::transaction(function () use ($id, $data, $request) {
            $item = PortalService::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($item, $data['revision']);
            if ($data['status'] === 'ACTIVE') {
                $this->validate($item);
            }
            $before = $item->getAttributes();
            $item->status = $data['status'];
            $item->revision++;
            $item->updated_by = $request->user()->id;
            $item->save();
            $this->audit($item, $request, 'PORTAL_SERVICE_STATUS_CHANGED', $before);

            return $item->refresh()->load('category');
        });
    }

    private function validate(PortalService $item): void
    {
        app(PortalServiceOptions::class)->validate($item->only(['icon_key', 'category_id', 'destination_type', 'destination', 'requires_auth']));
    }

    private function checkRevision(PortalService $item, int $revision): void
    {
        if ($item->revision !== $revision) {
            throw new HttpResponseException(response()->json([
                'code' => 'REVISION_CONFLICT',
                'message' => 'El servicio fue modificado. Recarga su información antes de continuar.',
                'current_revision' => $item->revision,
            ], 409));
        }
    }

    private function audit(PortalService $item, Request $request, string $action, ?array $before): void
    {
        app(AuditLogService::class)->log($request, $request->user()->id, $action, 'PortalService', $item->id, $before, $item->getAttributes());
    }
}
