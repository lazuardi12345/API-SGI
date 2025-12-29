<?php

namespace App\Http\Controllers;

use App\Models\GadaiHp;
use App\Models\GradeHp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class GadaiHpController extends Controller
{
    private array $dokumenFields = [
        'body','imei','about','akun','admin','cam_depan','cam_belakang','rusak',
        'samsung_account','galaxy_store','icloud','battery','3utools','iunlocker','cek_pencurian'
    ];

    /**
     * Convert dokumen to proper frontend URL
     */
    private function convertDokumen($dokumen)
    {
        if (!$dokumen) return null;

        $out = [];
        foreach ($this->dokumenFields as $field) {
            $out[$field] = $dokumen->$field;
        }

        return $out;
    }

    private function normalizeItems(array $items): array
    {
        $sync = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['id'])) {
                $sync[$item['id']] = ['nominal_override' => $item['nominal_override'] ?? null];
            } elseif (is_numeric($item)) {
                $sync[$item] = ['nominal_override' => null];
            }
        }
        return $sync;
    }


    private function deleteOldFiles(string $folder, string $field, string $nik): void
    {
        try {
            // Pattern: body_1234567890.* (hapus semua extension)
            $pattern = "{$field}_{$nik}.";
            
            // List semua file di folder
            $files = Storage::disk('minio')->files($folder);
            
            foreach ($files as $file) {
                $basename = basename($file);
                
                // Jika nama file cocok dengan pattern, hapus
                if (str_starts_with($basename, $pattern)) {
                    Storage::disk('minio')->delete($file);
                    Log::info('Deleted old file', ['file' => $file]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error deleting old files', [
                'folder' => $folder,
                'field' => $field,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $data = GadaiHp::with([
            'detailGadai.nasabah',
            'merk',
            'type_hp',
            'grade',
            'kerusakanList',
            'kelengkapanList',
            'dokumenPendukungHp'
        ])->orderByDesc('created_at')->paginate($perPage);

        $items = $data->getCollection()->map(function ($hp) {
            $hp->dokumen_pendukung = $this->convertDokumen($hp->dokumenPendukungHp);
            unset($hp->dokumenPendukungHp); // remove duplicate
            return $hp;
        });

        $data->setCollection($items);

        return response()->json([
            'success' => true,
            'message' => 'Data diambil',
            'data'    => $items,
            'total'   => $data->total(),
        ]);
    }

    public function show($id)
    {
        $hp = GadaiHp::with([
            'merk',
            'type_hp',
            'grade',
            'detailGadai.nasabah',
            'kerusakanList',
            'kelengkapanList',
            'dokumenPendukungHp'
        ])->find($id);

        if (!$hp) {
            return response()->json(['success' => false, 'message' => 'Tidak ditemukan'], 404);
        }

        $hp->dokumen_pendukung = $this->convertDokumen($hp->dokumenPendukungHp);
        unset($hp->dokumenPendukungHp);

        return response()->json([
            'success' => true,
            'data'    => $hp,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_barang'     => 'required',
            'detail_gadai_id' => 'required|exists:detail_gadai,id',
            'merk_hp_id'      => 'required',
            'type_hp_id'      => 'required',
            'grade_hp_id'     => 'required',
            'grade_type'      => 'required|in:A,B,C',
            'kelengkapan'     => 'array',
            'kerusakan'       => 'array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $grade = GradeHp::find($request->grade_hp_id);

        $hp = GadaiHp::create([
            'nama_barang'     => $request->nama_barang,
            'detail_gadai_id' => $request->detail_gadai_id,
            'merk_hp_id'      => $request->merk_hp_id,
            'type_hp_id'      => $request->type_hp_id,
            'grade_hp_id'     => $request->grade_hp_id,
            'grade_type'      => $request->grade_type,
            'grade_nominal'   => $request->grade_type === 'A' ? $grade->harga_grade_a
                                : ($request->grade_type === 'B' ? $grade->harga_grade_b : $grade->harga_grade_c),
            'imei'            => $request->imei,
            'warna'           => $request->warna,
            'kunci_password'  => $request->kunci_password,
            'kunci_pin'       => $request->kunci_pin,
            'kunci_pola'      => $request->kunci_pola,
            'ram'             => $request->ram,
            'rom'             => $request->rom,
        ]);

        if ($request->has('kelengkapan')) $hp->kelengkapanList()->sync($this->normalizeItems($request->kelengkapan));
        if ($request->has('kerusakan'))   $hp->kerusakanList()->sync($this->normalizeItems($request->kerusakan));

        $dokumen = $hp->dokumenPendukungHp()->create([]);
        $folder = "pawned-items/handphone/{$hp->id}";

        foreach ($this->dokumenFields as $field) {
            if ($request->hasFile($field)) {
                $dokumen->$field = $request->file($field)->store($folder, 'minio');
            }
        }

        $dokumen->save();

        $hp->dokumen_pendukung = $this->convertDokumen($dokumen);
        unset($hp->dokumenPendukungHp);

        return response()->json([
            'success' => true,
            'message' => 'Data berhasil disimpan',
            'data'    => $hp->load(['kelengkapanList', 'kerusakanList']),
        ]);
    }

public function update(Request $request, $id)
{
    $hp = GadaiHp::with([
        'detailGadai.nasabah',
        'kelengkapanList',
        'kerusakanList'
    ])->findOrFail($id);

    /**
     * =====================================================
     * 1. UPDATE DATA HP DASAR
     * =====================================================
     */
    $hp->update($request->only([
        'imei','warna','kunci_password','kunci_pin','kunci_pola',
        'ram','rom','grade_hp_id','grade_type'
    ]));

    /**
     * =====================================================
     * 2. UPDATE GRADE NOMINAL
     * =====================================================
     */
    if ($request->filled('grade_hp_id') && $request->filled('grade_type')) {
        $grade = GradeHp::find($request->grade_hp_id);

        $newNominal = $request->grade_type === 'A'
            ? $grade->harga_grade_a
            : ($request->grade_type === 'B'
                ? $grade->harga_grade_b
                : $grade->harga_grade_c);

        $hp->update(['grade_nominal' => $newNominal]);
    }

    /**
     * =====================================================
     * 3. SYNC KELENGKAPAN & KERUSAKAN
     * =====================================================
     */
    if ($request->has('kelengkapan'))
        $hp->kelengkapanList()->sync($this->normalizeItems($request->kelengkapan));

    if ($request->has('kerusakan'))
        $hp->kerusakanList()->sync($this->normalizeItems($request->kerusakan));

    // Refresh relasi
    $hp->load(['kelengkapanList', 'kerusakanList']);

    /**
     * =====================================================
     * 4. HITUNG TOTAL KELENGKAPAN & KERUSAKAN
     * =====================================================
     */
    $totalKelengkapan = $hp->kelengkapanList->sum(function ($k) {
        return $k->pivot->nominal_override ?? $k->nominal ?? 0;
    });

    $totalKerusakan = $hp->kerusakanList->sum(function ($k) {
        return $k->pivot->nominal_override ?? $k->nominal ?? 0;
    });

    /**
     * =====================================================
     * 5. PERHITUNGAN AKHIR
     * =====================================================
     */
    // TAKSIRAN = HARGA GRADE (FIX)
    $taksiranAkhir = $hp->grade_nominal;

    // PINJAMAN = Taksiran + Kelengkapan - Kerusakan
    $uangPinjaman = ($taksiranAkhir + $totalKelengkapan) - $totalKerusakan;

    if ($uangPinjaman < 0) $uangPinjaman = 0;

    /**
     * =====================================================
     * 6. UPDATE DETAIL GADAI
     * =====================================================
     */
    $detail = $hp->detailGadai;
    if ($detail) {
        $detail->taksiran = $taksiranAkhir;
        $detail->uang_pinjaman = $uangPinjaman;
        $detail->save();
    }

    /**
     * =====================================================
     * 7. UPDATE DOKUMEN
     * =====================================================
     */
    $dokumen = $hp->dokumenPendukungHp()->firstOrCreate([]);

    $nasabah = $hp->detailGadai->nasabah;
    $nasabahName = preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '_', $nasabah->nama_lengkap ?? 'unknown'));
    $nasabahNik  = preg_replace('/[^A-Za-z0-9]/', '', $nasabah->nik ?? 'unknown');

    $noGadai = $hp->detailGadai->no_gadai ?? $hp->id;
    $folder = "{$nasabahName}/handphone/{$noGadai}";

    foreach ($this->dokumenFields as $field) {
        if ($request->hasFile($field)) {

            // hapus file lama
            $this->deleteOldFiles($folder, $field, $nasabahNik);

            $file = $request->file($field);
            $ext = $file->getClientOriginalExtension();
            $fileName = "{$field}_{$nasabahNik}.{$ext}";

            // upload baru
            $dokumen->$field = $file->storeAs($folder, $fileName, 'minio');
        }
    }

    $dokumen->save();

    /**
     * =====================================================
     * 8. RETURN RESPONSE
     * =====================================================
     */
    $hp->dokumen_pendukung = $this->convertDokumen($dokumen);
    unset($hp->dokumenPendukungHp);

    return response()->json([
        'success' => true,
        'message' => 'Data HP berhasil diperbarui',
        'data'    => $hp->load(['kelengkapanList','kerusakanList']),
    ]);
}



}