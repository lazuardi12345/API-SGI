<?php

namespace App\Http\Controllers;

use App\Models\Kerusakan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class KerusakanController extends Controller
{
    /**
     * LIST DATA KERUSAKAN (PAKE PAGINATION)
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', '');

        $query = Kerusakan::orderBy('id', 'DESC');

        if ($search) {
            $query->where('nama_kerusakan', 'like', "%$search%");
        }

        $kerusakan = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $kerusakan->items(),
                'current_page' => $kerusakan->currentPage(),
                'last_page' => $kerusakan->lastPage(),
                'per_page' => $kerusakan->perPage(),
                'total' => $kerusakan->total(),
            ],
        ]);
    }

    /**
     * SIMPAN DATA BARU
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_kerusakan' => 'required|string|unique:kerusakan,nama_kerusakan',
            'persen'         => 'required|numeric|min:0|max:100', // Validasi persen
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $kerusakan = Kerusakan::create([
            'nama_kerusakan' => $request->nama_kerusakan,
            'persen'         => $request->persen,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Data kerusakan berhasil ditambahkan',
            'data' => $kerusakan,
        ]);
    }

    /**
     * UPDATE DATA KERUSAKAN
     */
    public function update(Request $request, $id)
    {
        $kerusakan = Kerusakan::find($id);
        if (!$kerusakan) {
            return response()->json([
                'success' => false,
                'message' => 'Kerusakan tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_kerusakan' => 'sometimes|string|unique:kerusakan,nama_kerusakan,' . $id,
            'persen'         => 'sometimes|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Update hanya field nama_kerusakan dan persen
        $kerusakan->update($request->only(['nama_kerusakan', 'persen']));

        return response()->json([
            'success' => true,
            'message' => 'Data kerusakan berhasil diperbarui',
            'data' => $kerusakan,
        ]);
    }

    /**
     * HAPUS DATA
     */
    public function destroy($id)
    {
        $kerusakan = Kerusakan::find($id);
        if (!$kerusakan) {
            return response()->json([
                'success' => false,
                'message' => 'Kerusakan tidak ditemukan',
            ], 404);
        }

        $kerusakan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kerusakan berhasil dihapus',
        ]);
    }
}