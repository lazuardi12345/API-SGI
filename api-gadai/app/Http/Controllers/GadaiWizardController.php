<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\DataNasabah;
use App\Models\DetailGadai;
use App\Models\GadaiHp;
use App\Models\Type;
use App\Models\HargaHp;
use App\Models\GradeHp;
use App\Models\DokumenPendukungHp;
use App\Services\GadaiDateService;        // ✅ Fix #1
use App\Services\GradeCalculatorService;  // ✅ Fix #2
use App\Services\NotificationService;     // ✅ Fix #3

class GadaiWizardController extends Controller
{
    const DOKUMEN_SOP_HP = [
        'Android' => ['body','imei','about','akun','admin','cam_depan','cam_belakang','rusak'],
        'Samsung' => ['body','imei','about','samsung_account','admin','cam_depan','cam_belakang','galaxy_store'],
        'iPhone'  => ['body','imei','about','icloud','battery','utools','iunlocker','cek_pencurian'],
    ];

    // ─── Helper: fire-and-forget notif (tidak blocking, tidak crash app) ───
    private function dispatchNotifSafely(callable $callback): void
    {
        dispatch(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                \Log::error('[NotifService] Gagal kirim notifikasi: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        })->afterResponse();
    }

    // ─── STORE ────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nasabah.nama_lengkap' => 'required|string',
            'nasabah.nik'          => 'required|string',
            'nasabah.foto_ktp'     => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'barang.type_hp_id'    => 'required|exists:type_hp,id',
            'barang.grade_type'    => 'required|string',
            'detail.tanggal_gadai' => 'required|date',
            'detail.type_id'       => 'required|exists:types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Deklarasi di luar try agar accessible setelah block
        $detail = null;

        DB::beginTransaction();
        try {
            $nasabahInput = $request->input('nasabah');
            $detailInput  = $request->input('detail');
            $barangInput  = $request->input('barang');

            // 1. Simpan Nasabah
            $nasabah = DataNasabah::create(array_merge($nasabahInput, ['user_id' => auth()->id()]));
            $this->handleFotoKtp($request, $nasabah);

            // 2. Persiapan Data Gadai
            $dateService   = new GadaiDateService();
            $tglJatuhTempo = $dateService->hitungJatuhTempoOtomatis($detailInput['tanggal_gadai'], 15);
            $noGadaiData   = $this->generateNoGadai($detailInput['tanggal_gadai'], $detailInput['type_id']);

            $detail = DetailGadai::create([
                'no_gadai'      => $noGadaiData['no_gadai'],
                'no_nasabah'    => $noGadaiData['no_nasabah'],
                'nasabah_id'    => $nasabah->id,
                'tanggal_gadai' => $detailInput['tanggal_gadai'],
                'jatuh_tempo'   => $tglJatuhTempo,
                'type_id'       => $detailInput['type_id'],
                'status'        => 'proses',
                'taksiran'      => 0,
                'uang_pinjaman' => 0,
            ]);

            // 3. Simpan Barang + Sync Kerusakan/Kelengkapan
            $barang = $this->processGadaiHp($barangInput, $detail->id);

            // 4. Hitung Final (aman, data kerusakan sudah di-sync di langkah 3)
            $perhitungan = $this->hitungFinalPinjaman($barang);

            // 5. Update Nominal Akhir
            $detail->update([
                'taksiran'      => $perhitungan['taksiran'],
                'uang_pinjaman' => $perhitungan['pinjaman'],
            ]);
            $barang->update(['grade_nominal' => $perhitungan['pinjaman']]);

            // 6. Dokumen SOP
            $this->uploadDokumenSop($request, $barang, $nasabah, $detail);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('[GadaiWizard] store() gagal: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        // Notif dikirim SETELAH response balik ke FE
        $detailId = $detail->id;
        $this->dispatchNotifSafely(function () use ($detailId) {
            $fresh = DetailGadai::find($detailId);
            if ($fresh) {
                (new NotificationService())->notifyNewTransaction($fresh);
            }
        });

        // Fresh dari DB biar data pasti up-to-date (hindari stale model)
        $responseData = DetailGadai::with('hp')->find($detailId);

        return response()->json([
            'success' => true,
            'message' => 'Gadai HP Berhasil',
            'data'    => $responseData,
        ]);
    }

    // ─── Generate No Gadai ────────────────────────────────────────────────
    private function generateNoGadai($tanggalGadai, $typeId)
    {
        $tgl    = \Carbon\Carbon::parse($tanggalGadai);
        $type   = Type::findOrFail($typeId);

        $lastDetail = DetailGadai::lockForUpdate()->orderBy('id', 'desc')->first();
        $noNum      = $lastDetail ? (int) substr($lastDetail->no_nasabah, -4) + 1 : 1;
        $suffix     = str_pad($noNum, 4, '0', STR_PAD_LEFT);

        return [
            'no_nasabah' => $tgl->format('my') . $suffix,
            'no_gadai'   => "SGI-{$tgl->format('d-m-Y')}-{$type->nomor_type}-{$suffix}",
        ];
    }

    // ─── Hitung Final Pinjaman ────────────────────────────────────────────
    private function hitungFinalPinjaman($barang)
    {
        $gradeData = GradeHp::whereHas('hargaHp', function ($q) use ($barang) {
            $q->where('type_hp_id', $barang->type_hp_id);
        })->first() ?? throw new \Exception("Master Harga/Grade belum di-setting.");

        $colPinjaman = 'grade_' . $barang->grade_type;
        $colTaksiran = 'taksiran_' . $barang->grade_type;

        $totalPersenKerusakan = DB::table('gadai_hp_kerusakan')
            ->join('kerusakan', 'kerusakan.id', '=', 'gadai_hp_kerusakan.kerusakan_id')
            ->where('gadai_hp_id', $barang->id)
            ->sum('kerusakan.persen') ?: 0;

        $calcService = new GradeCalculatorService();
        return $calcService->calculateFinalLoan($gradeData->$colPinjaman, (float) $totalPersenKerusakan);
    }

    // ─── Upload Dokumen SOP ───────────────────────────────────────────────
    private function uploadDokumenSop($request, $barang, $nasabah, $detail)
    {
        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
        $folderBarang  = "{$folderNasabah}/handphone/{$detail->no_gadai}";

        $merk   = $request->input('barang.merk_name', 'Android');
        $fields = self::DOKUMEN_SOP_HP[$merk] ?? self::DOKUMEN_SOP_HP['Android'];
        $paths  = [];

        foreach ($fields as $field) {
            if ($request->hasFile("barang.dokumen_pendukung.$field")) {
                $file     = $request->file("barang.dokumen_pendukung.$field");
                $filename = "{$field}_" . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $paths[$field] = $file->storeAs($folderBarang, $filename, 'minio');
            }
        }

        if (!empty($paths)) {
            DokumenPendukungHp::create(array_merge(['gadai_hp_id' => $barang->id], $paths));
        }
    }

    // ─── Handle Foto KTP ──────────────────────────────────────────────────
    private function handleFotoKtp($request, $nasabah)
    {
        if ($request->hasFile('nasabah.foto_ktp')) {
            $file          = $request->file('nasabah.foto_ktp');
            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
            $filename      = 'ktp_' . $nasabah->nik . '_' . time() . '.' . $file->getClientOriginalExtension();
            $ktpPath       = $file->storeAs($folderNasabah, $filename, 'minio');
            $nasabah->update(['foto_ktp' => $ktpPath]);
        }
    }

    // ─── Process Gadai HP ─────────────────────────────────────────────────
    private function processGadaiHp($barangInput, $detailId)
    {
        $pureGradeType = strtolower(str_replace(['-', ' '], '_', $barangInput['grade_type']));

        $barang = GadaiHp::create([
            'detail_gadai_id' => $detailId,
            'nama_barang'     => $barangInput['nama_barang'] ?? 'Handphone',
            'merk_hp_id'      => $barangInput['merk_hp_id'] ?? null,
            'type_hp_id'      => $barangInput['type_hp_id'],
            'grade_type'      => $pureGradeType,
            'imei'            => $barangInput['imei'] ?? null,
            'warna'           => $barangInput['warna'] ?? null,
            'ram'             => $barangInput['ram'] ?? null,
            'rom'             => $barangInput['rom'] ?? null,
            'kunci_password'  => $barangInput['kunci_password'] ?? null,
            'kunci_pin'       => $barangInput['kunci_pin'] ?? null,
            'kunci_pola'      => $barangInput['kunci_pola'] ?? null,
        ]);

        if (!empty($barangInput['kerusakan'])) {
            $barang->kerusakanList()->sync($barangInput['kerusakan']);
        }
        if (!empty($barangInput['kelengkapan'])) {
            $barang->kelengkapanList()->sync($barangInput['kelengkapan']);
        }

        return $barang;
    }
}