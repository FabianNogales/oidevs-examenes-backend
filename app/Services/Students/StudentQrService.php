<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\StudentQrToken;
use Illuminate\Support\Str;

class StudentQrService
{
    public function generateTokenForStudent(Student $student): StudentQrToken
    {
        // Revocar tokens activos anteriores del estudiante
        StudentQrToken::where('student_id', $student->id)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'REVOKED',
                'revoked_at' => now(),
            ]);

        // Crear nuevo token
        return StudentQrToken::create([
            'student_id' => $student->id,
            'token' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'generated_at' => now(),
        ]);
    }
}