<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait InfractionFixtures
{
    use IncidentFixtures {
        createIncidentFixtures as private createBaseIncidentFixtures;
    }

    protected function createIncidentFixtures(): void
    {
        $this->createBaseIncidentFixtures();
        DB::table('enrollments')->insert(['course_offering_id' => $this->exam->course_offering_id,
            'student_id' => $this->student->id, 'registered_by' => $this->owner->id, 'status' => 'ACTIVE']);
    }

    protected function infractionBase(): string
    {
        return '/api/v1/exams/'.$this->exam->id.'/infractions';
    }

    protected function infractionPayload(): array
    {
        return ['student_id' => $this->student->id, 'type' => 'COPYING', 'description' => 'Copia detectada durante la evaluación.',
            'observations' => 'Pendiente de revisión.'];
    }
}
