<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AdminLaporanMingguanController extends Controller
{

   public function cetakLaporanMingguan(Request $request)
{
    try {
        $tanggalInput = $request->get('tanggal') ?? Carbon::today()->toDateString();
        $date = Carbon::parse($tanggalInput);
        
        $startDate = $date->startOfWeek()->toDateString();
        $endDate = $date->endOfWeek()->toDateString();     
        $laporanTabel = [];
        $no = 1;
        $grandTotalDebet = 0;  
        $grandTotalKredit = 0;
        $gadaiBaru = DB::table('detail_gadai')
            ->join('types', 'detail_gadai.type_id', '=', 'types.id')
            ->select(
                DB::raw('DATE(detail_gadai.tanggal_gadai) as tanggal'),
                'types.nama_type', 
                DB::raw('count(*) as qty'), 
                DB::raw('SUM(CAST(detail_gadai.uang_pinjaman AS UNSIGNED)) as total_nominal')
            )
            ->whereBetween('detail_gadai.tanggal_gadai', [$startDate, $endDate])
            ->whereNull('detail_gadai.deleted_at')
            ->groupBy('tanggal', 'types.nama_type')
            ->orderBy('tanggal', 'asc')
            ->get();

        foreach ($gadaiBaru as $gb) {
            $laporanTabel[] = [
                'no' => $no++, 
                'tanggal' => Carbon::parse($gb->tanggal)->translatedFormat('d/m/Y'),
                'keterangan' => "Pencairan: " . $gb->nama_type,
                'qty' => (int)$gb->qty, 
                'debet' => 0, 
                'kredit' => (float)$gb->total_nominal,
            ];
            $grandTotalKredit += (float)$gb->total_nominal;
        }

        $pelunasan = DB::table('detail_gadai')
            ->join('types', 'detail_gadai.type_id', '=', 'types.id')
            ->select(
                DB::raw('DATE(detail_gadai.tanggal_bayar) as tanggal'),
                'types.nama_type', 
                DB::raw('count(*) as qty'), 
                DB::raw('SUM(CAST(detail_gadai.nominal_bayar AS UNSIGNED)) as total_nominal')
            )
            ->where('detail_gadai.status', 'lunas')
            ->whereBetween('detail_gadai.tanggal_bayar', [$startDate, $endDate])
            ->whereNull('detail_gadai.deleted_at')
            ->groupBy('tanggal', 'types.nama_type')
            ->orderBy('tanggal', 'asc')
            ->get();

        foreach ($pelunasan as $p) {
            $laporanTabel[] = [
                'no' => $no++, 
                'tanggal' => Carbon::parse($p->tanggal)->translatedFormat('d/m/Y'),
                'keterangan' => "Pelunasan: " . $p->nama_type,
                'qty' => (int)$p->qty, 
                'debet' => (float)$p->total_nominal, 
                'kredit' => 0,
            ];
            $grandTotalDebet += (float)$p->total_nominal;
        }

        $perpanjangan = DB::table('perpanjangan_tempo')
            ->join('detail_gadai', 'perpanjangan_tempo.detail_gadai_id', '=', 'detail_gadai.id')
            ->join('types', 'detail_gadai.type_id', '=', 'types.id')
            ->select(
                DB::raw('DATE(perpanjangan_tempo.tanggal_perpanjangan) as tanggal'),
                'types.nama_type', 
                DB::raw('count(*) as qty'), 
                DB::raw('SUM(CAST(perpanjangan_tempo.nominal_admin AS UNSIGNED)) as total_admin')
            )
            ->whereBetween('perpanjangan_tempo.tanggal_perpanjangan', [$startDate, $endDate])
            ->groupBy('tanggal', 'types.nama_type')
            ->orderBy('tanggal', 'asc')
            ->get();

        foreach ($perpanjangan as $pj) {
            $laporanTabel[] = [
                'no' => $no++, 
                'tanggal' => Carbon::parse($pj->tanggal)->translatedFormat('d/m/Y'),
                'keterangan' => "Admin Perpanjangan: " . $pj->nama_type,
                'qty' => (int)$pj->qty, 
                'debet' => (float)$pj->total_admin, 
                'kredit' => 0,
            ];
            $grandTotalDebet += (float)$pj->total_admin;
        }

        return response()->json([
            'success' => true,
            'metadata' => [
                'tipe_laporan' => 'Laporan Mingguan Terperinci',
                'rentang_waktu' => Carbon::parse($startDate)->translatedFormat('d F') . " s/d " . Carbon::parse($endDate)->translatedFormat('d F Y'),
                'generated_at' => now()->translatedFormat('d F Y H:i')
            ],
            'data_tabel' => $laporanTabel,
            'summary' => [
                'total_pemasukan' => $grandTotalDebet,
                'total_pengeluaran' => $grandTotalKredit,
                'selisih_kas' => $grandTotalDebet - $grandTotalKredit
            ]
        ]);
    } catch (\Exception $e) { 
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500); 
    }
}
}