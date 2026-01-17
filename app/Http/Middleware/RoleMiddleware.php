<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $roles)
    {
        $user = $request->user();

        // Jika user belum login / token tidak valid
        if (!$user) {
            return response()->json([
                'message' => 'Forbidden. Anda tidak punya akses.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Split multiple roles dari parameter middleware
        $roles = explode(',', $roles);

        // Ambil role user dari DB, case-insensitive dan trim spasi
        $userRole = strtolower(trim($user->role));
        $roles = array_map(function ($role) {
            return strtolower(trim($role));
        }, $roles);

        // Debugging log (opsional, bisa dihapus setelah fix)
        // \Log::info('ROLE DEBUG:', [
        //     'route' => $request->path(),
        //     'user_email' => $user->email,
        //     'user_role' => $userRole,
        //     'required_roles' => $roles,
        // ]);

        // Cek apakah role user ada di daftar role yang diizinkan
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'message' => 'Forbidden. Anda tidak punya akses.'
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
