<?php

namespace App\Services\Entries;

use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WrongExamAuditService
{
    public function __construct(private AuditLogService $audit) {}

    public function record(int $actorId, int $requestedExamId, int $qrExamId, int $studentId): void
    {
        try {
            // A failed insert is rolled back before handling the error, including on PostgreSQL.
            DB::transaction(fn () => $this->audit->log(
                request(), $actorId, 'ENTRY_QR_WRONG_EXAM', 'Exam', $requestedExamId,
                newValues: ['qr_exam_id' => $qrExamId, 'student_id' => $studentId,
                    'reason' => 'WRONG_EXAM', 'method' => 'QR'],
            ));
        } catch (Throwable $exception) {
            // Exception messages/SQL bindings may contain credentials or personal data.
            Log::error('ENTRY_QR_WRONG_EXAM audit persistence failed.', [
                'exception_type' => $exception::class,
            ]);
        }
    }
}
