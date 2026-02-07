<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator, Log};
use App\Models\{DataNasabah, DetailGadai, GadaiLogamMulia, GadaiRetro, GadaiPerhiasan, DokumenPendukungEmas, Type};
use App\Services\{GadaiDateService, NotificationService};
use Carbon\Carbon;

class GadaiUlangEmasController extends Controller
{
    const DOKUMEN_SOP_EMAS = [
        'emas_timbangan', 'gosokan_timer', 'gosokan_ktp', 'batu', 'cap_merek', 'karatase', 'ukuran_batu',
    ];

    const MAP_MODEL = [
        2 => GadaiLogamMulia::class,
        3 => GadaiRetro::class,
        4 => GadaiPerhiasan::class,
    ];

    const MAP_JENIS_FOLDER = [
        2 => 'logam_mulia',
        3 => 'retro',
        4 => 'perhiasan',
    ];

    public function checkNasabah(Request $request)
    {
        $nik = $request->input('nik');
        if (!$nik) return response()->json(['success' => false, 'message' => 'NIK wajib diisi.'], 422);

        $nasabah = DataNasabah::where('nik', $nik)->first();
        if (!$nasabah) return response()->json(['success' => false, 'message' => 'Nasabah tidak ditemukan.'], 404);

        $riwayat = DetailGadai::where('nasabah_id', $nasabah->id)
            ->with(['logamMulia', 'retro', 'perhiasan'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'nasabah' => $nasabah,
                'total_gadai' => $riwayat->count(),
                'riwayat_gadai' => $riwayat
            ]
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nasabah.nik'          => 'required|exists:data_nasabah,nik',
            'detail.tanggal_gadai' => 'required|date',
            'detail.type_id'       => 'required|in:2,3,4',
            'barang.nama_barang'   => 'required|string',
        ]);

        if ($validator->fails()) return response()->json(['success' => false, 'errors' => $validator->errors()], 422);

        DB::beginTransaction();
        try {
            $nasabah     = DataNasabah::where('nik', $request->input('nasabah.nik'))->first();
            $detailInput = $request->input('detail');
            $barangInput = $request->input('barang');

            // 1. GENERATE TANGGAL & NOMOR (SOP 15 HARI)
            $dateService   = new GadaiDateService();
            $tglJatuhTempo = $dateService->hitungJatuhTempoOtomatis($detailInput['tanggal_gadai'], 15);
            $noGadaiData   = $this->generateNoGadai($detailInput['tanggal_gadai'], $detailInput['type_id']);

            // 2. CREATE DETAIL GADAI (STATUS: PROSES)
            $detail = DetailGadai::create([
                'no_gadai'      => $noGadaiData['no_gadai'],
                'no_nasabah'    => $noGadaiData['no_nasabah'],
                'nasabah_id'    => $nasabah->id,
                'tanggal_gadai' => $detailInput['tanggal_gadai'],
                'jatuh_tempo'   => $tglJatuhTempo,
                'type_id'       => $detailInput['type_id'],
                'taksiran'      => $detailInput['taksiran'] ?? 0,
                'uang_pinjaman' => $detailInput['uang_pinjaman'] ?? 0,
                'status'        => 'proses', 
            ]);

            // 3. CREATE DATA BARANG
            $barang = $this->saveSpesifikEmas($detail, $barangInput);

            // 4. UPLOAD DOKUMEN SOP
            $this->uploadDokumenEmas($request, $barang, $nasabah, $detail);

            DB::commit();
            $this->sendNotification($nasabah, $detail);

            return response()->json(['success' => true, 'message' => "Gadai Ulang Emas berhasil dikirim (Status: PROSES)."], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Gadai Ulang Emas Fail: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function generateNoGadai($tanggal, $typeId)
    {
        $tgl = Carbon::parse($tanggal);
        $type = Type::find($typeId);
        $last = DetailGadai::lockForUpdate()->orderBy('id', 'desc')->first();
        $noNum = $last ? (int) substr($last->no_nasabah, -4) + 1 : 1;
        $suffix = str_pad($noNum, 4, '0', STR_PAD_LEFT);

        return [
            'no_nasabah' => $tgl->format('my') . $suffix,
            'no_gadai'   => "SGI-{$tgl->format('d-m-Y')}-" . ($type->nomor_type ?? 'EMAS') . "-{$suffix}"
        ];
    }

    private function saveSpesifikEmas($detail, $input)
    {
        $class = self::MAP_MODEL[$detail->type_id];
        return $class::create([
            'detail_gadai_id' => $detail->id,
            'nama_barang'     => $input['nama_barang'],
            'kode_cap'        => $input['kode_cap'] ?? null,
            'karat'           => $input['karat'] ?? null,
            'potongan_batu'   => $input['potongan_batu'] ?? 0,
            'berat'           => $input['berat'] ?? 0,
        ]);
    }

    private function uploadDokumenEmas($request, $barang, $nasabah, $detail)
    {
        $jenisFolder = self::MAP_JENIS_FOLDER[$detail->type_id];
        $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
        $folderPath = "{$folderNasabah}/{$jenisFolder}/{$detail->no_gadai}";
        
        $paths = [];
        foreach (self::DOKUMEN_SOP_EMAS as $field) {
            if ($request->hasFile("barang.dokumen_pendukung.$field")) {
                $file = $request->file("barang.dokumen_pendukung.$field");
                $filename = "{$field}_" . time() . '.' . $file->getClientOriginalExtension();
                $paths[$field] = $file->storeAs($folderPath, $filename, 'minio');
            }
        }

        if (!empty($paths)) {
            DokumenPendukungEmas::create(array_merge([
                'emas_id'   => $barang->id,
                'emas_type' => $jenisFolder,
            ], $paths));
        }
    }

    private function sendNotification($nasabah, $detail)
    {
        try {
            $notif = app(NotificationService::class);
            $count = DetailGadai::where('nasabah_id', $nasabah->id)->count();
            ($count > 1) ? $notif->notifyRepeatOrder($detail, $count) : $notif->notifyNewTransaction($detail);
        } catch (\Exception $e) { Log::warning("Notif Fail: " . $e->getMessage()); }
    }
}