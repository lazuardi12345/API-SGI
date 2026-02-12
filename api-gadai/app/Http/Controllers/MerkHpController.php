<?php

namespace App\Http\Controllers;

use App\Models\MerkHp;
use Illuminate\Http\Request;

class MerkHpController extends Controller
{
    public function index(Request $request)
{
    $perPage = $request->get('per_page', 10);
    $search = $request->get('search');

    $query = MerkHp::orderBy('id', 'desc');
    if ($search) {
        $query->where('nama_merk', 'like', "%{$search}%");
    }

    $paginatedData = $query->paginate($perPage);

    return response()->json([
        'success'  => true,
        'data'     => $paginatedData->items(), 
        'page'     => $paginatedData->currentPage(),
        'pageSize' => $paginatedData->perPage(),
        'total'    => $paginatedData->total(),
    ]);
}

    public function store(Request $request)
    {
        $request->validate([
            'nama_merk' => 'required|string|unique:merk_hp,nama_merk'
        ]);

        $merk = MerkHp::create($request->only('nama_merk'));

        return response()->json([
            'message' => 'Merk berhasil ditambahkan',
            'data' => $merk
        ]);
    }

    public function update(Request $request, $id)
    {
        $merk = MerkHp::findOrFail($id);

        $request->validate([
            'nama_merk' => "required|string|unique:merk_hp,nama_merk,$id"
        ]);

        $merk->update($request->only('nama_merk'));

        return response()->json([
            'message' => 'Merk berhasil diperbarui',
            'data' => $merk
        ]);
    }

    public function destroy($id)
    {
        $merk = MerkHp::findOrFail($id);
        $merk->delete();

        return response()->json(['message' => 'Merk berhasil dihapus']);
    }
}
