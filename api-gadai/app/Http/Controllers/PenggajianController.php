<?php

namespace App\Http\Controllers;

use App\Models\Penggajian;
use Illuminate\Http\Request;

class PenggajianController extends Controller
{
    // GET /penggajian
    public function index(Request $request)
    {
        $query = Penggajian::orderBy('tahun', 'desc')->orderBy('bulan', 'desc');

        if ($request->tahun) {
            $query->where('tahun', $request->tahun);
        }

        $data = $query->get()->map(function ($item) {
            return [
                'id'               => $item->id,
                'bulan'            => $item->bulan,
                'nama_bulan'       => $item->nama_bulan,
                'periode'          => $item->periode,
                'tahun'            => $item->tahun,
                'jumlah_karyawan'  => $item->jumlah_karyawan,
                'total_gaji'       => $item->total_gaji,
                'keterangan'       => $item->keterangan,
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }

    // GET /penggajian/{id}
    public function show($id)
    {
        $penggajian = Penggajian::findOrFail($id);

        return response()->json([
            'status' => true,
            'data'   => [
                'id'              => $penggajian->id,
                'bulan'           => $penggajian->bulan,
                'nama_bulan'      => $penggajian->nama_bulan,
                'periode'         => $penggajian->periode,
                'tahun'           => $penggajian->tahun,
                'jumlah_karyawan' => $penggajian->jumlah_karyawan,
                'total_gaji'      => $penggajian->total_gaji,
                'keterangan'      => $penggajian->keterangan,
            ],
        ]);
    }

    // POST /penggajian
    public function store(Request $request)
    {
        $request->validate([
            'bulan'            => 'required|integer|min:1|max:12',
            'tahun'            => 'required|integer|min:2000',
            'jumlah_karyawan'  => 'required|integer|min:1',
            'total_gaji'       => 'required|numeric|min:0',
            'keterangan'       => 'nullable|string',
        ]);

        $exists = Penggajian::where('bulan', $request->bulan)
            ->where('tahun', $request->tahun)
            ->exists();

        if ($exists) {
            return response()->json([
                'status'  => false,
                'message' => 'Periode bulan & tahun ini sudah ada!',
            ], 422);
        }

        $penggajian = Penggajian::create([
            'bulan'           => $request->bulan,
            'tahun'           => $request->tahun,
            'jumlah_karyawan' => $request->jumlah_karyawan,
            'total_gaji'      => $request->total_gaji,
            'keterangan'      => $request->keterangan,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Data penggajian berhasil disimpan!',
            'data'    => $penggajian,
        ], 201);
    }

    // PUT /penggajian/{id}
    public function update(Request $request, $id)
    {
        $penggajian = Penggajian::findOrFail($id);

        $request->validate([
            'jumlah_karyawan' => 'sometimes|integer|min:1',
            'total_gaji'      => 'sometimes|numeric|min:0',
            'keterangan'      => 'nullable|string',
        ]);

        $penggajian->update($request->only([
            'jumlah_karyawan',
            'total_gaji',
            'keterangan',
        ]));

        return response()->json([
            'status'  => true,
            'message' => 'Data penggajian berhasil diupdate!',
            'data'    => $penggajian,
        ]);
    }

    // DELETE /penggajian/{id}
    public function destroy($id)
    {
        $penggajian = Penggajian::findOrFail($id);
        $penggajian->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Data penggajian berhasil dihapus!',
        ]);
    }

    // GET /penggajian/rekap/{tahun}
    public function rekapTahunan($tahun)
    {
        $data = Penggajian::where('tahun', $tahun)
            ->orderBy('bulan')
            ->get()
            ->map(function ($item) {
                return [
                    'bulan'           => $item->bulan,
                    'nama_bulan'      => $item->nama_bulan,
                    'jumlah_karyawan' => $item->jumlah_karyawan,
                    'total_gaji'      => $item->total_gaji,
                    'keterangan'      => $item->keterangan,
                ];
            });

        return response()->json([
            'status'      => true,
            'tahun'       => $tahun,
            'grand_total' => $data->sum('total_gaji'),
            'data'        => $data,
        ]);
    }
}