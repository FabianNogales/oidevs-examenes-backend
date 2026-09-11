<?php

namespace App\Http\Controllers\Api\V1\Students;

use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateProfilePhotoRequest;
use App\Http\Resources\Students\StudentProfileResource;
use App\Services\Students\StudentProfileService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

class StudentProfileController extends Controller
{
    public function __construct(
        protected StudentProfileService $profileService
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            // Usa el usuario autenticado o toma el primero de la BD para pruebas
            $user = $request->user() ?? User::firstOrFail();
            $student = $this->profileService->getProfileForUser($user);

            return response()->json([
                'message' => 'Perfil obtenido exitosamente',
                'data'    => new StudentProfileResource($student),
            ], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'No se encontraron datos de perfil.'], 404);
        }
    }

    public function updatePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        try {
            $user = $request->user() ?? User::firstOrFail();
            
            $student = $this->profileService->updateProfilePhoto(
                $user,
                $request->file('photo'),
                $request->ip(),
                $request->userAgent()
            );

            return response()->json([
                'message' => 'Foto de perfil actualizada exitosamente',
                'data'    => new StudentProfileResource($student),
            ], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error al actualizar la foto de perfil.'], 500);
        }
    }
}