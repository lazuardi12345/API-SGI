<?php

namespace App\Http\Controllers;

use App\Models\GadaiRetro;
use App\Models\DokumenPendukungEmas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GadaiRetroController extends Controller
{
    // ================= INDEX =================
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search  = $request->get('search', '');

        $query = GadaiRetro::with(['detailGadai.nasabah', 'dokumenPendukung', 'kelengkapan'])
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('nama_barang', 'like', "%{$search}%")
                  ->orWhere('kode_cap', 'like', "%{$search}%")
                  ->orWhereHas('detailGadai.nasabah', fn($q2) => $q2->where('nama_lengkap', 'like', "%{$search}%"));
            });
        }

        $result = $query->paginate($perPage);

        $result->getCollection()->transform(function ($item) {
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
            'success'=>true,
            'message'=>'Data Gadai Retro berhasil diambil.',
            'data'=>$result->items(),
            'pagination'=>[
                'total'=>$result->total(),
                'per_page'=>$result->perPage(),
                'current_page'=>$result->currentPage(),
                'last_page'=>$result->lastPage(),
                'from'=>$result->firstItem(),
                'to'=>$result->lastItem(),
            ],
        ]);
    }

    // ================= SHOW =================
    public function show($id)
    {
        $retro = GadaiRetro::with(['detailGadai.nasabah', 'kelengkapan', 'dokumenPendukung'])->find($id);

        if (!$retro) {
            return response()->json(['success'=>false,'message'=>'Data tidak ditemukan.'],404);
        }

        $dokumenPendukung = $retro->dokumenPendukung
            ? $this->convertDokumenToURL($retro->dokumenPendukung)
            : [];

        $kelengkapanList = $retro->kelengkapan
            ? $retro->kelengkapan->map(fn($k)=>[
                'id'=>$k->id,
                'nama_kelengkapan'=>$k->nama_kelengkapan
            ])->toArray()
            : [];

        return response()->json([
            'success'=>true,
            'message'=>'Detail Gadai Retro berhasil diambil.',
            'data'=>[
                'id'=>$retro->id,
                'nama_barang'=>$retro->nama_barang,
                'kode_cap'=>$retro->kode_cap,
                'karat'=>$retro->karat,
                'potongan_batu'=>$retro->potongan_batu,
                'berat'=>$retro->berat,
                'detail_gadai'=>$retro->detailGadai,
                'dokumen_pendukung'=>$dokumenPendukung,
                'kelengkapan_list'=>$kelengkapanList,
            ]
        ]);
    }

    // ================= STORE =================
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_barang'=>'required|string',
            'kode_cap'=>'nullable|string',
            'karat'=>'nullable|numeric',
            'potongan_batu'=>'nullable|string',
            'berat'=>'nullable|numeric',
            'detail_gadai_id'=>'required|exists:detail_gadai,id',
            'dokumen_pendukung'=>'nullable|array',
            'kelengkapan'=>'nullable|array',
        ]);

        if($validator->fails()) return response()->json(['success'=>false,'errors'=>$validator->errors()],422);

        try {
            $retro = GadaiRetro::create($request->only([
                'nama_barang','kode_cap','karat','potongan_batu','berat','detail_gadai_id'
            ]));

            $this->saveDokumen($retro,$request);
            $this->syncKelengkapan($retro,$request);

            $retro->load(['dokumenPendukung','kelengkapan','detailGadai']);

            return response()->json(['success'=>true,'message'=>'Data berhasil ditambahkan.','data'=>$retro],201);

        } catch(\Throwable $e){
            Log::error('Gagal menyimpan data retro: '.$e->getMessage());
            return response()->json(['success'=>false,'message'=>'Gagal menyimpan data.','error'=>$e->getMessage()],500);
        }
    }

    // ================= UPDATE =================
public function update(Request $request, $id)
{
    $retro = GadaiRetro::with('detailGadai.nasabah')->find($id);

    if (!$retro) {
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
        'dokumen_pendukung' => 'nullable|array',
        'kelengkapan'       => 'nullable|array',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors()
        ], 422);
    }

    try {
        // Update field biasa
        $retro->update(
            $request->only([
                'nama_barang','kode_cap','karat','potongan_batu','berat'
            ])
        );

        // ==================== DOKUMEN PENDUKUNG ====================
        $dokumen = $retro->dokumenPendukung()->firstOrCreate([
            'emas_type' => 'retro',
            'emas_id'   => $retro->id
        ]);

        $nasabah = $retro->detailGadai->nasabah;
        $nasabahName = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', $nasabah->nama_lengkap));
        $nasabahNik  = preg_replace('/[^A-Za-z0-9]/', '', $nasabah->nik);
        $noGadai     = $retro->detailGadai->no_gadai ?? $retro->id;

        $folder = "{$nasabahName}/retro/{$noGadai}";

        // loop semua field dokumen
        foreach ($request->dokumen_pendukung ?? [] as $field => $value) {

            if ($request->hasFile("dokumen_pendukung.$field")) {

                // delete file lama
                if ($dokumen->$field && Storage::disk('minio')->exists($dokumen->$field)) {
                    Storage::disk('minio')->delete($dokumen->$field);
                }

                $file     = $request->file("dokumen_pendukung.$field");
                $ext      = $file->getClientOriginalExtension();
                $fileName = "{$field}_{$nasabahNik}.{$ext}";

                // Upload baru ke MinIO
                $path = $file->storeAs($folder, $fileName, 'minio');

                // simpan path nya
                $dokumen->$field = $path;
            }
        }

        $dokumen->save();

        // update kelengkapan jika ada
        if ($request->filled('kelengkapan')) {
            $retro->kelengkapan()->sync($request->kelengkapan);
        }

        // convert dokumen untuk response
        $retro->dokumen_pendukung = $this->convertDokumenToURL($dokumen);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diperbarui.',
            'data'    => $retro->load(['kelengkapan'])
        ]);

    } catch (\Throwable $e) {
        Log::error('Gagal update retro: '.$e->getMessage());

        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui data.',
            'error'   => $e->getMessage()
        ], 500);
    }
}




    // ================= DESTROY =================
public function destroy($id)
{
    $retro = GadaiRetro::with(['dokumenPendukung','kelengkapan'])->find($id);
    if(!$retro) return response()->json(['success'=>false,'message'=>'Data tidak ditemukan.'],404);

    if($retro->dokumenPendukung){
        foreach($retro->dokumenPendukung as $dok){ // loop per model
            foreach($dok->getAttributes() as $key=>$path){
                if(!in_array($key,['id','emas_type','emas_id','created_at','updated_at']) 
                    && $path 
                    && Storage::disk('minio')->exists($path)){
                    Storage::disk('minio')->delete($path);
                }
            }
            $dok->delete(); // hapus record dokumen
        }
    }

    $retro->kelengkapan()->detach();
    $retro->delete();

    return response()->json(['success'=>true,'message'=>'Data berhasil dihapus.']);
}

    // ================= HELPERS =================
private function convertDokumenToURL($dokumen)
{
    if(!$dokumen) return [];

    $converted = [];

    foreach($dokumen->getAttributes() as $key=>$path){
        if(!in_array($key,['id','emas_type','emas_id','created_at','updated_at']) && $path){
            $converted[$key] = url("api/files/{$path}");
        } else {
            $converted[$key] = null;
        }
    }

    return $converted;
}





private function syncKelengkapan($retro, $request)
{
    if ($request->filled('kelengkapan') && is_array($request->kelengkapan)) {
        $retro->kelengkapan()->sync($request->kelengkapan);
    }
}

}
