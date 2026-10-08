<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Students\StudentResource;
use App\Services\Students\StudentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $students) {}

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
}
