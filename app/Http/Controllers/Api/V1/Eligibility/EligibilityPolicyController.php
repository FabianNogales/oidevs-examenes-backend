<?php

namespace App\Http\Controllers\Api\V1\Eligibility;

use App\Http\Controllers\Controller;
use App\Models\EligibilityPolicy;
use App\Services\Eligibility\Automation\AutomationSupport;
use App\Services\Eligibility\Automation\CriteriaCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EligibilityPolicyController extends Controller
{
    public function __construct(private CriteriaCatalog $catalog, private AutomationSupport $support) {}

    public function index(Request $request)
    {
        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:200'], 'status' => ['sometimes', 'in:DRAFT,ACTIVE']]);
        $query = EligibilityPolicy::query();
        if ($request->filled('search')) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($request->string('search'))).'%']);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return $this->support->response($this->support->page($request, $query->orderByDesc('id')));
    }

    public function show(int $policy)
    {
        return $this->support->response(['data' => EligibilityPolicy::findOrFail($policy)]);
    }

    public function store(Request $request, ?int $policy = null)
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:200'], 'definition' => ['sometimes', 'array']]);
        $definition = $this->catalog->validateDefinition($input['definition'] ?? $this->catalog->definition());
        $result = DB::transaction(function () use ($request, $input, $definition, $policy) {
            $base = $policy ? EligibilityPolicy::findOrFail($policy) : null;
            // Serialize version creation on the original family row, even when cloning a later version.
            if ($base) {
                EligibilityPolicy::where('family', $base->family)->orderBy('id')->lockForUpdate()->get();
            }
            $new = EligibilityPolicy::create(['family' => $base?->family ?? (string) Str::uuid(),
                'version' => $base ? EligibilityPolicy::where('family', $base->family)->max('version') + 1 : 1,
                'name' => $input['name'], 'status' => 'DRAFT', 'definition' => $definition,
                'created_by' => $request->user()->id]);
            $new->refresh();
            $this->support->audit($request, 'ELIGIBILITY_POLICY_CREATED', 'EligibilityPolicy', $new->id, null, $new->toArray());

            return $new;
        });

        return $this->support->response(['data' => $result], 201);
    }

    public function update(Request $request, int $policy)
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'string', 'max:200'], 'definition' => ['sometimes', 'array']]);
        $result = DB::transaction(function () use ($request, $input, $policy) {
            $row = EligibilityPolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if ($row->revision !== (int) $input['revision'] || $row->status !== 'DRAFT') {
                $this->support->conflict('Versión activa inmutable o revisión desactualizada. Crea una nueva versión.');
            }
            $old = $row->toArray();
            if (isset($input['name'])) {
                $row->name = $input['name'];
            }
            if (isset($input['definition'])) {
                $row->definition = $this->catalog->validateDefinition($input['definition']);
            }
            $row->revision++;
            $row->save();
            $this->support->audit($request, 'ELIGIBILITY_POLICY_UPDATED', 'EligibilityPolicy', $row->id, $old, $row->toArray());

            return $row;
        });

        return $this->support->response(['data' => $result]);
    }

    public function activate(Request $request, int $policy)
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $result = DB::transaction(function () use ($request, $input, $policy) {
            $row = EligibilityPolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
            if ($row->revision !== (int) $input['revision'] || $row->status !== 'DRAFT') {
                $this->support->conflict('La política ya fue activada o cambió su revisión.');
            }
            $this->catalog->validateDefinition($row->definition);
            $old = $row->toArray();
            $row->update(['status' => 'ACTIVE', 'revision' => $row->revision + 1, 'activated_at' => now()]);
            $this->support->audit($request, 'ELIGIBILITY_POLICY_ACTIVATED', 'EligibilityPolicy', $row->id, $old, $row->toArray());

            return $row;
        });

        return $this->support->response(['data' => $result]);
    }
}
