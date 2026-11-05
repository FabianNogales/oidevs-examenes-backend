<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeRasterImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() > 5242880) {
            $fail('Debe seleccionar una imagen válida.');

            return;
        }
        $info = @getimagesize($value->getRealPath());
        if (! $info || ! in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info['mime'] !== $value->getMimeType()
            || $info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000
            || $info[0] * $info[1] > 16000000
            || ! $this->completeContainer(file_get_contents($value->getRealPath()), $info['mime'])) {
            $fail('La imagen debe ser JPEG, PNG o WebP, de hasta 8000 píxeles por lado y 16 megapíxeles.');
        }
    }

    private function completeContainer(string $bytes, string $mime): bool
    {
        if ($mime === 'image/jpeg') {
            return str_starts_with($bytes, "\xff\xd8\xff") && str_ends_with($bytes, "\xff\xd9");
        }
        if ($mime === 'image/webp') {
            return strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP' && unpack('V', substr($bytes, 4, 4))[1] + 8 === strlen($bytes);
        }
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return false;
        }
        $offset = 8;
        $hasData = false;
        while ($offset + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $offset, 4))[1];
            if ($offset + 12 + $length > strlen($bytes)) {
                return false;
            }
            $typeAndData = substr($bytes, $offset + 4, $length + 4);
            if (hash('crc32b', $typeAndData, true) !== substr($bytes, $offset + 8 + $length, 4)) {
                return false;
            }
            $type = substr($typeAndData, 0, 4);
            $hasData = $hasData || $type === 'IDAT';
            $offset += $length + 12;
            if ($type === 'IEND') {
                return $length === 0 && $hasData && $offset === strlen($bytes);
            }
        }

        return false;
    }
}
