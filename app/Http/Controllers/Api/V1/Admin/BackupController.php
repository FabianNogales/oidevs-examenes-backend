<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Backups\BackupResource;
use App\Models\Backup;
use App\Services\Backups\BackupService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BackupController extends Controller
{
    public function __construct(private BackupService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $this->service->reconcile($request);
        $latest = Backup::where('status', 'AVAILABLE')->orderByDesc('completed_at')->first();

        return BackupResource::collection(Backup::orderByDesc('requested_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString())->additional([
                'summary' => ['total' => Backup::count(),
                    'recorded_bytes' => (int) Backup::where('status', 'AVAILABLE')->sum('bytes'),
                    'last_available_at' => $latest?->completed_at?->toISOString(),
                    'active' => Backup::whereNotNull('active_slot')->exists(), 'enabled' => $this->service->enabled()],
            ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['type' => ['sometimes', 'in:MANUAL_FULL']]);
        try {
            $backup = $this->service->request($request);
        } catch (HttpResponseException $exception) {
            $this->service->record($request, 'BACKUP_REQUEST_REJECTED');
            throw $exception;
        }

        return response()->json(['data' => BackupResource::make($backup)], $backup->status === 'FAILED' ? 503 : 202);
    }

    public function show(Request $request, string $backup): BackupResource
    {
        $this->service->reconcile($request);

        return BackupResource::make(Backup::findOrFail($backup));
    }

    public function download(Request $request, string $backup): JsonResponse
    {
        return response()->json(['data' => $this->service->download($request, Backup::findOrFail($backup))])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function recover(Request $request, string $backup): JsonResponse
    {
        return response()->json(['data' => BackupResource::make($this->service->recover($request, $backup))])
            ->header('Cache-Control', 'private, no-store');
    }
}
