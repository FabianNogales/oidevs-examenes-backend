<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ActionAuditLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        
        if ($response->isSuccessful() && $request->user()) {
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => $request->isMethod('GET') ? 'READ' : 'WRITE',
                'entity_type' => 'TeacherDashboard',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}