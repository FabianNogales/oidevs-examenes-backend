<?php

namespace App\Http\Controllers\Api\V1\Rooms;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function index()
    {
        $rooms = DB::table('rooms')
            ->where('status', 'ACTIVE')
            ->select('id', 'code', 'name', 'location', 'status')
            ->get();
            
        return response()->json(['data' => $rooms]);
    }
}