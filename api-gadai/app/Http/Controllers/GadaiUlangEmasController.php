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

class GadaiUlangEmasController extends Controller
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

        if (!$nik) {
            return response()->json([
                'success' => false,
                'message' => 'NIK wajib diisi.',
            ], 422);
        }

        $nasabah = DataNasabah::where('nik', $nik)->first();

        if (!$nasabah) {
            return response()->json([
                'success' => false,
                'message' => 'Nasabah tidak ditemukan.',
            ], 404);
        }

        $totalGadai = DetailGadai::where('nasabah_id', $nasabah->id)->count();
        
        $riwayatGadai = DetailGadai::where('nasabah_id', $nasabah->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Nasabah ditemukan.',
            'data' => [
                'nasabah' => $nasabah,
                'total_gadai' => $totalGadai,
                'riwayat_gadai' => $riwayatGadai
            ]
        ]);
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $user = $request->user();
            $nik = $request->input('nasabah.nik');
            
            if (!$nik) {
                return response()->json([
                    'success' => false,
                    'message' => 'NIK wajib diisi untuk gadai ulang.',
                ], 422);
            }

            $nasabah = DataNasabah::where('nik', $nik)->first();

            if (!$nasabah) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data nasabah dengan NIK tersebut tidak ditemukan.',
                ], 404);
            }

            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);

            // STEP 1: DETAIL GADAI BARU
            $detailInput = $request->input('detail', []);

            $lastDetail = DetailGadai::lockForUpdate()->orderBy('id', 'desc')->first();
            $next = $lastDetail ? (int) substr($lastDetail->no_nasabah, -4) + 1 : 1;

            $noNasabah = date('m') . substr(date('Y'), 2) . str_pad($next, 4, '0', STR_PAD_LEFT);
            $noGadai   = "SGI-" . date('d-m-Y') . "-" . str_pad($next, 4, '0', STR_PAD_LEFT);

            $detail = DetailGadai::create([
                'no_gadai'      => $noGadai,
                'no_nasabah'    => $noNasabah,
                'nasabah_id'    => $nasabah->id,
                'tanggal_gadai' => $detailInput['tanggal_gadai'] ?? now(),
                'jatuh_tempo'   => $detailInput['jatuh_tempo'] ?? now()->addDays(15),
                'type_id'       => $detailInput['type_id'],
                'taksiran'      => $detailInput['taksiran'] ?? 0,
                'uang_pinjaman' => $detailInput['uang_pinjaman'] ?? 0,
                'status'        => 'proses',
            ]);

            // STEP 2: BARANG EMAS
            $barangInput = $request->input('barang', []);
            $typeId = $detail->type_id;

            $barangClass = self::MAP_MODEL[$typeId] ?? null;
            $jenisEmas   = self::MAP_JENIS_FOLDER[$typeId] ?? 'unknown';

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


            // STEP 3: DOKUMEN PENDUKUNG EMAS
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


            $nasabah->load('user');
            
            $dokumenFinal = [];
            $dokumen = DokumenPendukungEmas::where('emas_id', $barang->id)
                ->where('emas_type', $jenisEmas)
                ->first();

            if ($dokumen) {
                foreach ($dokumen->getAttributes() as $k => $v) {
                    if ($v && !in_array($k, ['id', 'emas_id', 'emas_type', 'created_at', 'updated_at'])) {
                        $dokumenFinal[$k] = $this->convertPathToUrl($v);
                    }
                }
            }

            $totalGadaiSekarang = DetailGadai::where('nasabah_id', $nasabah->id)->count();

            return response()->json([
                'success' => true,
                'message' => 'Data gadai ulang emas berhasil disimpan.',
                'data' => [
                    'nasabah' => $nasabah,
                    'detail' => $detail,
                    'barang' => $barang,
                    'dokumen' => $dokumenFinal,
                    'total_gadai' => $totalGadaiSekarang,
                    'info' => "Ini adalah gadai ke-{$totalGadaiSekarang} untuk nasabah {$nasabah->nama_lengkap}"
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function convertPathToUrl($path)
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;

        $path = ltrim($path, '/');
        $path = str_replace('..', '', $path);

        return url("api/files/{$path}");
    }
}