<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ReportHelper;
use App\Models\ReportPrint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PetugasLaporanController extends Controller
{
    use ReportHelper;

   public function cetakLaporanHarian(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
            
            // 1. Metadata Approval
            $existing = ReportPrint::where('report_type', 'harian')->where('report_date', $tanggal)->first();
            $isApproved = $existing ? (bool)$existing->is_approved : false;
            $namaManager = $existing ? $existing->approved_by : null;
            $docId = $existing ? $existing->doc_id : null;
            $qrCode = $isApproved && $namaManager && $docId 
                ? $this->generateReportQr("Rekapitulasi Harian", $tanggal, $docId, $namaManager) : null;

            $laporanTabel = [];
            $no = 1;
            $grandTotalDebet = 0;  // Pemasukan
            $grandTotalKredit = 0; // Pengeluaran

            // A. GADAI BARU (KREDIT / PENGELUARAN)
            $gadaiBaru = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select('types.nama_type', DB::raw('count(*) as qty'), DB::raw('SUM(CAST(detail_gadai.uang_pinjaman AS UNSIGNED)) as total_nominal'))
                ->whereDate('detail_gadai.tanggal_gadai', $tanggal)
                ->whereNull('detail_gadai.deleted_at')
                ->groupBy('types.nama_type')->get();

            foreach ($gadaiBaru as $gb) {
                $laporanTabel[] = [
                    'no' => $no++, 'keterangan' => "Pencairan Gadai: " . $gb->nama_type,
                    'qty' => (int)$gb->qty, 'debet' => 0, 'kredit' => (float)$gb->total_nominal,
                ];
                $grandTotalKredit += (float)$gb->total_nominal;
            }

            // B. PELUNASAN (DEBET / PEMASUKAN)
            $pelunasan = DB::table('detail_gadai')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select('types.nama_type', DB::raw('count(*) as qty'), DB::raw('SUM(CAST(detail_gadai.nominal_bayar AS UNSIGNED)) as total_nominal'))
                ->where('detail_gadai.status', 'lunas')->whereDate('detail_gadai.tanggal_bayar', $tanggal)
                ->whereNull('detail_gadai.deleted_at')->groupBy('types.nama_type')->get();

            foreach ($pelunasan as $p) {
                $laporanTabel[] = [
                    'no' => $no++, 'keterangan' => "Pelunasan Gadai: " . $p->nama_type,
                    'qty' => (int)$p->qty, 'debet' => (float)$p->total_nominal, 'kredit' => 0,
                ];
                $grandTotalDebet += (float)$p->total_nominal;
            }

            // C. ADMIN PERPANJANGAN (DEBET / PEMASUKAN)
            $perpanjangan = DB::table('perpanjangan_tempo')
                ->join('detail_gadai', 'perpanjangan_tempo.detail_gadai_id', '=', 'detail_gadai.id')
                ->join('types', 'detail_gadai.type_id', '=', 'types.id')
                ->select('types.nama_type', DB::raw('count(*) as qty'), DB::raw('SUM(CAST(perpanjangan_tempo.nominal_admin AS UNSIGNED)) as total_admin'))
                ->whereDate('perpanjangan_tempo.tanggal_perpanjangan', $tanggal)
                ->groupBy('types.nama_type')->get();

            foreach ($perpanjangan as $pj) {
                $laporanTabel[] = [
                    'no' => $no++, 'keterangan' => "Admin Perpanjangan: " . $pj->nama_type,
                    'qty' => (int)$pj->qty, 'debet' => (float)$pj->total_admin, 'kredit' => 0,
                ];
                $grandTotalDebet += (float)$pj->total_admin;
            }

            return response()->json([
                'success' => true,
                'metadata' => [
                    'halaman' => 1, 'is_approved' => $isApproved, 'approved_by' => $namaManager,
                    'doc_id' => $docId, 'qr_code' => $qrCode,
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                ],
                'data_tabel' => $laporanTabel,
                'summary' => [
                    'total_pemasukan' => $grandTotalDebet,
                    'total_pengeluaran' => $grandTotalKredit,
                    'selisih_kas' => $grandTotalDebet - $grandTotalKredit
                ]
            ]);
        } catch (\Exception $e) { return response()->json(['success' => false, 'message' => $e->getMessage()], 500); }
    }

    public function ajukanLaporan(Request $request)
    {
        try {
            $tanggal = $request->report_date ?? Carbon::today()->toDateString();
            
            $report = ReportPrint::updateOrCreate(
                ['report_type' => 'harian', 'report_date' => $tanggal],
                [
                    'doc_id' => 'REP-HRI-' . date('Ymd') . '-' . strtoupper(Str::random(5)),
                    'printed_by' => auth()->user()->name ?? 'Petugas',
                    'is_approved' => false,
                    'printed_at' => now(),
                    'ip_address' => $request->ip(), 
                ]
            );

            return response()->json([
                'success' => true, 
                'message' => 'Laporan Harian berhasil diajukan!', 
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}