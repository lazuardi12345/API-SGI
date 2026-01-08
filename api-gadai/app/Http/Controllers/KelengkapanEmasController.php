<?php

namespace App\Http\Controllers;

use App\Models\KelengkapanEmas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KelengkapanEmasController extends Controller
{

    public function index()
    {
        $kelengkapan = KelengkapanEmas::orderBy('nama_kelengkapan')->get();

        return response()->json([
            'success' => true,
            'data' => $kelengkapan,
        ]);
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_kelengkapan' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $kelengkapan = KelengkapanEmas::create([
            'nama_kelengkapan' => $request->nama_kelengkapan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil ditambahkan',
            'data' => $kelengkapan,
        ], 201);
    }

    public function show($id)
    {
        $kelengkapan = KelengkapanEmas::find($id);

        if (!$kelengkapan) {
            return response()->json([
                'success' => false,
                'message' => 'Kelengkapan tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $kelengkapan,
        ]);
    }


    public function update(Request $request, $id)
    {
        $kelengkapan = KelengkapanEmas::find($id);

        if (!$kelengkapan) {
            return response()->json([
                'success' => false,
                'message' => 'Kelengkapan tidak ditemukan'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_kelengkapan' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $kelengkapan->update([
            'nama_kelengkapan' => $request->nama_kelengkapan
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil diperbarui',
            'data' => $kelengkapan,
        ]);
    }

    public function destroy($id)
    {
        $kelengkapan = KelengkapanEmas::find($id);

        if (!$kelengkapan) {
            return response()->json([
                'success' => false,
                'message' => 'Kelengkapan tidak ditemukan'
            ], 404);
        }

        $kelengkapan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil dihapus',
        ]);
    }
}
