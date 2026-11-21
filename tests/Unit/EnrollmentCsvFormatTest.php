<?php

namespace Tests\Unit;

use App\Services\Enrollments\EnrollmentCsvFormat;
use PHPUnit\Framework\TestCase;

class EnrollmentCsvFormatTest extends TestCase
{
    public function test_excel_and_standard_exports_use_the_same_column_and_separator(): void
    {
        foreach ([
            ["sisCode\n202600001\n", ',', 0],
            ["\xEF\xBB\xBFsisCode\r\n202600001\r\n", ',', 0],
            ["Nombre; sisCode ;Otro\nAna;202600001;x\n", ';', 1],
            ["\xEF\xBB\xBF\"sisCode\",Nombre\n202600001,Ana\n", ',', 0],
        ] as [$csv, $expectedDelimiter, $expectedIndex]) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $csv);
            rewind($stream);
            [, $delimiter, $index] = EnrollmentCsvFormat::header($stream);
            $this->assertSame($expectedDelimiter, $delimiter);
            $this->assertSame($expectedIndex, $index);
            $row = fgetcsv($stream, 0, $delimiter, '"', '');
            $this->assertSame('202600001', $row[$index]);
            fclose($stream);
        }
    }

    public function test_missing_or_empty_header_never_becomes_column_zero(): void
    {
        foreach (['', "name,email\nAna,a@example.test\n"] as $csv) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $csv);
            rewind($stream);
            $this->assertFalse(EnrollmentCsvFormat::header($stream)[2]);
            fclose($stream);
        }
    }
}
