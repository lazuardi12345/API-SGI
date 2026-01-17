<?php

namespace App\Http\Controllers;

use App\Models\Type;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TypeController extends Controller
{

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10); // default 10
        $search = $request->get('search');        // optional
        $page = $request->get('page', 1);

        $query = Type::query();

        // 🔍 Jika ada pencarian
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('nomor_type', 'like', "%{$search}%")
                  ->orWhere('nama_type', 'like', "%{$search}%");
            });
        }

        // 📄 Pagination
        $types = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Data types berhasil diambil.',
            'data' => $types->items(),
            'pagination' => [
                'total' => $types->total(),
                'per_page' => $types->perPage(),
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
            ]
        ], 200);
    }

    /**
     * ➕ Simpan data type baru
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nomor_type' => 'required|string|unique:types,nomor_type',
            'nama_type'  => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $type = Type::create([
                'nomor_type' => $request->nomor_type,
                'nama_type'  => $request->nama_type,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data type.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data type berhasil ditambahkan.',
            'data' => $type
        ], 201);
    }

    /**
     * 🔍 Detail type berdasarkan ID
     */
    public function show($id)
    {
        $type = Type::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Data type tidak ditemukan.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data type ditemukan.',
            'data' => $type
        ], 200);
    }

    /**
     * ✏️ Update data type
     */
    public function update(Request $request, $id)
    {
        $type = Type::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Data type tidak ditemukan.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nomor_type' => 'sometimes|required|string|unique:types,nomor_type,' . $type->id,
            'nama_type'  => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $input = $request->only(['nomor_type', 'nama_type']);
        $updated = false;

        foreach ($input as $key => $value) {
            if ($value !== null && $type->$key !== $value) {
                $type->$key = $value;
                $updated = true;
            }
        }

        if (!$updated) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada perubahan data yang dilakukan.',
                'data' => $type
            ], 400);
        }

        try {
            $type->save();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui data type.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data type berhasil diperbarui.',
            'data' => $type
        ], 200);
    }

    /**
     * 🗑️ Hapus data type
     */
    public function destroy($id)
    {
        $type = Type::find($id);

        if (!$type) {
            return response()->json([
                'success' => false,
                'message' => 'Data type tidak ditemukan.'
            ], 404);
        }

        try {
            $type->delete();
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data type.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data type berhasil dihapus.'
        ], 200);
    }
}
