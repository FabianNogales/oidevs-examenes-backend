<?php

namespace App\Services\Portal;

use App\Models\PortalNews;
use App\Services\Audit\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PortalNewsService
{
    public function paginate(array $filters, bool $public): LengthAwarePaginator
    {
        $query = PortalNews::query();
        if ($public) {
            $query->where('status', 'PUBLISHED');
        } elseif (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['search'] ?? '') !== '') {
            $term = mb_strtolower($filters['search']);
            $query->where(function ($query) use ($term) {
                $expression = DB::connection()->getDriverName() === 'pgsql' ? 'strpos(LOWER(%s), ?) > 0' : 'instr(LOWER(%s), ?) > 0';
                foreach (['title', 'summary', 'content'] as $column) {
                    $query->orWhereRaw(sprintf($expression, $column), [$term]);
                }
            });
        }

        return $query->orderByRaw('CASE WHEN published_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function find(int $id, bool $public = false): PortalNews
    {
        return PortalNews::when($public, fn ($query) => $query->where('status', 'PUBLISHED'))->findOrFail($id);
    }

    public function save(array $data, Request $request, ?int $id = null): PortalNews
    {
        return DB::transaction(function () use ($data, $request, $id) {
            $news = $id === null ? new PortalNews : PortalNews::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($id !== null) {
                $this->checkRevision($news, $data['revision']);
            }
            $before = $news->exists ? $news->getAttributes() : null;
            unset($data['revision']);
            $news->fill($data);
            if ($id === null) {
                $news->status = 'DRAFT';
                $news->revision = 1;
                $news->created_by = $request->user()->id;
            } else {
                $news->revision++;
            }
            $news->updated_by = $request->user()->id;
            $news->save();
            $this->audit($news, $request, $id === null ? 'PORTAL_NEWS_CREATED' : 'PORTAL_NEWS_UPDATED', $before);

            return $news->refresh();
        });
    }

    public function changeStatus(int $id, array $data, Request $request): PortalNews
    {
        return DB::transaction(function () use ($id, $data, $request) {
            $news = PortalNews::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->checkRevision($news, $data['revision']);
            $before = $news->getAttributes();
            $news->status = $data['status'];
            if ($news->status === 'PUBLISHED' && $news->published_at === null) {
                $news->published_at = now();
                $news->published_by = $request->user()->id;
            }
            $news->revision++;
            $news->updated_by = $request->user()->id;
            $news->save();
            $this->audit($news, $request, 'PORTAL_NEWS_STATUS_CHANGED', $before);

            return $news->refresh();
        });
    }

    public function changeImage(int $id, int $revision, ?UploadedFile $image, Request $request): PortalNews
    {
        // Private storage: a known file path must not expose draft or archived images.
        $path = null;
        $oldPath = null;
        try {
            $news = DB::transaction(function () use ($id, $revision, $image, $request, &$path, &$oldPath) {
                $news = PortalNews::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->checkRevision($news, $revision);
                if ($image !== null) {
                    $storedPath = $image->store('portal-news', 'local');
                    if (! $storedPath) {
                        throw new RuntimeException('No se pudo guardar la imagen de la noticia.');
                    }
                    $path = $storedPath;
                }
                $before = $news->getAttributes();
                $oldPath = $news->image_path;
                $news->image_path = $path;
                $news->revision++;
                $news->updated_by = $request->user()->id;
                $news->save();
                $this->audit($news, $request, $image === null ? 'PORTAL_NEWS_IMAGE_REMOVED' : 'PORTAL_NEWS_IMAGE_UPDATED', $before);

                return $news->refresh();
            });
        } catch (Throwable $error) {
            $this->discardImage($path);
            throw $error;
        }

        // Delete the previous file only after the database commit. Cleanup failures
        // must never roll back a successful reference or delete the newly saved image.
        DB::afterCommit(fn () => $this->discardImage($oldPath));

        return $news;
    }

    private function discardImage(?string $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            if (! Storage::disk('local')->delete($path)) {
                Log::warning('Portal news image cleanup failed.', ['path' => $path]);
            }
        } catch (Throwable $error) {
            Log::warning('Portal news image cleanup failed.', ['path' => $path, 'error' => $error->getMessage()]);
        }
    }

    private function checkRevision(PortalNews $news, int $revision): void
    {
        if ($news->revision !== $revision) {
            throw new HttpResponseException(response()->json([
                'code' => 'REVISION_CONFLICT',
                'message' => 'La noticia fue modificada. Recarga su información antes de continuar.',
                'current_revision' => $news->revision,
            ], 409));
        }
    }

    private function audit(PortalNews $news, Request $request, string $action, ?array $before): void
    {
        app(AuditLogService::class)->log($request, $request->user()->id, $action, 'PortalNews', $news->id, $before, $news->getAttributes());
    }
}
