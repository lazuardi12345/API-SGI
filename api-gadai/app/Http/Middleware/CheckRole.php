<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            Log::warning("GUDANG DEBUG: User tidak terdeteksi di Middleware. Token mungkin salah/expired.");
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Log buat pembuktian (liat di storage/logs/laravel.log)
        Log::info("GUDANG DEBUG: User Login as [{$user->role}]. Required roles: " . implode(',', $roles));

        // Pengecekan role (case-insensitive)
        if (!in_array(strtolower($user->role), array_map('strtolower', $roles))) {
            Log::warning("GUDANG DEBUG: Akses Ditolak. Role '{$user->role}' tidak diizinkan akses route ini.");
            return response()->json(['message' => 'Forbidden. Anda tidak punya akses.'], 403);
        }

        return $next($request);
    }
}