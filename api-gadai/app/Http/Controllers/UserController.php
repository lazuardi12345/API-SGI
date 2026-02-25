<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{


public function getUsersByRoles(Request $request): JsonResponse
{
    $rolesString = $request->query('roles');
    
    if (!$rolesString) {
        return response()->json([
            'success' => false,
            'message' => 'Parameter roles diperlukan',
            'data'    => []
        ], 400);
    }

    $rolesArray = explode(',', $rolesString);
    $users = User::whereIn('role', $rolesArray)
        ->select('id') 
        ->get()
        ->makeHidden(['role_name', 'application_type']); 

    return response()->json([
        'success' => true,
        'message' => 'Daftar user berdasarkan role berhasil diambil',
        'data'    => $users
    ]);
}

public function getPegawaiSGI(Request $request): JsonResponse
{
    try {
        $roleNames = [
            'admin'   => 'Administrator',
            'checker' => 'Kepala Toko',
            'petugas' => 'Petugas Lapangan',
            'gudang'  => 'Staff Gudang',
            'kasir'   => 'Kasir',
        ];

        $users = User::whereNotIn('role', ['hm'])
            ->select('id', 'name', 'role')
            ->get();

        $formattedUsers = $users->map(function ($user) use ($roleNames) {
            return [
                'id'        => $user->id,
                'nama'      => $user->name,
                'role'      => $user->role, 
                'role_name' => $roleNames[$user->role] ?? 'Staff' 
            ];
        })->groupBy('role'); 

        return response()->json([
            'success' => true,
            'data'    => $formattedUsers
        ]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
}
