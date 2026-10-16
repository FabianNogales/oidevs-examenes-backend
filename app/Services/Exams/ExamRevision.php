<?php

namespace App\Services\Exams;

use App\Models\Exam;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class ExamRevision
{
    public function assertMatches(Exam $exam, string $expected): void
    {
        if (! $exam->updated_at || ! CarbonImmutable::parse($expected)->equalTo($exam->updated_at)) {
            throw new HttpResponseException(response()->json(['message' => 'El examen fue modificado. Recarga su información.', 'code' => 'EXAM_VERSION_CONFLICT'], 409));
        }
    }

    public function saveNext(Exam $exam, bool $requireMicroseconds = true): void
    {
        if (! $this->supportsMicroseconds()) {
            if ($requireMicroseconds) {
                throw new HttpResponseException(response()->json(['message' => 'La edición requiere habilitar microsegundos en updated_at. No se guardaron cambios.',
                    'code' => 'EXAM_VERSION_PRECISION_REQUIRED'], 409));
            }
            // Preserve existing cancellation behavior until the manual precision adjustment.
            $exam->save();

            return;
        }
        $previous = $exam->updated_at;
        $next = CarbonImmutable::now(config('app.timezone'));
        if ($previous && $next->lessThanOrEqualTo($previous)) {
            $next = CarbonImmutable::instance($previous)->addMicrosecond();
        }
        $exam->setDateFormat('Y-m-d H:i:s.u');
        $exam->updated_at = $next;
        $exam->save();
    }

    private function supportsMicroseconds(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return true;
        }
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }
        $column = DB::selectOne('SELECT datetime_precision FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [DB::connection()->getTablePrefix().'exams', 'updated_at']);

        return (int) ($column?->datetime_precision ?? 0) >= 6;
    }
}
