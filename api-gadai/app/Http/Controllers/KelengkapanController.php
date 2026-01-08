<?php

namespace App\Http\Controllers;

use App\Models\Kelengkapan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KelengkapanController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search  = $request->get('search', '');

        $query = Kelengkapan::orderBy('id', 'DESC');

        if ($search) {
            $query->where('nama_kelengkapan', 'like', "%$search%");
        }

        $kelengkapan = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items'        => $kelengkapan->items(),
                'current_page' => $kelengkapan->currentPage(),
                'last_page'    => $kelengkapan->lastPage(),
                'per_page'     => $kelengkapan->perPage(),
                'total'        => $kelengkapan->total(),
            ],
        ]);
    }


    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_kelengkapan' => 'required|string|unique:kelengkapan,nama_kelengkapan',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $kelengkapan = Kelengkapan::create([
            'nama_kelengkapan' => $request->nama_kelengkapan,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil ditambahkan',
            'data' => $kelengkapan,
        ]);
    }


    public function update(Request $request, $id)
    {
        $kelengkapan = Kelengkapan::find($id);
        if (!$kelengkapan) {
            return response()->json([
                'success' => false,
                'message' => 'Kelengkapan tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_kelengkapan' => 'sometimes|string|unique:kelengkapan,nama_kelengkapan,' . $id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $kelengkapan->update($request->only(['nama_kelengkapan']));

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil diperbarui',
            'data' => $kelengkapan,
        ]);
    }

    public function destroy($id)
    {
        $kelengkapan = Kelengkapan::find($id);
        if (!$kelengkapan) {
            return response()->json([
                'success' => false,
                'message' => 'Kelengkapan tidak ditemukan',
            ], 404);
        }

        $kelengkapan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kelengkapan berhasil dihapus',
        ]);
    }
}