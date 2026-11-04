<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\Cache;

class ExamReportSnapshots
{
    private function key(string $id): string
    {
        return 'exam-report:v1:'.hash('sha256', $id);
    }

    public function put(array $snapshot): void
    {
        $saved = Cache::store(config('exam_reports.cache_store'))->put($this->key($snapshot['report']['id']), $snapshot,
            now()->addMinutes((int) config('exam_reports.snapshot_ttl_minutes')));
        if (! $saved) {
            throw new \RuntimeException('Report snapshot could not be stored.');
        }
    }

    public function get(string $id): array
    {
        $snapshot = Cache::store(config('exam_reports.cache_store'))->get($this->key($id));
        abort_if(! is_array($snapshot) || now()->timestamp >= $snapshot['expires_at'], 410,
            'El reporte venció o ya no está disponible. Genera otro reporte.');

        return $snapshot;
    }
}
