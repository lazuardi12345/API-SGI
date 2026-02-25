<?php

namespace App\Http\Controllers;

use App\Models\{DataNasabah, DetailGadai, GadaiHp, Type, HargaHp, GradeHp, DokumenPendukungHp};
use App\Services\{GadaiDateService, NotificationService, GradeCalculatorService}; // ✅ Fix #1
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator, Log};
use Carbon\Carbon;

class GadaiUlangController extends Controller
{
    const DOKUMEN_SOP_HP = [
        'Android' => ['body','imei','about','akun','admin','cam_depan','cam_belakang','rusak'],
        'Samsung' => ['body','imei','about','samsung_account','admin','cam_depan','cam_belakang','galaxy_store'],
        'iPhone'  => ['body','imei','about','icloud','battery','utools','iunlocker','cek_pencurian'],
    ];

    // ─── Fire-and-forget notif, tidak blocking, tidak crash app ──────────
    private function dispatchNotifSafely(callable $callback): void
    {
        dispatch(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('[NotifService] Gagal kirim notifikasi: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        })->afterResponse();
    }

    // ─── Check Riwayat Nasabah ────────────────────────────────────────────
    public function checkNasabah(Request $request)
    {
        $nik = $request->input('nik');
        if (!$nik) {
            return response()->json(['success' => false, 'message' => 'NIK wajib diisi.'], 422);
        }

        $nasabah = DataNasabah::where('nik', $nik)->first();
        if (!$nasabah) {
            return response()->json(['success' => false, 'message' => 'Nasabah belum terdaftar.'], 404);
        }

        $gadaiBerjalan = DetailGadai::where('nasabah_id', $nasabah->id)
            ->where('status', '!=', 'lunas')
            ->count();

        $riwayatGadai = DetailGadai::where('nasabah_id', $nasabah->id)
            ->with(['hp'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'nasabah'        => $nasabah,
                'total_gadai'    => $riwayatGadai->count(),
                'gadai_berjalan' => $gadaiBerjalan,
                'riwayat_gadai'  => $riwayatGadai,
            ]
        ]);
    }

    // ─── Store Gadai Ulang ────────────────────────────────────────────────
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nasabah.id'           => 'required|exists:data_nasabah,id',
            'barang.type_hp_id'    => 'required|exists:type_hp,id',
            'barang.grade_type'    => 'required|string',
            'detail.tanggal_gadai' => 'required|date',
            'detail.type_id'       => 'required|exists:types,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $this->checkLimitNasabah($request->input('nasabah.id'), $request->input('detail.type_id'));
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        // Deklarasi di luar try agar accessible setelah block
        $detail  = null;
        $nasabah = null;

        DB::beginTransaction();
        try {
            $nasabah     = DataNasabah::findOrFail($request->input('nasabah.id'));
            $detailInput = $request->input('detail');
            $barangInput = $request->input('barang');

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

            // 1. Simpan Barang & Sync Kerusakan dulu
            $barang = $this->processGadaiHp($barangInput, $detail->id);

            // 2. Baru hitung nilai (query sum kerusakan sudah valid)
            $nilai = $this->hitungNilaiBarang($barang);

            // 3. Update final nominal
            $detail->update([
                'taksiran'      => $nilai['taksiran'],
                'uang_pinjaman' => $nilai['pinjaman'],
            ]);
            $barang->update(['grade_nominal' => $nilai['pinjaman']]);

            $this->uploadDokumenSop($request, $barang, $nasabah, $detail);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[GadaiUlang] store() gagal: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        // ✅ Fix #2: Notif di luar try, non-blocking, tidak bisa rollback data
        $detailId   = $detail->id;
        $nasabahId  = $nasabah->id;
        $this->dispatchNotifSafely(function () use ($detailId, $nasabahId) {
            $freshDetail = DetailGadai::find($detailId);
            if (!$freshDetail) return;

            $total = DetailGadai::where('nasabah_id', $nasabahId)->count();
            $notif = new NotificationService();

            if ($total > 1) {
                $notif->notifyRepeatOrder($freshDetail, $total);
            } else {
                $notif->notifyNewTransaction($freshDetail);
            }
        });

        // Fresh query biar data response pasti up-to-date
        $responseData = DetailGadai::with('hp')->find($detailId);

        return response()->json([
            'success' => true,
            'message' => 'Gadai Ulang HP Berhasil Diproses',
            'data'    => $responseData,
        ], 201);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────

    private function checkLimitNasabah($nasabahId, $typeId)
    {
        $type = Type::find($typeId);
        $name = strtolower($type->nama_type ?? '');

        if (str_contains($name, 'hp') || str_contains($name, 'handphone')) {
            $berjalan = DetailGadai::where('nasabah_id', $nasabahId)
                ->where('type_id', $typeId)
                ->where('status', '!=', 'lunas')
                ->count();

            if ($berjalan >= 3) {
                throw new \Exception("Limit tercapai! Nasabah memiliki {$berjalan} unit HP yang belum lunas.");
            }
        }
    }

    private function generateNoGadai($tanggal, $typeId)
    {
        $tgl  = Carbon::parse($tanggal);
        $type = Type::findOrFail($typeId);

        $last   = DetailGadai::lockForUpdate()->orderBy('id', 'desc')->first();
        $noNum  = $last ? (int) substr($last->no_nasabah, -4) + 1 : 1;
        $suffix = str_pad($noNum, 4, '0', STR_PAD_LEFT);

        return [
            'no_nasabah' => $tgl->format('my') . $suffix,
            'no_gadai'   => "SGI-{$tgl->format('d-m-Y')}-{$type->nomor_type}-{$suffix}",
        ];
    }

    private function hitungNilaiBarang($barang)
    {
        $gradeData = GradeHp::whereHas('hargaHp', function ($q) use ($barang) {
            $q->where('type_hp_id', $barang->type_hp_id);
        })->first() ?? throw new \Exception("Master Harga/Grade belum di-setting.");

        $colPinjaman = 'grade_' . $barang->grade_type;

        $totalPersenKerusakan = DB::table('gadai_hp_kerusakan')
            ->join('kerusakan', 'kerusakan.id', '=', 'gadai_hp_kerusakan.kerusakan_id')
            ->where('gadai_hp_id', $barang->id)
            ->sum('kerusakan.persen') ?: 0;

        $calcService = new GradeCalculatorService();
        return $calcService->calculateFinalLoan($gradeData->$colPinjaman, (float) $totalPersenKerusakan);
    }

    private function processGadaiHp($input, $detailId)
    {
        $barang = GadaiHp::create([
            'detail_gadai_id' => $detailId,
            'nama_barang'     => $input['nama_barang'] ?? 'Smartphone',
            'merk_hp_id'      => $input['merk_hp_id'] ?? null,
            'type_hp_id'      => $input['type_hp_id'],
            'grade_type'      => strtolower(str_replace(['-', ' '], '_', $input['grade_type'])),
            'imei'            => $input['imei'] ?? null,
            'warna'           => $input['warna'] ?? null,
            'ram'             => $input['ram'] ?? null,
            'rom'             => $input['rom'] ?? null,
            'kunci_password'  => $input['kunci_password'] ?? null,
            'kunci_pin'       => $input['kunci_pin'] ?? null,
            'kunci_pola'      => $input['kunci_pola'] ?? null,
        ]);

        if (!empty($input['kerusakan']))  $barang->kerusakanList()->sync($input['kerusakan']);
        if (!empty($input['kelengkapan'])) $barang->kelengkapanList()->sync($input['kelengkapan']);

        return $barang;
    }

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
}