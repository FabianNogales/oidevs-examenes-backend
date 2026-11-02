<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;

trait ExpulsionFixtures
{
    use InfractionFixtures {
        createIncidentFixtures as private createBaseFixtures;
    }

    protected function createIncidentFixtures(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:05:00', 'America/La_Paz'));
        $this->createBaseFixtures();
        $this->exam->update(['exam_date' => '2026-11-02', 'start_time' => '09:00:00']);
    }

    protected function expulsionBase(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/expulsions';
    }

    protected function expulsionPayload(): array
    {
        return ['student_id' => $this->student->id, 'reason' => 'Medida adoptada por el docente.'];
    }
}
