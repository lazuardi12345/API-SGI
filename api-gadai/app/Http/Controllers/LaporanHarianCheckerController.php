<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LaporanHarianCheckerController extends Controller
{
    public function cetakLaporanHarian(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
            $laporanTabel = [];
            $no = 1;
            
            $grandTotalDebet = 0;
            $grandTotalKredit = 0;

            // 1. GADAI BARU (KREDIT/UANG KELUAR)
            $gadaiBaru = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(detail_gadai.uang_pinjaman AS UNSIGNED)) as total_nominal')
                )
                ->whereDate('detail_gadai.tanggal_gadai', $tanggal)
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('types.nama_type')
                ->get();

            foreach ($gadaiBaru as $gb) {
                $nominal = (float)$gb->total_nominal;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Pencairan Gadai: " . $gb->nama_type,
                        'qty' => (int)$gb->qty,
                        'debet' => 0,
                        'kredit' => $nominal,
                    ];
                    $grandTotalKredit += $nominal;
                }
            }

            // 2. PELUNASAN (DEBET/UANG MASUK)
            $pelunasan = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(detail_gadai.nominal_bayar AS UNSIGNED)) as total_nominal')
                )
                ->where('detail_gadai.status', 'lunas')
                ->whereDate('detail_gadai.tanggal_bayar', $tanggal)
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('types.nama_type')
                ->get();

            foreach ($pelunasan as $p) {
                $nominal = (float)$p->total_nominal;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Pelunasan Gadai: " . $p->nama_type,
                        'qty' => (int)$p->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // 3. PERPANJANGAN (DEBET/UANG MASUK)
            $perpanjangan = DB::table('perpanjangan_tempo')
                ->join('detail_gadai', 'perpanjangan_tempo.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(perpanjangan_tempo.nominal_admin AS UNSIGNED)) as total_admin')
                )
                ->whereDate('perpanjangan_tempo.tanggal_perpanjangan', $tanggal)
                ->groupBy('types.nama_type')
                ->get();

            foreach ($perpanjangan as $pj) {
                $nominal = (float)$pj->total_admin;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Admin Perpanjangan: " . $pj->nama_type,
                        'qty' => (int)$pj->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // 4. LELANG (DEBET/UANG MASUK)
            $lelang = DB::table('pelelangan')
                ->join('detail_gadai', 'pelelangan.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select(
                    'types.nama_type', 
                    DB::raw('count(*) as qty'), 
                    DB::raw('SUM(CAST(pelelangan.nominal_diterima AS UNSIGNED)) as total_lelang')
                )
                ->where('pelelangan.status_lelang', 'terlelang')
                ->whereDate('pelelangan.waktu_bayar', $tanggal)
                ->groupBy('types.nama_type')
                ->get();

            foreach ($lelang as $l) {
                $nominal = (float)$l->total_lelang;
                if ($nominal > 0) {
                    $laporanTabel[] = [
                        'no' => $no++,
                        'keterangan' => "Lelang: " . $l->nama_type,
                        'qty' => (int)$l->qty,
                        'debet' => $nominal,
                        'kredit' => 0,
                    ];
                    $grandTotalDebet += $nominal;
                }
            }

            // AMBIL SALDO TERAKHIR DENGAN AMAN
            $lastTrans = DB::table('transaksi_brankas')
                ->orderBy('id', 'desc')
                ->first();

            // Pastikan mengambil properti saldo_akhir, bukan objeknya
            $saldoAkhir = $lastTrans ? (float)$lastTrans->saldo_akhir : 0;

            return response()->json([
                'success' => true,
                'metadata' => [
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                    'checker_name' => auth()->user()->name ?? 'Petugas',
                ],
                'data_tabel' => $laporanTabel, 
                'footer_total' => [
                    'total_debet' => $grandTotalDebet,
                    'total_kredit' => $grandTotalKredit,
                    'selisih' => $grandTotalDebet - $grandTotalKredit
                ],
                'saldo_kas' => [
                    'raw' => $saldoAkhir,
                    'formatted' => 'Rp ' . number_format($saldoAkhir, 0, ',', '.')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
                'line' => $e->getLine() 
            ], 500);
        }
    }
}