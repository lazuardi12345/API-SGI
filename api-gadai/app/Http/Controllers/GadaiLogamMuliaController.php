<?php

namespace App\Http\Controllers;

use App\Models\GadaiLogamMulia;
use App\Models\DokumenPendukungEmas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GadaiLogamMuliaController extends Controller
{

        const DOKUMEN_SOP_EMAS = [
        'emas_timbangan',
        'gosokan_timer',
        'gosokan_ktp',
        'batu',
        'cap_merek',
        'karatase',
        'ukuran_batu',
    ];
    // ================= INDEX =================
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $search  = $request->get('search', '');

        $query = GadaiLogamMulia::with('detailGadai.nasabah','dokumenPendukung','kelengkapanEmas')
            ->orderByDesc('created_at');

        if(!empty($search)){
            $query->where(function($q) use($search){
                $q->where('nama_barang','like',"%{$search}%")
                  ->orWhere('kode_cap','like',"%{$search}%")
                  ->orWhereHas('detailGadai.nasabah', function($q2) use($search){
                      $q2->where('nama_lengkap','like',"%{$search}%");
                  });
            });
        }

        $result = $query->paginate($perPage);

        $result->getCollection()->transform(function($item){
            $item->dokumen_pendukung = $item->dokumenPendukung 
                ? $this->convertDokumenToURL($item->dokumenPendukung)
                : [];
           $item->kelengkapan_list = $item->kelengkapanEmas
    ? $item->kelengkapanEmas->map(fn($k)=>[
        'id'=>$k->id,
        'nama_kelengkapan'=>$k->nama_kelengkapan
    ])->toArray()
    : [];

            return $item;
        });

        return response()->json([
            'success'=>true,
            'message'=>'Data Gadai Logam Mulia berhasil diambil.',
            'data'=>$result->items(),
            'pagination'=>[
                'total'=>$result->total(),
                'per_page'=>$result->perPage(),
                'current_page'=>$result->currentPage(),
                'last_page'=>$result->lastPage(),
                'from'=>$result->firstItem(),
                'to'=>$result->lastItem()
            ]
        ]);
    }

    // ================= SHOW =================
    public function show($id)
    {
        $logam = GadaiLogamMulia::with('detailGadai.nasabah','dokumenPendukung','kelengkapanEmas')->find($id);
        if(!$logam){
            return response()->json(['success'=>false,'message'=>'Data tidak ditemukan.'],404);
        }

        $dokumenPendukung = $logam->dokumenPendukung 
            ? $this->convertDokumenToURL($logam->dokumenPendukung)
            : [];

        $kelengkapanList = $logam->kelengkapanEmas 
            ? $logam->kelengkapanEmas->map(fn($k)=>[
                'id'=>$k->id,
                'nama_kelengkapan'=>$k->nama_kelengkapan
            ])->toArray()
            : [];

        return response()->json([
            'success'=>true,
            'message'=>'Detail Gadai Logam Mulia berhasil diambil.',
            'data'=>[
                'id'=>$logam->id,
                'nama_barang'=>$logam->nama_barang,
                'kode_cap'=>$logam->kode_cap,
                'karat'=>$logam->karat,
                'potongan_batu'=>$logam->potongan_batu,
                'berat'=>$logam->berat,
                'detail_gadai'=>$logam->detailGadai,
                'dokumen_pendukung'=>$dokumenPendukung,
                'kelengkapan_list'=>$kelengkapanList,
            ]
        ]);
    }

    // ================= STORE =================
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

    if($validator->fails()){
        return response()->json(['success'=>false,'errors'=>$validator->errors()],422);
    }

    try {
        $logam = GadaiLogamMulia::create($request->only([
            'nama_barang','kode_cap','karat','potongan_batu','berat','detail_gadai_id'
        ]));

        // ==================== DOKUMEN ====================
        $dokumen = $logam->dokumenPendukung()->firstOrCreate([
            'emas_type'=>'logam_mulia',
            'emas_id'=>$logam->id
        ]);

        $nasabah = $logam->detailGadai->nasabah;
        $nasabahName = preg_replace('/[^A-Za-z0-9_\-]/','', str_replace(' ','_',$nasabah->nama_lengkap));
        $nasabahNik  = preg_replace('/[^A-Za-z0-9]/','',$nasabah->nik);
        $noGadai     = $logam->detailGadai->no_gadai ?? $logam->id;
        $folder      = "{$nasabahName}/logam_mulia/{$noGadai}";

        foreach($request->dokumen_pendukung ?? [] as $field => $value){
            if($request->hasFile("dokumen_pendukung.$field")){
                $file = $request->file("dokumen_pendukung.$field");
                $ext  = $file->getClientOriginalExtension();
                $fileName = "{$field}_{$nasabahNik}.{$ext}";
                $path = $file->storeAs($folder, $fileName, 'minio');
                $dokumen->$field = $path;
            } else {
                $dokumen->$field = $value ?? null;
            }
        }

        $dokumen->save();

        // ==================== KELENGKAPAN ====================
        if($request->filled('kelengkapan') && is_array($request->kelengkapan)){
            $ids = array_map(fn($item)=>$item['id'],$request->kelengkapan);
            $logam->kelengkapanEmas()->sync($ids);
        }

        $logam->load('dokumenPendukung','kelengkapanEmas','detailGadai');

        return response()->json([
            'success'=>true,
            'message'=>'Data berhasil ditambahkan.',
            'data'=>$logam
        ],201);

    } catch(\Throwable $e){
        Log::error('Gagal menyimpan logam mulia: '.$e->getMessage());
        return response()->json([
            'success'=>false,
            'message'=>'Gagal menyimpan data.',
            'error'=>$e->getMessage()
        ],500);
    }
}



    // ================= UPDATE =================
