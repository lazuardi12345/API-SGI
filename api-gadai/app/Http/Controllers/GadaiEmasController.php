<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\DataNasabah;
use App\Models\DetailGadai;
use App\Models\GadaiLogamMulia;
use App\Models\GadaiPerhiasan;
use App\Models\GadaiRetro;
use App\Models\Type;
use App\Models\DokumenPendukungEmas;
use App\Services\NotificationService;
use App\Services\GadaiDateService;
use Carbon\Carbon;

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


    private function dispatchNotifSafely(callable $callback): void
    {
        dispatch(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::error('[NotifService] Gagal kirim notifikasi emas: ' . $e->getMessage());
            }
        })->afterResponse();
    }

    public function store(Request $request)
    {
        $detail = null;
        $petugasId = Auth::id();

        DB::beginTransaction();
        try {
            $user         = $request->user();
            $nasabahInput = $request->input('nasabah', []);
            $detailInput  = $request->input('detail', []);
            $barangInput  = $request->input('barang', []);

            $nasabah = DataNasabah::create([
                'user_id'      => $user->id,
                'nama_lengkap' => $nasabahInput['nama_lengkap'] ?? '',
                'nik'          => $nasabahInput['nik'] ?? '',
                'alamat'       => $nasabahInput['alamat'] ?? '',
                'no_hp'        => $nasabahInput['no_hp'] ?? '',
                'bank'         => $nasabahInput['bank'] ?? 'BCA',
                'no_rek'       => $nasabahInput['no_rek'] ?? '',
            ]);
            if ($request->hasFile('nasabah.foto_ktp')) {
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
                $file = $request->file('nasabah.foto_ktp');
                $filename = 'ktp_' . ($nasabah->nik ?? $nasabah->id) . '.' . $file->getClientOriginalExtension();
                $nasabah->foto_ktp = $file->storeAs($folderNasabah, $filename, 'minio');
                $nasabah->save();
            }
            $tanggalGadai = $detailInput['tanggal_gadai'] ?? now()->toDateString();
            $typeId       = $detailInput['type_id'];
            $noGadaiData  = $this->generateNoGadai($tanggalGadai, $typeId);

            $dateService   = new GadaiDateService();
            $tglJatuhTempo = $detailInput['jatuh_tempo']
                ?? $dateService->hitungJatuhTempoOtomatis($tanggalGadai, 15);
            $detail = DetailGadai::create([
                'no_gadai'      => $noGadaiData['no_gadai'],
                'no_nasabah'    => $noGadaiData['no_nasabah'],
                'nasabah_id'    => $nasabah->id,
                'tanggal_gadai' => $tanggalGadai,
                'jatuh_tempo'   => $tglJatuhTempo,
                'type_id'       => $typeId,
                'taksiran'      => (int) ($detailInput['taksiran'] ?? 0),
                'uang_pinjaman' => (int) ($detailInput['uang_pinjaman'] ?? 0),
                'status'        => 'proses',
            ]);
            $barangClass = self::MAP_MODEL[$typeId] ?? null;
            $jenisEmas   = self::MAP_JENIS_FOLDER[$typeId] ?? null;

            if (!$barangClass) {
                throw new \Exception("Tipe gadai emas tidak valid (ID: {$typeId})");
            }

            $barang                  = new $barangClass();
            $barang->detail_gadai_id = $detail->id;
            $barang->nama_barang     = $barangInput['nama_barang'] ?? '';
            $barang->kode_cap        = $barangInput['kode_cap'] ?? '';
            $barang->karat           = $barangInput['karat'] ?? '';
            $barang->potongan_batu   = $barangInput['potongan_batu'] ?? '';
            $barang->berat           = $barangInput['berat'] ?? '';
            $barang->save();
            $folderNasabahClean = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
            $folderBarang = "{$folderNasabahClean}/{$jenisEmas}/{$detail->no_gadai}";
            $dokumenPaths = [];

            foreach (self::DOKUMEN_SOP_EMAS as $field) {
                $file = $request->file("barang.dokumen_pendukung.{$field}");
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $filename = "{$field}_" . time() . '.' . $file->getClientOriginalExtension();
                    $dokumenPaths[$field] = $file->storeAs($folderBarang, $filename, 'minio');
                } else {
                    $dokumenPaths[$field] = null;
                }
            }

            DokumenPendukungEmas::create(array_merge(
                ['emas_id' => $barang->id, 'emas_type' => $jenisEmas],
                $dokumenPaths
            ));

            DB::commit();
            $detailId = $detail->id;
            $this->dispatchNotifSafely(function () use ($detailId, $petugasId) {
                $fresh = DetailGadai::with('nasabah')->find($detailId);
                if ($fresh) {
                    (new NotificationService())->notifyNewTransaction($fresh, $petugasId);
                }
            });

            return response()->json([
                'success'   => true,
                'message'   => 'Data gadai emas berhasil disimpan.',
                'detail_id' => $detail->id,
                'no_gadai'  => $detail->no_gadai,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[GadaiEmas] store() crash: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function generateNoGadai($tanggalGadai, $typeId)
    {
        $tgl  = Carbon::parse($tanggalGadai);
        $type = Type::findOrFail($typeId);
        $last = DetailGadai::whereDate('tanggal_gadai', $tgl->toDateString())
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->first();

        $next   = $last ? ((int) substr($last->no_gadai, -4)) + 1 : 1;
        $suffix = str_pad($next, 4, '0', STR_PAD_LEFT);
        $no_gadai   = "SGI-{$tgl->format('d-m-Y')}-{$type->nomor_type}-{$suffix}";
        $no_nasabah = $tgl->format('my') . $suffix;

        return compact('no_gadai', 'no_nasabah');
    }
}