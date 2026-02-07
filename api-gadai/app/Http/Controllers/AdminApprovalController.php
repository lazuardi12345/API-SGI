<?php

namespace App\Http\Controllers;

use App\Models\DetailGadai;
use App\Models\PerpanjanganTempo;
use App\Models\Pelelangan;
use App\Traits\KalkulatorGadaiTrait; // 1. Import Trait
use App\Services\StrukAwalService;
use App\Services\PerpanjanganService;
use App\Services\PelunasanService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AdminApprovalController extends Controller
{
    use KalkulatorGadaiTrait; // 2. Gunakan Trait di sini

    public function laporanMingguan(Request $request)
    {
        try {
            // Parsing Tanggal
            $start = $request->filled('start_date') 
                ? Carbon::parse($request->get('start_date'))->startOfDay() 
                : Carbon::now()->startOfWeek();
            $end = $request->filled('end_date') 
                ? Carbon::parse($request->get('end_date'))->endOfDay() 
                : Carbon::now()->endOfWeek();

            // 1. GADAI BARU
            $gadaiBaru = DetailGadai::with(['nasabah', 'type'])
                ->whereBetween('tanggal_gadai', [$start, $end])
                ->get()
                ->map(function ($d) {
                    $service = new StrukAwalService();
                    $k = $service->hitungStrukAwal($d);
                    return [
                        'no_gadai'       => $d->no_gadai,
                        'nasabah'        => $d->nasabah->nama_lengkap ?? "Tanpa Nama",
                        'pokok_pinjaman' => (float)$k['pokok'],
                        'jasa_sewa'      => (float)$k['jasa_sewa'],
                        'admin'          => (float)$k['administrasi'],
                        'asuransi'       => (float)$k['asuransi'],
                        'total_diterima' => (float)$k['total_diterima'],
                        'type'           => 'gadai_baru'
                    ];
                });

            // 2. PERPANJANGAN
            $perpanjangan = PerpanjanganTempo::with('detailGadai.nasabah', 'detailGadai.type')
                ->whereBetween('created_at', [$start, $end])
                ->get()
                ->map(function ($p) {
                    if (!$p->detailGadai) return null;
                    $service = new PerpanjanganService();
                    $k = $service->hitungPerpanjangan($p->detailGadai, $p->created_at, $p->jatuh_tempo_baru);
                    return [
                        'no_gadai'    => $p->detailGadai->no_gadai,
                        'nasabah'     => $p->detailGadai->nasabah->nama_lengkap ?? "Tanpa Nama",
                        'jasa'        => (float)$k['jasa_perpanjangan'],
                        'denda'       => (float)$k['denda_telat'],
                        'admin'       => (float)$k['nominal_admin'],
                        'penalty'     => (float)$k['penalty'],
                        'total_bayar' => (float)$k['total_bayar'],
                        'type'        => 'perpanjangan'
                    ];
                })->filter()->values();

            // 3. PELUNASAN
            $pelunasan = DetailGadai::with(['nasabah', 'type'])
                ->where('status', 'lunas')
                ->whereBetween('tanggal_bayar', [$start, $end])
                ->get()
                ->map(function ($d) {
                    $service = new PelunasanService();
                    $k = $service->hitungPelunasan($d);
                    return [
                        'no_gadai'      => $d->no_gadai,
                        'nasabah'       => $d->nasabah->nama_lengkap ?? "Tanpa Nama",
                        'pokok'         => (float)$k['pokok'],
                        'denda'         => (float)$k['denda'],
                        'penalty'       => (float)$k['penalty'],
                        'total_dibayar' => (float)$k['total_bayar'],
                        'type'          => 'pelunasan'
                    ];
                });

            // 4. PELELANGAN
            $pelelangan = Pelelangan::with(['detailGadai.nasabah', 'detailGadai.type'])
                ->where('status_lelang', 'terlelang')
                ->whereBetween('waktu_bayar', [$start, $end])
                ->get()
                ->map(function ($p) {
                    if (!$p->detailGadai) return null;
                    
                    $k = $this->hitungTotalTagihanLelang($p->detailGadai, $p->waktu_bayar);
                    
                    return [
                        'no_gadai'      => $p->detailGadai->no_gadai,
                        'nasabah'       => $p->detailGadai->nasabah->nama_lengkap ?? "Tanpa Nama",
                        'total_hutang'  => (float)$k['total_hutang'],
                        'harga_terjual' => (float)$p->nominal_diterima,
                        'profit_loss'   => (float)$p->keuntungan_lelang,
                        'type'          => 'pelelangan'
                    ];
                })->filter()->values();

            // Ringkasan Akhir
            $ringkasan = [
                'gadai_baru'   => ['total_transaksi' => $gadaiBaru->count(), 'total_nominal' => $gadaiBaru->sum('pokok_pinjaman')],
                'perpanjangan' => ['total_transaksi' => $perpanjangan->count(), 'total_nominal' => $perpanjangan->sum('total_bayar')],
                'pelunasan'    => ['total_transaksi' => $pelunasan->count(), 'total_nominal' => $pelunasan->sum('total_dibayar')],
                'pelelangan'   => ['total_transaksi' => $pelelangan->count(), 'total_nominal' => $pelelangan->sum('harga_terjual')],
            ];

            return response()->json([
                'success' => true,
                'periode' => ['start' => $start->format('d/m/Y'), 'end' => $end->format('d/m/Y')],
                'data'    => [
                    'gadai_baru'   => $gadaiBaru,
                    'perpanjangan' => $perpanjangan,
                    'pelunasan'    => $pelunasan,
                    'pelelangan'   => $pelelangan,
                    'ringkasan'    => $ringkasan,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error("Error Laporan Mingguan: " . $e->getMessage());
            return response()->json([
                'success' => false, 
                'message' => "Terjadi kesalahan: " . $e->getMessage()
            ], 500);
        }
    }
}