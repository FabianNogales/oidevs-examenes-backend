<?php

namespace App\Services\Entries;

use App\Enums\RoleName;
use App\Models\Exam;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EntryAnnulmentService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function annul(int $examId, int $entryId, string $reason, Request $request): object
    {
        try {
            return DB::transaction(function () use ($examId, $entryId, $reason, $request) {
                // Entry registration also locks the exam first; serialize both transitions.
                $exam = Exam::query()->lockForUpdate()->findOrFail($examId);
                $actor = User::query()->lock('FOR NO KEY UPDATE')->findOrFail($request->user()->id);
                abort_unless($actor->isActive() && $actor->hasRole(RoleName::DOCENTE)
                    && (int) $exam->courseOffering?->teacher?->user_id === (int) $actor->id,
                    403, 'Solo el docente responsable puede anular ingresos de este examen.');
                $entry = DB::table('exam_entries')->where('exam_id', $exam->id)
                    ->where('id', $entryId)->lockForUpdate()->first();
                abort_if($entry === null, 404, 'Registro de ingreso no encontrado.');
                if ($entry->status !== 'VALID') {
                    throw new HttpResponseException(response()->json([
                        'message' => 'El ingreso ya está anulado o no se encuentra en un estado válido para anular.',
                        'code' => 'ENTRY_NOT_VALID',
                    ], 409));
                }
                $before = (array) $entry;
                DB::table('exam_entries')->where('id', $entry->id)->update([
                    'status' => 'ANNULLED', 'annulled_by' => $actor->id, 'annulled_at' => now(),
                    'annulment_reason' => $reason, 'updated_at' => now(),
                ]);
                $entry = DB::table('exam_entries')->where('id', $entry->id)->first();
                $this->audit->log($request, $actor->id, 'EXAM_ENTRY_ANNULLED', 'ExamEntry', $entry->id,
                    $before, (array) $entry);

                return $entry;
            });
        } catch (HttpExceptionInterface|HttpResponseException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new HttpResponseException(response()->json([
                'message' => 'No se pudo anular el ingreso. Consulta su estado antes de reintentar.',
                'code' => 'INTERNAL_ERROR',
            ], 500));
        }
    }
}
