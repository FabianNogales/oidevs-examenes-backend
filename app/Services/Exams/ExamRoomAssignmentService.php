<?php

namespace App\Services\Exams;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ExamRoomAssignmentService
{
    /**
     * @return list<int>
     */
    public function normalizeAdditionalRoomIds(int $primaryRoomId, mixed $rawAdditionalRooms): array
    {
        return collect(is_array($rawAdditionalRooms) ? $rawAdditionalRooms : [])
            ->filter(fn ($roomId) => is_numeric($roomId) && (int) $roomId > 0 && (int) $roomId !== $primaryRoomId)
            ->map(fn ($roomId) => (int) $roomId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function assignedRoomIds(int $examId, int $fallbackRoomId): array
    {
        if (! Schema::hasTable('exam_rooms')) {
            return [$fallbackRoomId];
        }

        $roomIds = DB::table('exam_rooms')
            ->where('exam_id', $examId)
            ->pluck('room_id')
            ->map(fn ($roomId) => (int) $roomId)
            ->all();

        return $roomIds === [] ? [$fallbackRoomId] : $roomIds;
    }

    /**
     * @param  list<int>  $targetRoomIds
     */
    public function syncExamRooms(int $examId, array $targetRoomIds, bool $replaceExisting = false): void
    {
        if (! Schema::hasTable('exam_rooms')) {
            return;
        }

        if ($replaceExisting) {
            DB::table('exam_rooms')->where('exam_id', $examId)->delete();
        }

        $now = now();
        $pivotRows = [];
        foreach (array_values($targetRoomIds) as $index => $assignedRoomId) {
            $pivotRows[] = [
                'exam_id' => $examId,
                'room_id' => $assignedRoomId,
                'is_primary' => $index === 0,
                'is_shared' => false,
                'shared_with_consent' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('exam_rooms')->insert($pivotRows);
    }
}
