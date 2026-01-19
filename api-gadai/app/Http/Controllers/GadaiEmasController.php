<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\DataNasabah;
use App\Models\DetailGadai;
use App\Models\GadaiLogamMulia;
use App\Models\GadaiPerhiasan;
use App\Models\GadaiRetro;
use App\Models\DokumenPendukungEmas;

class GadaiEmasController extends Controller
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

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $nasabahInput = $request->input('nasabah', []);

            // =========================
            // STEP 1: NASABAH
            // =========================
          $nasabah = DataNasabah::create([
            'user_id'      => $user->id,
            'nama_lengkap' => $nasabahInput['nama_lengkap'] ?? '',
            'nik'          => $nasabahInput['nik'] ?? '',
            'alamat'       => $nasabahInput['alamat'] ?? '',
            'no_hp'        => $nasabahInput['no_hp'] ?? '',
            'bank'         => $nasabahInput['bank'] ?? 'BCA', 
            'no_rek'  => $nasabahInput['no_rek'] ?? '',
        ]);

            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);

            // Upload foto KTP nasabah
            if ($request->hasFile('nasabah.foto_ktp')) {
                $file = $request->file('nasabah.foto_ktp');
                $filename = 'ktp_' . ($nasabah->nik ?? $nasabah->id) . '.' . $file->getClientOriginalExtension();
                $nasabah->foto_ktp = $file->storeAs($folderNasabah, $filename, 'minio');
                $nasabah->save();
            }

            // =========================
            // STEP 2: DETAIL GADAI
            // =========================
            $detailInput = $request->input('detail', []);

// Ambil tanggal hari ini untuk filter
$today = now()->format('Y-m-d');

// Cari urutan terakhir KHUSUS untuk hari ini saja agar SGI-DD-MM-YYYY tidak duplikat
$lastDetailToday = DetailGadai::whereDate('created_at', $today)
    ->lockForUpdate()
    ->orderBy('id', 'desc')
    ->first();

if ($lastDetailToday) {
    // Ambil 4 digit terakhir dari no_gadai aslinya, lalu tambah 1
    $lastIncrement = (int) substr($lastDetailToday->no_gadai, -4);
    $next = $lastIncrement + 1;
} else {
    $next = 1;
}

// Format No Nasabah: MMyyXXXX
$noNasabah = date('m') . substr(date('Y'), 2) . str_pad($next, 4, '0', STR_PAD_LEFT);
// Format No Gadai: SGI-DD-MM-YYYY-XXXX
$noGadai   = "SGI-" . date('d-m-Y') . "-" . str_pad($next, 4, '0', STR_PAD_LEFT);

// DOUBLE CHECK: Pastikan nomor ini belum benar-benar ada di DB (Safety Net)
$exists = DetailGadai::where('no_gadai', $noGadai)->exists();
if ($exists) {
    // Jika masih ada (karena tabrakan race condition), naikkan terus sampai dapat yang kosong
    while (DetailGadai::where('no_gadai', $noGadai)->exists()) {
        $next++;
        $noGadai = "SGI-" . date('d-m-Y') . "-" . str_pad($next, 4, '0', STR_PAD_LEFT);
        $noNasabah = date('m') . substr(date('Y'), 2) . str_pad($next, 4, '0', STR_PAD_LEFT);
    }
}

$detail = DetailGadai::create([
    'no_gadai'      => $noGadai,
    'no_nasabah'    => $noNasabah,
    'nasabah_id'    => $nasabah->id,
    'tanggal_gadai' => $detailInput['tanggal_gadai'] ?? now(),
    'jatuh_tempo'   => $detailInput['jatuh_tempo'] ?? now()->addDays(15),
    'type_id'       => $detailInput['type_id'],
    'taksiran'      => (int) ($detailInput['taksiran'] ?? 0),
    'uang_pinjaman' => (int) ($detailInput['uang_pinjaman'] ?? 0),
    'status'        => 'proses',
]);

            // =========================
            // STEP 3: BARANG EMAS
            // =========================
            $barangInput = $request->input('barang', []);
            $typeId = $detail->type_id;

            $mapModel = [
                2 => GadaiLogamMulia::class,
                3 => GadaiRetro::class,
                4 => GadaiPerhiasan::class,
            ];

            $mapJenisFolder = [
                2 => 'logam_mulia',
                3 => 'retro',
                4 => 'perhiasan',
            ];

            $barangClass = $mapModel[$typeId] ?? null;
            $jenisEmas   = $mapJenisFolder[$typeId] ?? 'unknown';

            if (!$barangClass) {
                throw new \Exception("Tipe gadai tidak valid");
            }

            $barang = new $barangClass();
            $barang->detail_gadai_id = $detail->id;
            $barang->nama_barang     = $barangInput['nama_barang'] ?? '';
            $barang->kode_cap        = $barangInput['kode_cap'] ?? '';
            $barang->karat           = $barangInput['karat'] ?? '';
            $barang->potongan_batu   = $barangInput['potongan_batu'] ?? '';
            $barang->berat           = $barangInput['berat'] ?? '';
            $barang->save();

            // =========================
            // STEP 4: DOKUMEN PENDUKUNG EMAS
            // =========================
            $folderBarang = "{$folderNasabah}/{$jenisEmas}/{$detail->no_gadai}";
            $dokumenPaths = [];

            foreach (self::DOKUMEN_SOP_EMAS as $field) {
                $file = $request->file("barang.dokumen_pendukung.$field");
                if ($file) {
                    $filename = "{$field}_" . ($nasabah->nik ?? $nasabah->id) . '.' . $file->getClientOriginalExtension();
                    $dokumenPaths[$field] = $file->storeAs($folderBarang, $filename, 'minio');
                } else {
                    $dokumenPaths[$field] = null;
                }
            }

            DokumenPendukungEmas::create(array_merge(
                [
                    'emas_id'   => $barang->id,
                    'emas_type' => $jenisEmas,
                ],
                $dokumenPaths
            ));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data gadai emas berhasil disimpan.',
                'detail_id' => $detail->id,
                'barang_id' => $barang->id,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
