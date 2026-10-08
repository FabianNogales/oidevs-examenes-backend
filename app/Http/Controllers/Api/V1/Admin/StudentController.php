<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Students\StoreStudentRequest;
use App\Http\Requests\Api\V1\Students\UpdateStudentRequest;
use App\Http\Requests\Api\V1\Students\UpdateStudentStatusRequest;
use App\Http\Resources\Students\StudentDetailResource;
use App\Http\Resources\Students\StudentResource;
use App\Models\Career;
use App\Models\Student;
use App\Services\Students\StudentRegistrationService;
use App\Services\Students\StudentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $students) {}

    /** Listado paginado con representación reducida, sin CI. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'nullable', 'string'],
        ], [
            'page.integer' => 'La página debe ser un número entero.',
            'page.min' => 'La página debe ser mayor o igual a 1.',
            'per_page.integer' => 'La cantidad por página debe ser un número entero.',
            'search.string' => 'La búsqueda debe ser un texto.',
        ]);

        return StudentResource::collection($this->students->paginate($filters));
    }

    /** Registrar con contexto MANUAL y devolver StudentResource con HTTP 201. */
    public function store(StoreStudentRequest $request, StudentRegistrationService $registration): JsonResponse
    {
        $data = $request->validated();
        $career = Career::find($data['career_id']);

        if (! $career) {
            return response()->json([
                'message' => 'La carrera indicada no existe.',
                'errors' => ['career_id' => ['La carrera indicada no existe.']],
            ], 422);
        }

        try {
            $student = $registration->create($data, $career, $request->user(), $request, 'MANUAL');
        } catch (UniqueConstraintViolationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'El SIS, CI o correo ya fue registrado. Actualice los datos e intente nuevamente.',
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Falló el registro manual de un estudiante.', [
                'exception_type' => $exception::class,
                'actor_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo registrar el estudiante. Intente nuevamente más tarde.',
            ], 500);
        }

        return StudentResource::make($student->load(['user', 'career']))->response()->setStatusCode(201);
    }

    /** Consultar por ID administrativo; el binding responde 404 si no existe. */
    public function show(Student $student): StudentDetailResource
    {
        return StudentDetailResource::make($this->students->detail($student));
    }

    /** Cambiar disponibilidad de la cuenta mediante la operación separada de estado. */
    public function updateStatus(UpdateStudentStatusRequest $request, Student $student): JsonResponse
    {
        try {
            $student = $this->students->changeStatus($student, $request->validated('status'), $request);
        } catch (\Throwable $exception) {
            Log::error('Falló el cambio de estado de un estudiante.', [
                'exception_type' => $exception::class,
                'student_id' => $student->id,
                'actor_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo cambiar el estado del estudiante. Intente nuevamente más tarde.',
            ], 500);
        }

        return StudentResource::make($student)->response();
    }

    /** Aplicar PATCH parcial y devolver el detalle actualizado, incluido CI. */
    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        try {
            $student = $this->students->update($student, $request->validated(), $request);
        } catch (UniqueConstraintViolationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'El SIS, CI o correo ya fue registrado. Actualice los datos e intente nuevamente.',
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Falló la edición administrativa de un estudiante.', [
                'exception_type' => $exception::class,
                'student_id' => $student->id,
                'actor_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar el estudiante. Intente nuevamente más tarde.',
            ], 500);
        }

        return StudentDetailResource::make($student)->response();
    }
}
