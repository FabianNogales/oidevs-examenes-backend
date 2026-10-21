<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Subjects\CareerResource;
use App\Models\Career;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CareerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'search' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $query = Career::query();
        if ($search !== '') {
            $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['%'.$search.'%']));
        }

        return CareerResource::collection($query->orderBy('name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 10)->withQueryString());
    }

    public function show(string $career): CareerResource
    {
        return CareerResource::make(Career::findOrFail($career));
    }
}
