<?php

namespace App\Http\Controllers;

use App\Models\SuratKuasaPelunasan;
use App\Models\DetailGadai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SuratKuasaController extends Controller
{
    protected $fotoFields = ['foto_wakil', 'foto_surat'];

    public function searchGadai(Request $request)
    {
        $search = $request->get('q');
        $data = DetailGadai::with('nasabah:id,nama_lengkap,nik,alamat,no_hp')
            ->where('status', 'Selesai') 
            ->where(function($query) use ($search) {
                $query->where('no_gadai', 'LIKE', "%{$search}%")
                      ->orWhereHas('nasabah', function($q) use ($search) {
                          $q->where('nama_lengkap', 'LIKE', "%{$search}%");
                      });
            })
            ->limit(10)
            ->get();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $riwayat = SuratKuasaPelunasan::with([
            'pemberiKuasa:id,nama_lengkap,nik,no_hp', 
            'detailGadai:id,no_gadai,status'
        ])->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $riwayat->items(),
            'pagination' => ['total' => $riwayat->total(), 'current_page' => $riwayat->currentPage()]
        ]);
    }

    public function store(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'wakil_nama'     => 'required|string|max:255',
            'wakil_nik'      => 'required|string|max:20',
            'wakil_alamat'   => 'required|string',
            'wakil_hp'       => 'required|string|max:20',
            'wakil_hubungan' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $gadai = DetailGadai::find($id);
        if (!$gadai) return response()->json(['success' => false, 'message' => 'Gadai tidak ditemukan.'], 404);

        try {
            $surat = SuratKuasaPelunasan::updateOrCreate(
                ['detail_gadai_id' => $id],
                [
                    'nasabah_id'     => $gadai->nasabah_id,
                    'wakil_nama'     => $request->wakil_nama,
                    'wakil_nik'      => $request->wakil_nik,
                    'wakil_alamat'   => $request->wakil_alamat,
                    'wakil_hp'       => $request->wakil_hp,
                    'wakil_hubungan' => $request->wakil_hubungan,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Data surat berhasil disimpan, silakan cetak.',
                'data'    => $surat->load(['pemberiKuasa', 'detailGadai'])
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $surat = SuratKuasaPelunasan::with(['pemberiKuasa', 'detailGadai'])
            ->where('detail_gadai_id', $id)
            ->first();

        if (!$surat) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        return response()->json(['success' => true, 'data' => $surat]);
    }


public function update(Request $request, $id)
{
    $surat = SuratKuasaPelunasan::with(['detailGadai.nasabah'])->find($id);
    if (!$surat) return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);

    $rules = [
        'wakil_nama'     => 'sometimes|string|max:255',
        'wakil_nik'      => 'sometimes|string|max:20',
        'wakil_alamat'   => 'sometimes|string',
        'wakil_hp'       => 'sometimes|string|max:20',
        'wakil_hubungan' => 'sometimes|string',
    ];

    foreach ($this->fotoFields as $field) {
        $rules[$field] = 'nullable|image|mimes:jpeg,png,jpg|max:2048';
    }

    $request->validate($rules);

    try {
        DB::beginTransaction();

        $surat->fill($request->only([
            'wakil_nama', 'wakil_nik', 'wakil_alamat', 'wakil_hp', 'wakil_hubungan'
        ]));

        $this->handleFotoSuratKuasa($request, $surat);

        $surat->save();
        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Data dan Dokumen berhasil diperbarui.',
            'data'    => $surat->load(['pemberiKuasa', 'detailGadai'])
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

private function handleFotoSuratKuasa($request, $surat)
{
    $nasabah = $surat->detailGadai->nasabah;
    $detail  = $surat->detailGadai;

    $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap ?? 'nasabah');

    $mapJenisFolder = [
        1 => 'handphone',
        2 => 'logam_mulia',
        3 => 'retro',
        4 => 'perhiasan',
    ];

    $jenisFolder = $mapJenisFolder[$detail->type_id] ?? 'gadai';
    $noGadaiSlug = preg_replace('/[^A-Za-z0-9\-]/', '_', $detail->no_gadai ?? "gadai-{$surat->detail_gadai_id}");

    $folder = "{$folderNasabah}/{$jenisFolder}/{$noGadaiSlug}/surat-kuasa";

    foreach ($this->fotoFields as $field) {
        if ($request->hasFile($field)) {
            if ($surat->$field) {
                Storage::disk('minio')->delete($surat->$field);
            }

            $file     = $request->file($field);
            $filename = "{$noGadaiSlug}-{$field}.{$file->getClientOriginalExtension()}";

            $surat->$field = $file->storeAs($folder, $filename, 'minio');
        }
    }
}
}