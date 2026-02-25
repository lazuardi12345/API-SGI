<?php

namespace App\Http\Controllers;

use App\Models\Operasional;
use Illuminate\Http\Request;

class OperasionalController extends Controller
{
    // GET /operasional
    public function index(Request $request)
    {
        $query = Operasional::orderBy('tanggal', 'desc');

        // filter by bulan & tahun
        if ($request->bulan && $request->tahun) {
            $query->whereMonth('tanggal', $request->bulan)
                  ->whereYear('tanggal', $request->tahun);
        } elseif ($request->tahun) {
            $query->whereYear('tanggal', $request->tahun);
        }

        $data      = $query->get();
        $total     = $data->sum('nominal');

        return response()->json([
            'status' => true,
            'total'  => $total,
            'data'   => $data,
        ]);
    }

    // GET /operasional/{id}
    public function show($id)
    {
        $operasional = Operasional::findOrFail($id);

        return response()->json([
            'status' => true,
            'data'   => $operasional,
        ]);
    }

    // POST /operasional
    public function store(Request $request)
    {
        $request->validate([
            'tanggal'    => 'required|date',
            'deskripsi'  => 'required|string',
            'nominal'    => 'required|numeric|min:0',
            'keterangan' => 'nullable|string',
        ]);

        $operasional = Operasional::create($request->only([
            'tanggal', 'deskripsi', 'nominal', 'keterangan'
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Data operasional berhasil disimpan!',
            'data'    => $operasional,
        ], 201);
    }

    // PUT /operasional/{id}
    public function update(Request $request, $id)
    {
        $operasional = Operasional::findOrFail($id);

        $request->validate([
            'tanggal'    => 'sometimes|date',
            'deskripsi'  => 'sometimes|string',
            'nominal'    => 'sometimes|numeric|min:0',
            'keterangan' => 'nullable|string',
        ]);

        $operasional->update($request->only([
            'tanggal', 'deskripsi', 'nominal', 'keterangan'
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Data operasional berhasil diupdate!',
            'data'    => $operasional,
        ]);
    }

    // DELETE /operasional/{id}
    public function destroy($id)
    {
        $operasional = Operasional::findOrFail($id);
        $operasional->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Data operasional berhasil dihapus!',
        ]);
    }
}