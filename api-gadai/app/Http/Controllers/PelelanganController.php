<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetailGadai;
use App\Models\Pelelangan;
use App\Services\NotificationService; 
use App\Traits\KalkulatorGadaiTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PelelanganController extends Controller
{
    use KalkulatorGadaiTrait;

    protected $notificationService; 

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function index()
    {
        $today = Carbon::today();
        $batasMinimalLelang = $today->copy()->subDays(15);

        $lelangables = DetailGadai::with(['nasabah', 'type', 'pelelangan', 'perpanjanganTempos'])
            ->whereNotIn('status', ['lunas'])
            ->whereDate('jatuh_tempo', '<=', $batasMinimalLelang)
            ->where(function($q) {
                $q->whereHas('pelelangan', function($query) {
                    $query->where('status_lelang', 'siap');
                })
                ->orWhereDoesntHave('pelelangan');
            })
            ->get()
            ->map(function ($d) {
                // ✅ Gunakan fungsi baru dari trait
                $kalkulasi = $this->hitungTotalTagihanLelang($d);

                return [
                    'id' => $d->id,
                    'pelelangan_id' => $d->pelelangan->id ?? null,
                    'no_gadai' => $d->no_gadai,
                    'nama_nasabah' => $d->nasabah->nama_lengkap ?? '-',
                    'type' => $d->type->nama_type ?? '-',
                    'tanggal_gadai' => $d->tanggal_gadai,
                    'jatuh_tempo' => $kalkulasi['jatuh_tempo_used'], 
                    'hari_terlambat' => $kalkulasi['hari_terlambat'],
                    'uang_pinjaman' => $kalkulasi['pokok'],
                    'biaya_jasa' => $kalkulasi['biaya_jasa'], // ✅ Tambah biaya jasa
                    'penalty' => $kalkulasi['penalty'],
                    'denda' => $kalkulasi['denda'],
                    'total_hutang' => $kalkulasi['total_hutang'],
                    'status_lelang' => $d->pelelangan ? $d->pelelangan->status_lelang : 'belum_terdaftar',
                ];
            });

        return response()->json([
            'success' => true,
            'total_data' => $lelangables->count(),
            'data' => $lelangables
        ]);
    }

    public function prosesLelang(Request $request, $detailGadaiId)
    {
        $request->validate([
            'nominal_diterima' => 'required|numeric|min:1',
            'metode_pembayaran' => 'required|in:cash,transfer',
            'bukti_transfer' => 'required_if:metode_pembayaran,transfer|image|max:2048',
        ]);

        DB::beginTransaction();
        try {
            $pelelangan = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type', 'detailGadai.perpanjanganTempos'])
                ->where('detail_gadai_id', $detailGadaiId)
                ->firstOrFail();
            
            $gadai = $pelelangan->detailGadai;
            
            // ✅ Gunakan fungsi baru dari trait
            $kalkulasi = $this->hitungTotalTagihanLelang($gadai);
            
            $nominalDiterima = (float) $request->nominal_diterima;
            $keuntungan = $nominalDiterima - $kalkulasi['total_hutang'];

            $pathMinio = $pelelangan->bukti_transfer;
            if ($request->hasFile('bukti_transfer')) {
                $nasabah = $gadai->nasabah;
                $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
                $tipe = strtolower($gadai->type->nama_type ?? 'umum');
                $folderBase = "{$folderNasabah}/{$tipe}/{$gadai->no_gadai}/pelelangan";
                
                $pathMinio = $request->file('bukti_transfer')->storeAs(
                    $folderBase, 
                    "bukti-lelang-" . time() . "." . $request->file('bukti_transfer')->getClientOriginalExtension(), 
                    'minio'
                );
            }

            $pelelangan->update([
                'status_lelang' => 'terlelang',
                'nominal_diterima' => $nominalDiterima,
                'keuntungan_lelang' => $keuntungan,
                'metode_pembayaran' => $request->metode_pembayaran,
                'bukti_transfer' => $pathMinio,
                'waktu_bayar' => now(),
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Barang berhasil dilelang!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function daftarkanLelang(Request $request)
    {
        $request->validate([
            'detail_gadai_id' => 'required|exists:detail_gadai,id',
        ]);

        $detail = DetailGadai::find($request->detail_gadai_id);

        if ($detail->pelelangan) {
            return response()->json([
                'success' => false,
                'message' => 'Barang sudah terdaftar di lelang'
            ], 400);
        }

        $pelelangan = Pelelangan::create([
            'detail_gadai_id' => $detail->id,
            'status_lelang' => 'siap',
            'keterangan' => 'Barang terdaftar untuk dilelang',
        ]);

        $this->notificationService->notifyBarangLelang($pelelangan);

        return response()->json([
            'success' => true,
            'message' => 'Barang berhasil masuk daftar lelang & Notifikasi terkirim',
            'data' => $pelelangan
        ]);
    }

    public function history()
    {
        $history = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type', 'detailGadai.perpanjanganTempos'])
            ->orderBy('waktu_bayar', 'desc')
            ->get()
            ->map(function ($p) {
                $gadai = $p->detailGadai;
                
                // ✅ Gunakan fungsi baru dari trait dengan waktu bayar sebagai acuan
                $kalkulasi = $this->hitungTotalTagihanLelang($gadai, $p->waktu_bayar);

                return [
                    'id' => $p->id,
                    'detail_gadai_id' => $gadai->id, // ✅ Tambahkan ini untuk struk
                    'no_gadai' => $gadai->no_gadai ?? '-',
                    'nama_nasabah' => $gadai->nasabah->nama_lengkap ?? '-',
                    'type' => $gadai->type->nama_type ?? '-',
                    'tanggal_gadai' => $gadai->tanggal_gadai ?? '-',
                    'jatuh_tempo' => $kalkulasi['jatuh_tempo_used'],
                    'hari_terlambat' => $kalkulasi['hari_terlambat'],
                    'uang_pinjaman' => $kalkulasi['pokok'],
                    'biaya_jasa' => $kalkulasi['biaya_jasa'], // ✅ Tambah biaya jasa
                    'nominal_denda' => $kalkulasi['denda'],
                    'nominal_penalty' => $kalkulasi['penalty'],
                    'total_hutang' => $kalkulasi['total_hutang'],
                    'harga_terjual' => $p->nominal_diterima,
                    'keuntungan_lelang' => $p->keuntungan_lelang, // ✅ Tambahkan keuntungan
                    'tanggal_dilelang' => $p->waktu_bayar,
                    'status_lelang' => $p->status_lelang,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }


public function show($detailGadaiId)
{
    try {
        $pelelangan = Pelelangan::with([
            'detailGadai.nasabah',
            'detailGadai.type',
            'detailGadai.perpanjanganTempos',
            'detailGadai.hp.merk',
            'detailGadai.hp.type_hp',
            'detailGadai.perhiasan.kelengkapan',
            'detailGadai.logamMulia.kelengkapanEmas',
            'detailGadai.retro.kelengkapan'
        ])
        ->where('detail_gadai_id', $detailGadaiId)
        ->firstOrFail();

        $gadai = $pelelangan->detailGadai;
        $kalkulasi = $this->hitungTotalTagihanLelang($gadai, $pelelangan->waktu_bayar);
        $detailBarang = [
            'nama_barang' => '-',
            'brand'       => '-',
            'tipe'        => '-',
            'atribut'     => [], 
        ];

        if ($gadai->hp) {
            $detailBarang['nama_barang'] = $gadai->hp->nama_barang;
            $detailBarang['brand'] = $gadai->hp->merk->nama_merk ?? '-';
            $detailBarang['tipe']  = $gadai->hp->type_hp->nama_type ?? '-';
            $detailBarang['atribut'] = [
                'RAM/ROM' => "{$gadai->hp->ram}/{$gadai->hp->rom} GB",
                'IMEI'    => $gadai->hp->imei,
                'Warna'   => $gadai->hp->warna,
            ];
        } elseif ($gadai->perhiasan) {
            $detailBarang['nama_barang'] = $gadai->perhiasan->nama_barang;
            $detailBarang['tipe'] = 'Perhiasan';
            $detailBarang['atribut'] = [
                'Berat Kotor' => "{$gadai->perhiasan->berat_kotor} gr",
                'Berat Bersih' => "{$gadai->perhiasan->berat_bersih} gr",
                'Karat' => "{$gadai->perhiasan->karat}K",
                'Kelengkapan' => $gadai->perhiasan->kelengkapan->nama_kelengkapan ?? '-',
            ];
        } elseif ($gadai->logamMulia) {
            $detailBarang['nama_barang'] = $gadai->logamMulia->nama_barang;
            $detailBarang['tipe'] = 'Logam Mulia';
            $detailBarang['atribut'] = [
                'Berat' => "{$gadai->logamMulia->berat} gr",
                'Kadar' => "{$gadai->logamMulia->kadar}%",
                'Sertifikat' => $gadai->logamMulia->sertifikat ?? 'Tidak Ada',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'pelelangan' => $pelelangan,
                'detail_barang_formatted' => $detailBarang, 
                'kalkulasi' => [
                    'pokok' => $kalkulasi['pokok'],
                    'bunga' => $kalkulasi['biaya_jasa'], 
                    'denda' => $kalkulasi['denda'],
                    'penalty' => $kalkulasi['penalty'],
                    'total_hutang' => $kalkulasi['total_hutang'],
                    'hari_terlambat' => $kalkulasi['hari_terlambat'],
                ]
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
    }
}


public function lunasi(Request $request, $detailGadaiId)
{
    $request->validate([
        'nominal_diterima' => 'required|numeric|min:1',
        'metode_pembayaran' => 'required|in:cash,transfer',
        'bukti_transfer' => 'required_if:metode_pembayaran,transfer|image|max:2048',
        'keterangan' => 'nullable|string|max:500'
    ]);

    DB::beginTransaction();
    try {
        $pelelangan = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type', 'detailGadai.perpanjanganTempos'])
            ->where('detail_gadai_id', $detailGadaiId)
            ->firstOrFail();
        
        $gadai = $pelelangan->detailGadai;
        
        // Hitung total tagihan
        $kalkulasi = $this->hitungTotalTagihanLelang($gadai);
        
        $nominalDiterima = (float) $request->nominal_diterima;

        // Handle upload bukti transfer
        $pathMinio = $pelelangan->bukti_transfer;
        if ($request->hasFile('bukti_transfer')) {
            $nasabah = $gadai->nasabah;
            $folderNasabah = preg_replace('/[^A-Za-z0-9\-]/', '_', $nasabah->nama_lengkap);
            $tipe = strtolower($gadai->type->nama_type ?? 'umum');
            $folderBase = "{$folderNasabah}/{$tipe}/{$gadai->no_gadai}/pelelangan";
            
            $pathMinio = $request->file('bukti_transfer')->storeAs(
                $folderBase, 
                "bukti-pelunasan-" . time() . "." . $request->file('bukti_transfer')->getClientOriginalExtension(), 
                'minio'
            );
        }

        // Update pelelangan dengan status lunas
        $pelelangan->update([
            'status_lelang' => 'lunas',
            'nominal_diterima' => $nominalDiterima,
            'keuntungan_lelang' => 0, // Tidak ada keuntungan karena ditebus
            'metode_pembayaran' => $request->metode_pembayaran,
            'bukti_transfer' => $pathMinio,
            'waktu_bayar' => now(),
            'keterangan' => $request->keterangan ?? 'Barang ditebus oleh nasabah'
        ]);

        // Update status detail gadai menjadi lunas
        $gadai->update([
            'status' => 'lunas',
            'nominal_bayar' => $nominalDiterima,
            'tanggal_bayar' => now(),
            'metode_pembayaran' => $request->metode_pembayaran
        ]);

        DB::commit();
        return response()->json([
            'success' => true, 
            'message' => 'Pelunasan berhasil! Barang dapat diserahkan ke nasabah.'
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false, 
            'message' => $e->getMessage()
        ], 500);
    }


}

}