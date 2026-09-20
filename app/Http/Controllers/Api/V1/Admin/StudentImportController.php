<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Students\StudentCsvImportService;
use Illuminate\Http\Request;

class StudentImportController extends Controller
{
    public function preview(
        Request $request,
        StudentCsvImportService $service
    ) {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
            ],
        ]);

        $result = $service->validate(
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Vista previa generada correctamente.',
            'data' => $result,
        ]);
    }

    public function confirm(
        Request $request,
        StudentCsvImportService $service
    ) {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
            ],
        ]);

        $result = $service->import(
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Importación procesada correctamente.',
            'data' => $result,
        ]);
    }
}