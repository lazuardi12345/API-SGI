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
}