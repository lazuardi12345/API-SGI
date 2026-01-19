<?php

namespace App\Http\Controllers;

use App\Models\TypeHp;
use Illuminate\Http\Request;

class TypeHpController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search = $request->get('search', null);

        $query = TypeHp::with('merk')->orderBy('id', 'desc');

        if ($search) {
            $query->where('nama_type', 'like', "%{$search}%");
        }

        return response()->json($query->paginate($perPage));
    }

public function getByMerk($merkId)
{
    $types = TypeHp::with(['hargaTerbaru']) 
        ->withCount('grades')
        ->where('merk_hp_id', $merkId)
        ->orderBy('nama_type', 'ASC')
        ->get();

    $data = $types->map(function ($type) {
        $harga = $type->hargaTerbaru; 

        return [
            'id'           => $type->id,
            'id_harga'     => $harga ? $harga->id : null, 
            'nama_type'    => $type->nama_type,
            'harga_barang' => $harga ? $harga->harga_barang : (int) 0,
            'has_grade'    => $type->grades_count > 0,
            'updated_at'   => $harga ? $harga->updated_at : null, 
        ];
    });

    return response()->json(['success' => true, 'data' => $data]);
}

    public function store(Request $request)
    {
        $request->validate([
            'merk_hp_id' => 'required|exists:merk_hp,id',
            'nama_type'  => 'required|string'
        ]);

        $type = TypeHp::create($request->only('merk_hp_id', 'nama_type'));

        return response()->json([
            'success' => true,
            'message' => 'Type berhasil ditambahkan',
            'data' => $type
        ]);
    }


    public function update(Request $request, $id)
    {
        $type = TypeHp::findOrFail($id);

        $request->validate([
            'merk_hp_id' => 'required|exists:merk_hp,id',
            'nama_type'  => 'required|string'
        ]);

        $type->update($request->only('merk_hp_id', 'nama_type'));

        return response()->json([
            'success' => true,
            'message' => 'Type berhasil diperbarui',
            'data' => $type
        ]);
    }


    public function destroy($id)
    {
        $type = TypeHp::findOrFail($id);
        $type->delete();

        return response()->json([
            'success' => true,
            'message' => 'Type berhasil dihapus'
        ]);
    }
}
