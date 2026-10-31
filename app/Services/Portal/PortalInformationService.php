<?php

namespace App\Services\Portal;

use App\Models\PortalInformationContent;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PortalInformationService
{
    public function publicContent(): array
    {
        $records = PortalInformationContent::where('is_published', true)->get()
            ->sortBy(fn ($item) => [$item->published['sort_order'], $item->id]);
        $result = ['sections' => [], 'faqs' => []];
        foreach ($records as $item) {
            $value = ['id' => $item->id];
            if ($item->kind === 'SECTION') {
                $value['key'] = $item->key;
            }
            $result[$item->kind === 'SECTION' ? 'sections' : 'faqs'][] = $value + $item->published;
        }

        return $result;
    }

    public function paginate(string $kind, array $filters): LengthAwarePaginator
    {
        $query = PortalInformationContent::where('kind', $kind);
        $postgres = DB::connection()->getDriverName() === 'pgsql';
        if (isset($filters['is_published'])) {
            $query->where('is_published', (bool) $filters['is_published']);
        }
        if (($filters['search'] ?? '') !== '') {
            $fields = $kind === 'SECTION' ? ['title', 'description'] : ['question', 'answer'];
            $query->where(function ($query) use ($fields, $filters, $postgres) {
                foreach ($fields as $field) {
                    $expression = $postgres ? "strpos(LOWER(draft->>'{$field}'), ?) > 0" : "instr(LOWER(json_extract(draft, '$.{$field}')), ?) > 0";
                    $query->orWhereRaw($expression, [mb_strtolower($filters['search'])]);
                }
            });
        }
        $order = $postgres ? "CAST(draft->>'sort_order' AS INTEGER)" : "CAST(json_extract(draft, '$.sort_order') AS INTEGER)";

        return $query->orderByRaw($order)->orderBy('id')->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function find(string $kind, int $id): PortalInformationContent
    {
        return PortalInformationContent::where('kind', $kind)->findOrFail($id);
    }

    public function save(string $kind, array $data, Request $request, ?int $id = null): PortalInformationContent
    {
        return DB::transaction(function () use ($kind, $data, $request, $id) {
            $item = $id === null ? new PortalInformationContent : PortalInformationContent::where('kind', $kind)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($id !== null) {
                $this->checkRevision($item, $data['revision']);
            }
            $before = $item->exists ? $item->getAttributes() : null;
            unset($data['revision']);
            if (isset($data['sort_order'])) {
                $data['sort_order'] = (int) $data['sort_order'];
            }
            if ($id === null) {
                $item->kind = $kind;
                $item->draft = ['question' => $data['question'], 'answer' => $data['answer'], 'sort_order' => $data['sort_order'] ?? 0];
                $item->revision = 1;
                $item->created_by = $request->user()->id;
            } else {
                $item->draft = array_replace($item->draft, $data);
                $item->revision++;
            }
            $item->updated_by = $request->user()->id;
            $item->save();
            $this->audit($item, $request, $id === null ? 'PORTAL_INFORMATION_CREATED' : 'PORTAL_INFORMATION_DRAFT_UPDATED', $before);

            return $item->refresh();
        });
    }

    public function publish(string $kind, int $id, int $revision, bool $publish, Request $request): PortalInformationContent
    {
        return DB::transaction(function () use ($kind, $id, $revision, $publish, $request) {
            $item = PortalInformationContent::where('kind', $kind)->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($item, $revision);
            $before = $item->getAttributes();
            if ($publish) {
                $required = $kind === 'SECTION' ? ['title', 'description'] : ['question', 'answer'];
                Validator::make($item->draft, array_fill_keys($required, ['required', 'string']))->validate();
                $item->published = $item->draft;
                $item->published_at = now();
            }
            $item->is_published = $publish;
            $item->revision++;
            $item->updated_by = $request->user()->id;
            $item->save();
            $this->audit($item, $request, $publish ? 'PORTAL_INFORMATION_PUBLISHED' : 'PORTAL_INFORMATION_UNPUBLISHED', $before);

            return $item->refresh();
        });
    }

    private function checkRevision(PortalInformationContent $item, int $revision): void
    {
        if ($item->revision !== $revision) {
            throw new HttpResponseException(response()->json([
                'code' => 'REVISION_CONFLICT',
                'message' => 'El contenido fue modificado. Recarga su información antes de continuar.',
                'current_revision' => $item->revision,
            ], 409));
        }
    }

    private function audit(PortalInformationContent $item, Request $request, string $action, ?array $before): void
    {
        app(AuditLogService::class)->log($request, $request->user()->id, $action, 'PortalInformationContent', $item->id, $before, $item->getAttributes());
    }
}
