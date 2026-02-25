<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetailGadai;
use App\Models\Pelelangan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{


public function getFullDashboard(Request $request)
{
    try {
        $tahunSekarang = $request->query('tahun', now()->year);
        $bulanSekarang = now()->month;
        $terakhir = DB::table('transaksi_brankas')
            ->orderBy('id', 'desc')
            ->first();
        $stats = DB::table('transaksi_brankas')
            ->selectRaw("
                SUM(CASE WHEN kategori = 'topup_pusat' AND status_validasi = 'tervalidasi' THEN pemasukan ELSE 0 END) as modal_pusat,
                SUM(CASE WHEN kategori = 'setor_ke_admin' AND status_validasi = 'tervalidasi' THEN pengeluaran ELSE 0 END) as setoran_lunas,
                SUM(CASE WHEN kategori = 'setor_ke_admin' AND status_validasi = 'pending' THEN pengeluaran ELSE 0 END) as setoran_pending
            ")
            ->first();

        $rekapBulanan = DB::table('transaksi_brankas')
            ->selectRaw("SUM(pemasukan) as masuk, SUM(pengeluaran) as keluar")
            ->whereMonth('created_at', $bulanSekarang)
            ->whereYear('created_at', $tahunSekarang)
            ->where('status_validasi', 'tervalidasi')
            ->first();
        $mutasiBulanan = DB::table('transaksi_brankas')
            ->select(
                DB::raw('MONTH(created_at) as bulan'),
                DB::raw('SUM(pemasukan) as total_masuk'),
                DB::raw('SUM(pengeluaran) as total_keluar')
            )
            ->whereYear('created_at', $tahunSekarang)
            ->where('status_validasi', 'tervalidasi') 
            ->groupBy(DB::raw('MONTH(created_at)'))
            ->orderBy('bulan', 'asc')
            ->get();

        $pemasukanArr = array_fill(0, 12, 0);
        $pengeluaranArr = array_fill(0, 12, 0);
        $saldoBulananArr = array_fill(0, 12, 0);

        foreach ($mutasiBulanan as $data) {
            $pemasukanArr[$data->bulan - 1] = (int)$data->total_masuk;
            $pengeluaranArr[$data->bulan - 1] = (int)$data->total_keluar;
        }

        $currentSaldo = 0;
        for ($i = 0; $i < 12; $i++) {
            $currentSaldo += ($pemasukanArr[$i] - $pengeluaranArr[$i]);
            $saldoBulananArr[$i] = $currentSaldo;
        }


        return response()->json([
            'success' => true,
            'summary' => [
                'saldo_toko_saat_ini' => (int) ($terakhir->saldo_akhir ?? 0),
                'saldo_rekening_saat_ini' => (int) ($terakhir->saldo_akhir_rekening ?? 0),
                'total_modal_dari_pusat' => (int) ($stats->modal_pusat ?? 0),
                'total_setoran_ke_admin' => (int) ($stats->setoran_lunas ?? 0), 
                'total_setoran_pending' => (int) ($stats->setoran_pending ?? 0), 
                'total_pemasukan_bulan_ini' => (int) ($rekapBulanan->masuk ?? 0),
                'total_pengeluaran_bulan_ini' => (int) ($rekapBulanan->keluar ?? 0),
            ],
            'chart' => [
                'pemasukan' => $pemasukanArr,
                'pengeluaran' => $pengeluaranArr,
                'saldo_kumulatif' => $saldoBulananArr,
                'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
            ],
            'info' => [
                'bulan_aktif' => now()->locale('id')->getTranslatedMonthName(),
                'tahun_aktif' => (int) $tahunSekarang
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal memuat data dashboard: ' . $e->getMessage()
        ], 500);
    }
}




}
