<?php

namespace App\Http\Controllers;

use App\Models\GadaiPerhiasan;
use App\Models\DokumenPendukungEmas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GadaiPerhiasanController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search  = $request->get('search', '');

        $query = GadaiPerhiasan::with(['detailGadai.nasabah', 'dokumenPendukung', 'kelengkapan'])
                ->orderByDesc('created_at');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('nama_barang', 'like', "%{$search}%")
                  ->orWhere('kode_cap', 'like', "%{$search}%")
                  ->orWhereHas('detailGadai.nasabah', fn($q2) => $q2->where('nama_lengkap', 'like', "%{$search}%"));
            });
        }

        $result = $query->paginate($perPage);

        $result->getCollection()->transform(function($item) {
            $item->dokumen_pendukung = $item->dokumenPendukung 
                ? $this->convertDokumenToURL($item->dokumenPendukung)
                : [];

            $item->kelengkapan_list = $item->kelengkapan 
                ? $item->kelengkapan->map(fn($k)=>[
                    'id'=>$k->id,
                    'nama_kelengkapan'=>$k->nama_kelengkapan
                ])->toArray()
                : [];

            return $item;
        });

        return response()->json([
            'success' => true,
            'message' => 'Data Gadai Perhiasan berhasil diambil.',
            'data' => $result->items(),
            'pagination' => [
                'total' => $result->total(),
                'per_page' => $result->perPage(),
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
                'from' => $result->firstItem(),
                'to' => $result->lastItem(),
            ],
        ]);
    }
    public function show($id)
    {
        $perhiasan = GadaiPerhiasan::with(['detailGadai.nasabah', 'kelengkapan', 'dokumenPendukung'])->find($id);

        if (!$perhiasan) {
            return response()->json(['success' => false, 'message' => 'Data tidak ditemukan.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diambil.',
            'data' => [
                'id'=>$perhiasan->id,
                'nama_barang'=>$perhiasan->nama_barang,
                'kode_cap'=>$perhiasan->kode_cap,
                'karat'=>$perhiasan->karat,
                'potongan_batu'=>$perhiasan->potongan_batu,
                'berat'=>$perhiasan->berat,
                'detail_gadai'=>$perhiasan->detailGadai,
                'dokumen_pendukung'=>$perhiasan->dokumenPendukung ? $this->convertDokumenToURL($perhiasan->dokumenPendukung) : [],
                'kelengkapan_list'=>$perhiasan->kelengkapan 
                    ? $perhiasan->kelengkapan->map(fn($k)=>[
                        'id'=>$k->id,
                        'nama_kelengkapan'=>$k->nama_kelengkapan
                      ])->toArray()
                    : [],
            ]
        ]);
    }
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_barang'       => 'required|string',
            'kode_cap'          => 'nullable|string',
            'karat'             => 'nullable|numeric',
            'potongan_batu'     => 'nullable|string',
            'berat'             => 'nullable|numeric',
            'detail_gadai_id'   => 'required|exists:detail_gadai,id',
            'dokumen_pendukung' => 'nullable|array',
            'kelengkapan'       => 'nullable|array',
        ]);

        if ($validator->fails()) return response()->json(['success'=>false,'errors'=>$validator->errors()],422);

        try {
            $perhiasan = GadaiPerhiasan::create($request->only([
                'nama_barang', 'kode_cap', 'karat', 'potongan_batu', 'berat', 'detail_gadai_id'
            ]));

            $this->saveDokumen($perhiasan, $request);
            $this->syncKelengkapan($perhiasan, $request);

            $perhiasan->load(['dokumenPendukung','kelengkapan','detailGadai']);

            return response()->json(['success'=>true,'message'=>'Data berhasil ditambahkan.','data'=>$perhiasan],201);

        } catch (\Throwable $e) {
            Log::error('Gagal menyimpan data perhiasan: '.$e->getMessage());
            return response()->json(['success'=>false,'message'=>'Gagal menyimpan data.','error'=>$e->getMessage()],500);
        }
    }
   public function update(Request $request, $id)
{
    $perhiasan = GadaiPerhiasan::with('detailGadai.nasabah')->find($id);

    if (!$perhiasan) {
        return response()->json([
            'success' => false,
            'message' => 'Data tidak ditemukan.'
        ], 404);
    }

    $validator = Validator::make($request->all(), [
        'nama_barang'       => 'sometimes|required|string',
        'kode_cap'          => 'nullable|string',
        'karat'             => 'nullable|numeric',
        'potongan_batu'     => 'nullable|string',
        'berat'             => 'nullable|numeric',
        'dokumen_pendukung.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        'kelengkapan'       => 'nullable|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    try {
        $perhiasan->update(
            $request->only(['nama_barang', 'kode_cap', 'karat', 'potongan_batu', 'berat'])
        );
        $dokumen = $perhiasan->dokumenPendukung()->firstOrCreate([
            'emas_type' => 'perhiasan',
            'emas_id'   => $perhiasan->id
        ]);

        $nasabah = $perhiasan->detailGadai->nasabah;
        $nasabahName = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', $nasabah->nama_lengkap));
        $nasabahNik  = preg_replace('/[^A-Za-z0-9]/', '', $nasabah->nik);
        $noGadai     = $perhiasan->detailGadai->no_gadai ?? $perhiasan->id;
        $folder      = "{$nasabahName}/perhiasan/{$noGadai}";

        $dokumenFields = [
            'emas_timbangan',
            'gosokan_timer',
            'gosokan_ktp',
            'batu',
            'cap_merek',
            'karatase',
            'ukuran_batu'
        ];

        foreach ($dokumenFields as $field) {
            $fileKey = "dokumen_pendukung.{$field}";

            if ($request->hasFile($fileKey)) {
                if ($dokumen->$field && Storage::disk('minio')->exists($dokumen->$field)) {
                    Storage::disk('minio')->delete($dokumen->$field);
                }
                $file     = $request->file($fileKey);
                $ext      = $file->getClientOriginalExtension();
                $fileName = "{$field}_{$nasabahNik}.{$ext}";
                $path     = $file->storeAs($folder, $fileName, 'minio');

                $dokumen->$field = $path;
            }
        }

        $dokumen->save();
        $dokumen->refresh();
        if ($request->filled('kelengkapan')) {
            $ids = array_map(fn($item) => $item['id'], $request->kelengkapan);
            $perhiasan->kelengkapan()->sync($ids);
        }
        $perhiasan->dokumen_pendukung = $this->convertDokumenToURL($dokumen);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diperbarui.',
            'data'    => $perhiasan->load(['kelengkapan', 'detailGadai.nasabah'])
        ]);

    } catch (\Throwable $e) {
        Log::error('Gagal update perhiasan: '.$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui data.',
            'error'   => $e->getMessage()
        ], 500);
    }
}
    public function destroy($id)
    {
        $perhiasan = GadaiPerhiasan::with(['dokumenPendukung','kelengkapan'])->find($id);
        if(!$perhiasan) return response()->json(['success'=>false,'message'=>'Data tidak ditemukan.'],404);

        if($perhiasan->dokumenPendukung){
            foreach($perhiasan->dokumenPendukung->getAttributes() as $key=>$path){
                if(!in_array($key,['id','emas_type','emas_id','created_at','updated_at']) && $path && Storage::disk('minio')->exists($path)){
                    Storage::disk('minio')->delete($path);
                }
            }
            $perhiasan->dokumenPendukung->delete();
        }

        $perhiasan->kelengkapan()->detach();
        $perhiasan->delete();

        return response()->json(['success'=>true,'message'=>'Data berhasil dihapus.']);
    }
    private function convertDokumenToURL($dokumen)
    {
        if (!$dokumen) return [];

        $converted = [
            'id' => $dokumen->id,
            'emas_type' => $dokumen->emas_type,
            'emas_id' => $dokumen->emas_id,
            'created_at' => $dokumen->created_at,
            'updated_at' => $dokumen->updated_at,
        ];
        $dokumenFields = [
            'emas_timbangan',
            'gosokan_timer',
            'gosokan_ktp',
            'batu',
            'cap_merek',
            'karatase',
            'ukuran_batu'
        ];

        foreach ($dokumenFields as $field) {
            $converted[$field] = $dokumen->$field 
                ? url("api/files/{$dokumen->$field}") 
                : null;
        }

        return $converted;
    }

    private function saveDokumen($perhiasan, $request)
    {
        if ($request->filled('dokumen_pendukung') && is_array($request->dokumen_pendukung)) {
            $dok = $perhiasan->dokumenPendukung;
            $dataDokumen = [];

            foreach ($request->dokumen_pendukung as $key => $value) {
                if ($request->hasFile("dokumen_pendukung.$key")) {
                    $file = $request->file("dokumen_pendukung.$key");
                    if ($file && $file->isValid()) {
                        if ($dok && isset($dok->$key) && Storage::disk('minio')->exists($dok->$key)) {
                            Storage::disk('minio')->delete($dok->$key);
                        }
                        $filename = "{$key}-" . time() . "." . $file->getClientOriginalExtension();
                        $path = $file->storeAs("dokumen_perhiasan/{$perhiasan->id}", $filename, 'minio');
                        $dataDokumen[$key] = $path;
                    }
                } else {
                    $dataDokumen[$key] = $value;
                }
            }

            if (!empty($dataDokumen)) {
                DokumenPendukungEmas::updateOrCreate(
                    ['emas_type' => 'perhiasan', 'emas_id' => $perhiasan->id],
                    $dataDokumen
                );
            }
        }
    }

    private function syncKelengkapan($perhiasan, $request)
    {
        if ($request->filled('kelengkapan') && is_array($request->kelengkapan)) {
            $ids = array_map(fn($item) => is_array($item) ? $item['id'] : $item, $request->kelengkapan);
            $perhiasan->kelengkapan()->sync($ids);
        }
    }
}