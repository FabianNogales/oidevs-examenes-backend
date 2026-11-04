<?php

namespace Tests\Support;

use App\Models\StudentQrToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait EntryAnnulmentFixtures
{
    use IncidentFixtures {
        createIncidentFixtures as private createBaseIncidentFixtures;
    }

    protected function createIncidentFixtures(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:05:00', 'America/La_Paz'));
        $this->createBaseIncidentFixtures();
        $this->exam->update(['exam_date' => '2026-11-01', 'start_time' => '09:00:00']);
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $this->student->id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);
        DB::table('exam_eligibilities')->insert(['exam_id' => $this->exam->id,
            'student_id' => $this->student->id, 'status' => 'ELIGIBLE']);
    }

    protected function initialEntry(): int
    {
        return DB::table('exam_entries')->insertGetId([
            'exam_id' => $this->exam->id, 'student_id' => $this->student->id, 'room_id' => $this->exam->room_id,
            'verified_by' => $this->owner->id, 'verification_method' => 'QR', 'entered_at' => now()->subMinute(),
            'status' => 'VALID', 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
    }

    protected function entryBase(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/entry-control';
    }

    protected function annulPath(int $id): string
    {
        return $this->entryBase().'/entries/'.$id.'/annul';
    }

    protected function manualPath(): string
    {
        return $this->entryBase().'/students/'.$this->student->id.'/entries';
    }

    protected function signedQr(): string
    {
        $uuid = (string) Str::uuid();
        StudentQrToken::create(['student_id' => $this->student->id, 'exam_id' => $this->exam->id,
            'token' => $uuid, 'status' => 'ACTIVE', 'generated_at' => now()]);
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode(['v' => 2,
            'exp' => now()->addHour()->timestamp, 'jti' => $uuid]);

        return $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, config('app.key'), true)), '+/', '-_'), '=');
    }
}
