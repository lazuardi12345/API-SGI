<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ReportHelper;
use App\Models\ReportPrint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaporanKasirController extends Controller
{
    use ReportHelper;

    /**
     * HALAMAN 5: CETAK LAPORAN BRANKAS
     */
public function cetakLaporanBrankasHarian(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
            
            // 1. Cek Metadata Approval
            $existing = ReportPrint::where('report_type', 'brankas')
                ->where('report_date', $tanggal)
                ->first();

            $isApproved = $existing ? (bool)$existing->is_approved : false;
            $namaManager = $existing ? $existing->approved_by : null;
            $docId = $existing ? $existing->doc_id : null;
            $qrCode = null;

            if ($isApproved && $namaManager && $docId) {
                $qrCode = $this->generateReportQr("Laporan Mutasi Brankas", $tanggal, $docId, $namaManager);
            }

            // 2. Ambil Mutasi dari tabel utama
            $mutasi = DB::table('transaksi_brankas')
                ->whereDate('created_at', $tanggal)
                ->orderBy('id', 'asc')
                ->get();

            // 3. Kalkulasi Summary
            $totalMasuk = $mutasi->sum('pemasukan');
            $totalKeluar = $mutasi->sum('pengeluaran');
            
            $lastRow = $mutasi->last();
            $saldoAkhirHariIni = $lastRow ? (float)$lastRow->saldo_akhir : 0;

            // Saldo Awal diambil dari saldo akhir H-1
            $saldoAwal = DB::table('transaksi_brankas')
                ->whereDate('created_at', '<', $tanggal)
                ->orderBy('id', 'desc')
                ->value('saldo_akhir') ?? 0;

            return response()->json([
                'success' => true,
                'metadata' => [
                    'halaman' => 5,
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                    'is_approved' => $isApproved,
                    'approved_by' => $namaManager,
                    'doc_id' => $docId,
                    'qr_code' => $qrCode,
                ],
                'summary_brankas' => [
                    'saldo_awal' => (float)$saldoAwal,
                    'total_debet' => (float)$totalMasuk,
                    'total_kredit' => (float)$totalKeluar,
                    'saldo_akhir' => (float)$saldoAkhirHariIni,
                ],
                'data_mutasi' => $mutasi->map(function($item) {
                    return [
                        'jam' => Carbon::parse($item->created_at)->format('H:i'),
                        // Percantik keterangan dengan huruf kapital di awal kategori
                        'keterangan' => strtoupper(str_replace('_', ' ', $item->kategori)) . " - " . $item->deskripsi,
                        'masuk' => (float)$item->pemasukan,
                        'keluar' => (float)$item->pengeluaran,
                        // SINKRONISASI: Frontend kamu panggil 'saldo_akhir', bukan 'saldo'
                        'saldo_akhir' => (float)$item->saldo_akhir, 
                        'status' => $item->status_validasi
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    /**
     * AJUKAN LAPORAN BRANKAS (KASIR ONLY)
     */
    public function ajukanLaporanBrankas(Request $request)
    {
        try {
            $tanggal = $request->report_date ?? date('Y-m-d');
            
            DB::beginTransaction();
            ReportPrint::updateOrCreate(
                ['report_type' => 'brankas', 'report_date' => $tanggal],
                [
                    'doc_id'      => 'REP-BRK-' . date('Ymd') . '-' . strtoupper(Str::random(5)),
                    'printed_by'  => auth()->user()->name,
                    'is_approved' => false,
                    'printed_at'  => now(),
                    'ip_address'  => $request->ip(), 
                ]
            );
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Laporan Brankas berhasil diajukan!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // --- HELPER INTERNAL KASIR ---

   private function hitungKalkulasiInternal($detail, $tanggalAcuan = null) {
        $pokok = $detail->uang_pinjaman;
        $bunga = $pokok * 0.01 * 1; 
        return ['total_hutang' => (int)($pokok + $bunga + 180000)];
    }

    private function hitungSummaryBrankas($tanggal) {
        $saldoAwal = DB::table('transaksi_brankas')->where('created_at', '<', $tanggal)->orderBy('id', 'desc')->value('saldo_akhir') ?? 0;
        $hariIni = DB::table('transaksi_brankas')->whereDate('created_at', $tanggal);
        return [
            'saldo_awal_hari_ini' => (float)$saldoAwal,
            'total_uang_masuk' => (float)$hariIni->sum('pemasukan'),
            'total_uang_keluar' => (float)$hariIni->sum('pengeluaran'),
            'saldo_akhir_toko' => (float)($saldoAwal + $hariIni->sum('pemasukan') - $hariIni->sum('pengeluaran'))
        ];
    }

    private function getMutasiBrankas($tanggal) {
        return DB::table('transaksi_brankas')->whereDate('created_at', $tanggal)->get()->map(function($item) {
            return ['jam' => Carbon::parse($item->created_at)->format('H:i'), 'keterangan' => $item->kategori . " - " . $item->deskripsi, 'masuk' => (float)$item->pemasukan, 'keluar' => (float)$item->pengeluaran];
        });
    }
}