public function update(Request $request, $id)
{
    $logam = GadaiLogamMulia::with('detailGadai.nasabah')->find($id);

    if (!$logam) {
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
        $logam->update(
            $request->only(['nama_barang','kode_cap','karat','potongan_batu','berat'])
        );

        // ==================== DOKUMEN PENDUKUNG ====================
        $dokumen = $logam->dokumenPendukung()->firstOrCreate([
            'emas_type' => 'logam_mulia',
            'emas_id'   => $logam->id
        ]);

        $nasabah = $logam->detailGadai->nasabah;
        $nasabahName = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', $nasabah->nama_lengkap));
        $nasabahNik  = preg_replace('/[^A-Za-z0-9]/', '', $nasabah->nik);
        $noGadai     = $logam->detailGadai->no_gadai ?? $logam->id;
        $folder      = "{$nasabahName}/logam_mulia/{$noGadai}";

        foreach ($request->dokumen_pendukung ?? [] as $field => $value) {
            if ($request->hasFile("dokumen_pendukung.$field")) {
                if ($dokumen->$field && Storage::disk('minio')->exists($dokumen->$field)) {
                    Storage::disk('minio')->delete($dokumen->$field);
                }

                $file     = $request->file("dokumen_pendukung.$field");
                $ext      = $file->getClientOriginalExtension();
                $fileName = "{$field}_{$nasabahNik}.{$ext}";
                $path     = $file->storeAs($folder, $fileName, 'minio');

                $dokumen->$field = $path;
            }
        }

        $dokumen->save();

        // ==================== SYNC KELENGKAPAN ====================
        if ($request->filled('kelengkapan')) {
            $ids = array_map(fn($item) => $item['id'], $request->kelengkapan);
            $logam->kelengkapanEmas()->sync($ids);
        }

        // convert dokumen untuk response
        $logam->dokumen_pendukung = $this->convertDokumenToURL($dokumen);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil diperbarui.',
            'data'    => $logam->load(['kelengkapanEmas'])
        ]);

    } catch (\Throwable $e) {
        Log::error('Gagal update logam mulia: '.$e->getMessage());

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
        $logam = GadaiLogamMulia::with('dokumenPendukung','kelengkapanEmas')->find($id);
        if(!$logam) return response()->json(['success'=>false,'message'=>'Data tidak ditemukan.'],404);

        if($logam->dokumenPendukung){
            foreach($logam->dokumenPendukung->getAttributes() as $key=>$path){
                if(!in_array($key,['id','emas_type','emas_id','created_at','updated_at']) && $path && Storage::disk('minio')->exists($path)){
                    Storage::disk('minio')->delete($path);
                }
            }
            $logam->dokumenPendukung->delete();
        }

        $logam->kelengkapanEmas()->detach();
        $logam->delete();

        return response()->json(['success'=>true,'message'=>'Data berhasil dihapus.']);
    }

    // ================= HELPERS =================
    private function convertDokumenToURL($dokumen)
    {
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

   private function syncKelengkapan($logam, $request)
{
    if ($request->filled('kelengkapan') && is_array($request->kelengkapan)) {
        // Hanya ambil id kelengkapan, tanpa nominal_override
        $ids = array_map(fn($item) => $item['id'], $request->kelengkapan);
        $logam->kelengkapanEmas()->sync($ids);
    }
}

}
