<?php

namespace App\Services\Portal;

use App\Models\PortalService;
use App\Rules\InstitutionalServiceUrl;
use App\Rules\PortalServicePlainText;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PortalServiceCatalog
{
    private function query(bool $public): Builder
    {
        $query = PortalService::query();
        if ($public) {
            // Apply the same current allowlist to old rows and new rows, before pagination.
            // No remote HTTP/DNS requests: EIDA only supplies validated links.
            $ids = PortalService::where('status', 'ACTIVE')->where('destination_type', 'EXTERNAL')
                ->get(['id', 'name', 'description', 'icon_key', 'destination_type', 'destination'])
                ->filter(fn ($item) => $this->issues($item) === [])->modelKeys();
            $query->where('status', 'ACTIVE')->where('destination_type', 'EXTERNAL')->whereIn('id', $ids);
        }

        return $query;
    }

    public function paginate(array $filters, bool $public): LengthAwarePaginator
    {
        $query = $this->query($public);
        if (! $public && isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['search'] ?? '') !== '') {
            $expression = DB::connection()->getDriverName() === 'pgsql' ? 'strpos(LOWER(name), ?) > 0' : 'instr(LOWER(name), ?) > 0';
            $query->whereRaw($expression, [mb_strtolower($filters['search'])]);
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
            $mapped = [];
            foreach (['name' => 'name', 'description' => 'description', 'icon' => 'icon_key', 'url' => 'destination', 'display_order' => 'sort_order'] as $api => $column) {
                if (array_key_exists($api, $data)) {
                    $mapped[$column] = $data[$api];
                }
            }
            if ($id === null) {
                $mapped += ['sort_order' => 0, 'category_id' => null];
            }
            $item->fill($mapped + ['destination_type' => 'EXTERNAL', 'requires_auth' => false]);
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

            return $item->refresh();
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

            return $item->refresh();
        });
    }

    private function validate(PortalService $item): void
    {
        Validator::make($this->validationValues($item), $this->validationRules())->validate();
    }

    public function issues(PortalService $item): array
    {
        return array_keys(Validator::make($this->validationValues($item), $this->validationRules())->errors()->toArray());
    }

    private function validationValues(PortalService $item): array
    {
        return ['name' => $item->name, 'description' => $item->description, 'icon' => $item->icon_key,
            'url' => $item->destination_type === 'EXTERNAL' ? $item->destination : null];
    }

    private function validationRules(): array
    {
        return ['name' => ['required', 'string', 'max:200', new PortalServicePlainText],
            'description' => ['required', 'string', 'max:2000', new PortalServicePlainText],
            'icon' => ['required', Rule::in(config('portal_services.icon_keys', []))],
            'url' => ['required', new InstitutionalServiceUrl]];
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
