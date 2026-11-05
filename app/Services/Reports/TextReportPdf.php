<?php

namespace App\Services\Reports;

/** A paginated text PDF using the standard PDF fonts; no HTML or remote resources. */
class TextReportPdf
{
    public function render(string $title, array $lines): string
    {
        $wrapped = [];
        foreach ($lines as $line) {
            foreach (explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], (string) $line)) as $paragraph) {
                $paragraph = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $paragraph);
                do {
                    $length = mb_strlen($paragraph);
                    $take = min(94, $length);
                    if ($length > 94) {
                        $space = mb_strrpos(mb_substr($paragraph, 0, 94), ' ');
                        if ($space !== false && $space > 45) {
                            $take = $space;
                        }
                    }
                    $wrapped[] = mb_substr($paragraph, 0, $take);
                    $paragraph = ltrim(mb_substr($paragraph, $take));
                } while ($paragraph !== '');
            }
        }
        $pages = array_chunk($wrapped ?: ['Sin resultados.'], 56);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageId = 5 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $stream = "BT /F2 18 Tf 42 800 Td (EIDA) Tj ET\n";
            $stream .= 'BT /F2 12 Tf 42 777 Td ('.$this->escape($title).") Tj ET\n";
            $stream .= "0.75 G 42 765 m 553 765 l S 0 G\nBT /F1 9 Tf 12 TL 42 748 Td\n";
            foreach ($page as $line) {
                // ActualText retains the original Unicode text for extraction/accessibility.
                $actualText = strtoupper(bin2hex("\xFE\xFF".mb_convert_encoding($line, 'UTF-16BE', 'UTF-8')));
                $stream .= '/Span << /ActualText <'.$actualText.'> >> BDC ('.$this->escape($line).") Tj EMC T*\n";
            }
            $stream .= "ET\nBT /F1 8 Tf 42 30 Td (".$this->escape('EIDA | Página '.($index + 1).' de '.count($pages)).") Tj ET\n";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($stream).">>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ', $kids).'] >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";
    }

    private function escape(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($encoded === false) {
            throw new \RuntimeException('Invalid report text encoding.');
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }
}
