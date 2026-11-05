<?php

namespace App\Services\Images;

use App\Rules\SafeRasterImage;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseImageService
{
    /** Call inside the owner's transaction; no filesystem persistence. */
    public function store(UploadedFile $file, string $purpose, int $ownerId, ?int $actorId): string
    {
        if (! in_array($purpose, ['profile', 'historical', 'news'], true) || DB::transactionLevel() === 0) {
            throw new RuntimeException('Image writes require an owner transaction.');
        }
        $field = $purpose === 'news' ? 'image' : 'photo';
        $limit = $purpose === 'profile' ? 2048 : 5120;
        Validator::make([$field => $file], [$field => ['required', 'file', 'max:'.$limit, new SafeRasterImage]])->validate();
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false || strlen($bytes) > $limit * 1024) {
            throw ValidationException::withMessages([$field => 'La imagen supera el tamaño permitido.']);
        }
        $info = getimagesizefromstring($bytes);
        // GD is optional. When available, fully decode, strip metadata and resize locally.
        if (function_exists('imagecreatefromstring')) {
            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                throw ValidationException::withMessages([$field => 'El contenido de la imagen está dañado.']);
            }
            $scale = min(1, 2048 / max($info[0], $info[1]));
            $target = imagecreatetruecolor(max(1, (int) round($info[0] * $scale)), max(1, (int) round($info[1] * $scale)));
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $info[0], $info[1]);
            ob_start();
            $encoded = match ($info['mime']) {
                'image/jpeg' => imagejpeg($target, null, 85),
                'image/png' => imagepng($target, null, 8),
                'image/webp' => function_exists('imagewebp') && imagewebp($target, null, 85),
            };
            $optimized = ob_get_clean();
            imagedestroy($target);
            imagedestroy($source);
            if ($encoded && $optimized !== '') {
                $bytes = $optimized;
                $info = getimagesizefromstring($bytes);
            }
        }
        if (strlen($bytes) > $limit * 1024) {
            throw ValidationException::withMessages([$field => 'La imagen procesada supera el tamaño permitido.']);
        }
        $id = (string) Str::uuid();
        $metadata = [
            'id' => $id, 'purpose' => $purpose,
            'student_id' => $purpose === 'news' ? null : $ownerId,
            'news_id' => $purpose === 'news' ? $ownerId : null,
            'mime_type' => $info['mime'], 'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'width' => $info[0], 'height' => $info[1],
            'uploaded_at' => now(), 'uploaded_by' => $actorId,
        ];
        if (DB::getDriverName() === 'pgsql') {
            // Parameterized hex -> BYTEA avoids PDO treating NUL bytes as text.
            $metadata['content'] = bin2hex($bytes);
            $columns = implode(', ', array_keys($metadata));
            $placeholders = implode(', ', array_map(fn ($key) => $key === 'content' ? "decode(?, 'hex')" : '?', array_keys($metadata)));
            DB::insert("INSERT INTO stored_images ($columns) VALUES ($placeholders)", array_values($metadata));
        } else {
            // Binary LOB binding is also required for isolated SQLite tests (non UTF-8 bytes).
            $stream = fopen('php://memory', 'r+');
            try {
                fwrite($stream, $bytes);
                rewind($stream);
                DB::table('stored_images')->insert([...$metadata, 'content' => $stream]);
            } finally {
                fclose($stream);
            }
        }

        return $id;
    }

    private function owned(string $id, string $purpose, int $ownerId): Builder
    {
        return DB::table('stored_images')->where('id', $id)->where('purpose', $purpose)
            ->where($purpose === 'news' ? 'news_id' : 'student_id', $ownerId);
    }

    public function discard(?string $id, string $purpose, int $ownerId): void
    {
        if ($id) {
            $this->owned($id, $purpose, $ownerId)->delete();
        }
    }

    /** Authorization belongs to the existing module controller/middleware. */
    public function response(?string $id, string $purpose, int $ownerId, ?string $legacyPath = null): StreamedResponse
    {
        if ($id) {
            $record = $this->owned($id, $purpose, $ownerId)->first();
            abort_unless($record, 404);
            $bytes = is_resource($record->content) ? stream_get_contents($record->content) : $record->content;
            if (strlen($bytes) !== (int) $record->byte_size || ! hash_equals($record->sha256, hash('sha256', $bytes))) {
                throw new RuntimeException('Stored image integrity check failed.');
            }
            $mime = $record->mime_type;
            $hash = $record->sha256;
        } else {
            // Read-only compatibility for old local references. Never fetch remote URLs.
            $prefixes = match ($purpose) {
                'profile' => ['avatars/', 'profile-photos/'], 'historical' => ['institutional-photos/'], 'news' => ['portal-news/']
            };
            abort_unless($legacyPath && Str::startsWith($legacyPath, $prefixes)
                && ! str_contains($legacyPath, '..') && ! str_contains($legacyPath, '\\'), 404);
            $disk = Storage::disk($purpose === 'profile' ? 'public' : 'local');
            abort_unless($disk->exists($legacyPath), 404);
            abort_if($disk->size($legacyPath) > 5242880, 404);
            $bytes = $disk->get($legacyPath);
            $info = @getimagesizefromstring($bytes);
            abort_unless($info && in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true), 404);
            $mime = $info['mime'];
            $hash = hash('sha256', $bytes);
        }

        return response()->stream(static function () use ($bytes) {
            echo $bytes;
        }, 200, [
            'Content-Type' => $mime, 'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="image.'.match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'
            }.'"',
            // Re-check news publication on every request, including after unpublishing.
            'Cache-Control' => $purpose === 'news' && request()->routeIs('portal.public.*') ? 'no-store' : 'private, no-store',
            'ETag' => '"'.$hash.'"', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Vary' => 'Origin, Cookie, Authorization',
        ]);
    }
}
