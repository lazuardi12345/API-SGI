<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    protected function guard() { return Auth::guard('api'); }

    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'role'     => 'required|in:'.implode(',', [User::ROLE_HM, User::ROLE_ADMIN, User::ROLE_CHECKER, User::ROLE_PETUGAS, User::ROLE_GUDANG, User::ROLE_KASIR]),
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => $request->role,
        ]);

        $token = $this->guard()->login($user);

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil.',
            'data'    => $this->respondWithToken($token, $user)
        ], 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (! $token = $this->guard()->attempt($credentials)) {
            return response()->json(['success' => false, 'message' => 'Email atau password salah.'], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil.',
            'data'    => $this->respondWithToken($token, $this->guard()->user())
        ]);
    }


    protected function respondWithToken($token, $user)
    {
        return [
            'user'         => $user,
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => $this->guard()->factory()->getTTL() * 60
        ];
    }

    public function logout()
    {
        $this->guard()->logout();
        return response()->json(['success' => true, 'message' => 'Logout berhasil.']);
    }

    public function me()
    {
        return response()->json(['success' => true, 'data' => $this->guard()->user()]);
    }
}