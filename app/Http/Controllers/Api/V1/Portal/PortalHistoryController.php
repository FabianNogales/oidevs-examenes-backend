<?php

namespace App\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\Portal\PortalHistoryResource;
use App\Models\AuditLog;
use App\Models\PortalEvent;
use App\Models\PortalInformationContent;
use App\Models\PortalNews;
use App\Models\PortalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PortalHistoryController extends Controller
{
    public function events(Request $request, int $event): AnonymousResourceCollection
    {
        PortalEvent::findOrFail($event);

        return $this->history($request, 'PortalEvent', $event);
    }

    public function news(Request $request, int $news): AnonymousResourceCollection
    {
        PortalNews::findOrFail($news);

        return $this->history($request, 'PortalNews', $news);
    }

    public function services(Request $request, int $service): AnonymousResourceCollection
    {
        PortalService::findOrFail($service);

        return $this->history($request, 'PortalService', $service);
    }

    public function information(Request $request, int $content): AnonymousResourceCollection
    {
        $kind = $request->routeIs('portal.admin.information.faqs.*') ? 'FAQ' : 'SECTION';
        PortalInformationContent::where('kind', $kind)->findOrFail($content);

        return $this->history($request, 'PortalInformationContent', $content);
    }

    private function history(Request $request, string $type, int $id): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return PortalHistoryResource::collection(AuditLog::with(['user.teacher', 'user.student'])
            ->where('entity_type', $type)->where('entity_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString());
    }
}
