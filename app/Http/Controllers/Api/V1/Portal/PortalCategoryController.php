<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Rules\PortalServicePlainText;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalCategoryController extends Controller
{
    private function table(Request $request): string
    {
        return $request->route('catalog') === 'news' ? 'portal_news_categories' : 'portal_event_categories';
    }

    private function entity(Request $request): string
    {
        return $request->route('catalog') === 'news' ? 'PortalNewsCategory' : 'PortalEventCategory';
    }

    private function data(object $row): array
    {
        return ['id' => (int) $row->id, 'name' => $row->name, 'is_active' => (bool) $row->is_active, 'revision' => (int) $row->revision];
    }

    public function selection(Request $request)
    {
        return response()->json(['data' => DB::table($this->table($request))->where('is_active', true)->orderBy('name')->orderBy('id')->get(['id', 'name'])]);
    }

    public function index(Request $request)
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:200'], 'is_active' => ['sometimes', 'in:true,false,1,0'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = DB::table($this->table($request))->orderBy('name')->orderBy('id');
        if ($request->filled('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($request->string('search'))).'%']);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        $page = $query->paginate($request->integer('per_page', 15));

        return response()->json(['data' => array_map($this->data(...), $page->items()), 'meta' => $this->meta($page)]);
    }

    private function meta($page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    public function store(Request $request)
    {
        return $this->save($request, null, false);
    }

    public function update(Request $request)
    {
        return $this->save($request, (int) $request->route('category'), false);
    }

    public function status(Request $request)
    {
        return $this->save($request, (int) $request->route('category'), true);
    }

    private function save(Request $request, ?int $id, bool $status)
    {
        $rules = $status ? ['is_active' => ['required', 'boolean']] : ['name' => ['required', 'string', 'max:100', new PortalServicePlainText]];
        $rules['revision'] = $id === null ? ['prohibited'] : ['required', 'integer', 'min:1'];
        $input = $request->validate($rules);
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['fields' => 'Solo se permiten los campos definidos para esta operación.']);
        }
        $table = $this->table($request);
        try {
            $result = DB::transaction(function () use ($request, $id, $status, $input, $table) {
                $row = $id === null ? null : DB::table($table)->where('id', $id)->lockForUpdate()->firstOrFail();
                if ($row && (int) $row->revision !== (int) $input['revision']) {
                    throw new HttpResponseException(response()->json(['code' => 'REVISION_CONFLICT',
                        'message' => 'La categoría fue modificada. Recarga su información antes de continuar.', 'current_revision' => (int) $row->revision], 409));
                }
                $changes = $status ? ['is_active' => (bool) $input['is_active']] : ['name' => trim($input['name'])];
                // SQLite LOWER is ASCII-only; use the same Unicode comparison as PostgreSQL for validation.
                $duplicate = ! $status && DB::table($table)->when($id, fn ($q) => $q->where('id', '!=', $id))->pluck('name')
                    ->contains(fn ($name) => mb_strtolower(trim($name)) === mb_strtolower($changes['name']));
                if ($duplicate) {
                    throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
                }
                $before = $row ? $this->data($row) : null;
                if ($row && ($status ? (bool) $row->is_active === $changes['is_active'] : $row->name === $changes['name'])) {
                    return $before;
                }
                if ($id === null) {
                    $id = DB::table($table)->insertGetId($changes + ['is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table($table)->where('id', $id)->update($changes + ['revision' => $row->revision + 1, 'updated_at' => now()]);
                }
                $after = $this->data(DB::table($table)->where('id', $id)->first());
                app(AuditLogService::class)->log($request, $request->user()->id,
                    $row === null ? 'PORTAL_CATEGORY_CREATED' : ($status ? 'PORTAL_CATEGORY_STATUS_CHANGED' : 'PORTAL_CATEGORY_UPDATED'),
                    $this->entity($request), $id, $before, $after);

                return $after;
            });
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => 'Ya existe una categoría con ese nombre.']);
            }
            throw $exception;
        }

        return response()->json(['data' => $result], $id === null ? 201 : 200);
    }

    public function history(Request $request)
    {
        $id = (int) $request->route('category');
        DB::table($this->table($request))->where('id', $id)->firstOrFail();
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = AuditLog::with('user')->where('entity_type', $this->entity($request))->where('entity_id', $id)->orderByDesc('id')->paginate($request->integer('per_page', 15));

        return response()->json(['data' => $page->map(fn ($log) => ['id' => $log->id, 'action' => $log->action,
            'entity_type' => $log->entity_type, 'entity_id' => $log->entity_id,
            'actor_id' => $log->user_id, 'actor_name' => $log->user?->display_name,
            'created_at' => $log->created_at->toIso8601String(), 'old_values' => $log->old_values, 'new_values' => $log->new_values]), 'meta' => $this->meta($page)]);
    }
}
