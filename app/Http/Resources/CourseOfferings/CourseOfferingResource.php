<?php

namespace App\Http\Resources\CourseOfferings;

use App\Http\Resources\AcademicTerms\AcademicTermResource;
use App\Http\Resources\Subjects\SubjectResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseOfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['teacher.user:id,email', 'subject.careers', 'academicTerm']);
        foreach (['enrollments', 'exams'] as $relation) {
            if (! array_key_exists($relation.'_count', $this->resource->getAttributes())) {
                $this->resource->loadCount($relation);
            }
        }
        $teacher = $this->teacher;

        return [
            'id' => (int) $this->id,
            'teacher' => [
                'id' => (int) $teacher->id,
                'institutional_code' => $teacher->institutional_code,
                'first_names' => $teacher->first_names,
                'last_names' => $teacher->last_names,
                'email' => $teacher->user->email,
                'status' => $teacher->status instanceof \BackedEnum ? $teacher->status->value : $teacher->status,
            ],
            'subject' => SubjectResource::make($this->subject),
            'academic_term' => AcademicTermResource::make($this->academicTerm),
            'status' => $this->status,
            'enrollments_count' => (int) $this->enrollments_count,
            'exams_count' => (int) $this->exams_count,
        ];
    }
}
