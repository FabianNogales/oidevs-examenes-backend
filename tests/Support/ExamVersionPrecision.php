<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait ExamVersionPrecision
{
    private function enableExamMicroseconds(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            if (! preg_match('/^hu18_test_[a-f0-9]{12}$/', DB::connection()->getDatabaseName())) {
                $this->markTestSkipped('Precision fixtures require an isolated hu18_test_* database.');
            }
            // Test fixture only; the project database is adjusted manually by its owner.
            DB::statement('ALTER TABLE exams ALTER COLUMN updated_at TYPE timestamp(6) without time zone');
        }
    }
}
