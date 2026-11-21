<?php

namespace App\Services\Enrollments;

final class EnrollmentCsvFormat
{
    /** Read the header once, accepting UTF-8 exports from Excel and either common separator. */
    public static function header($stream): array
    {
        $firstLine = fgets($stream);
        if ($firstLine === false) {
            return [[], ',', false];
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $comma = str_getcsv($firstLine, ',', '"', '');
        $semicolon = str_getcsv($firstLine, ';', '"', '');
        $delimiter = count($semicolon) > count($comma) ? ';' : ',';
        $headers = $delimiter === ';' ? $semicolon : $comma;
        $normalized = array_map(fn ($value) => strtolower(trim((string) $value)), $headers);

        return [$headers, $delimiter, array_search('siscode', $normalized, true)];
    }
}
