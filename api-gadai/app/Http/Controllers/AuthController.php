<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register user baru.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            // sesuaikan enum role baru
            'role'     => 'required|in:hm,admin,checker,petugas',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil.',
            'data' => [
                'user'       => $user,
                'token'      => $token,
                'token_type' => 'Bearer',
            ]
        ], 201);
    }

    /**
     * Login user yang sudah terdaftar.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah.'
            ], 401);
        }

        // hapus semua token lama (opsional)
        $user->tokens()->delete();

        // buat token baru
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data' => [
                'user'       => $user,
                'token'      => $token,
                'token_type' => 'Bearer',
            ]
        ]);
    }

    /**
     * Logout user (hapus token aktif).
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil.'
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Token tidak ditemukan atau sudah logout.'
        ], 401);
    }
}
