<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Rules\PortalServicePlainText;
use App\Services\Eligibility\Automation\AutomationSupport;
use App\Services\Eligibility\Automation\CriteriaCatalog;
use App\Services\Eligibility\Automation\GlobalCriteriaCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EligibilityCriterionController extends Controller
{
    public function __construct(private GlobalCriteriaCatalog $catalog, private AutomationSupport $support) {}

    private function query()
    {
        return DB::table('eligibility_criteria')->whereIn('code', array_keys(CriteriaCatalog::LABELS));
    }

    public function index(Request $request)
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:200'], 'status' => ['sometimes', 'in:ACTIVE,INACTIVE']]);
        $query = $this->query()->orderBy('code');
        if ($request->filled('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($request->string('search'))).'%']);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        $page = $this->support->page($request, $query);
        $page['data'] = array_map(fn ($row) => $this->catalog->data($row), $page['data']);
        $page['catalog_revision'] = $this->catalog->revision();

        return $this->support->response($page);
    }

    public function show(string $code)
    {
        return $this->support->response(['data' => $this->catalog->data($this->query()->where('code', $code)->firstOrFail())]);
    }

    public function update(Request $request, string $code)
    {
        return $this->save($request, $code, false);
    }

    public function status(Request $request, string $code)
    {
        return $this->save($request, $code, true);
    }

    private function save(Request $request, string $code, bool $status)
    {
        $rules = ['revision' => ['required', 'integer', 'min:1']];
        $rules += $status ? ['status' => ['required', 'in:ACTIVE,INACTIVE']] : [
            'name' => ['sometimes', 'required', 'string', 'max:200', new PortalServicePlainText],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000', new PortalServicePlainText],
        ];
        $input = $request->validate($rules);
        abort_if(array_diff(array_keys($request->all()), array_keys($rules)), 422, 'Solo se permiten los campos editables del criterio.');
        $data = DB::transaction(function () use ($request, $code, $input, $status) {
            // Same lock order as configuration/preview/confirmation: catalog first, then exam.
            $this->catalog->rows(true);
            $row = $this->query()->where('code', $code)->firstOrFail();
            if ((int) $row->revision !== (int) $input['revision']) {
                $this->support->conflict('El criterio cambió. Recarga el catálogo.');
            }
            $old = $this->catalog->data($row);
            $changes = array_diff_assoc(array_diff_key($input, ['revision' => true]), (array) $row);
            if ($changes) {
                $this->query()->where('id', $row->id)->update($changes + ['revision' => $row->revision + 1, 'updated_at' => now()]);
                $row = $this->query()->where('id', $row->id)->first();
                $this->support->audit($request, $status ? 'ELIGIBILITY_CRITERION_STATUS_CHANGED' : 'ELIGIBILITY_CRITERION_UPDATED',
                    'EligibilityCriterion', $row->id, $old, $this->catalog->data($row));
            }

            return $this->catalog->data($row);
        });

        return $this->support->response(['data' => $data, 'catalog_revision' => $this->catalog->revision()]);
    }

    public function history(Request $request, string $code)
    {
        $row = $this->query()->where('code', $code)->firstOrFail();

        return $this->support->response($this->support->page($request,
            AuditLog::where('entity_type', 'EligibilityCriterion')->where('entity_id', $row->id)->orderByDesc('id')));
    }
}
