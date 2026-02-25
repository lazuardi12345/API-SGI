<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\DetailGadai;
use App\Models\Pelelangan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardGadaiController extends Controller
{
    /**
     * Endpoint Tunggal Dashboard - Menggabungkan semua statistik
     */
    public function index(Request $request)
    {
        try {
            $tahunSekarang = $request->query('tahun', Carbon::now()->year);
            $bulanSekarang = Carbon::now()->month;

            // --- 1. SUMMARY GADAI (Beredar, Belum Lunas, Lunas) ---
            $queryBeredar = DetailGadai::whereIn('status', ['proses', 'selesai']);
            $queryBelumLunas = DetailGadai::where('status', 'selesai');
            $queryLunas = DetailGadai::where('status', 'lunas');

            $summaryGadai = [
                'beredar' => [
                    'jumlah' => (int) $queryBeredar->count(),
                    'nominal' => (float) $queryBeredar->sum('uang_pinjaman')
                ],
                'belum_lunas' => [ // Selesai tapi belum Lunas
                    'jumlah' => (int) $queryBelumLunas->count(),
                    'nominal' => (float) $queryBelumLunas->sum('uang_pinjaman')
                ],
                'lunas' => [
                    'jumlah' => (int) $queryLunas->count(),
                    'nominal' => (float) $queryLunas->sum('uang_pinjaman')
                ]
            ];

            // --- 2. STATISTIK BULANAN (Pendapatan & Nasabah) ---
            $dataBulananGadai = DetailGadai::select(
                DB::raw('MONTH(tanggal_gadai) as bulan'),
                DB::raw('SUM(uang_pinjaman) as total_pinjaman'),
                DB::raw('COUNT(DISTINCT nasabah_id) as total_nasabah')
            )
            ->whereYear('tanggal_gadai', $tahunSekarang)
            ->groupBy('bulan')
            ->get()
            ->keyBy('bulan');

            $chartGadai = [];
            for ($i = 1; $i <= 12; $i++) {
                $item = $dataBulananGadai->get($i);
                $chartGadai[] = [
                    'bulan' => Carbon::create()->month($i)->locale('id')->monthName,
                    'total_pinjaman' => (int)($item->total_pinjaman ?? 0),
                    'total_nasabah' => (int)($item->total_nasabah ?? 0),
                ];
            }

            // --- 3. TOTAL UNIT PER JENIS (Global & Bulanan) ---
            $tables = ['gadai_hp', 'gadai_perhiasan', 'gadai_retro', 'gadai_logam_mulia'];
            $totalUnitPerJenis = [];
            $totalUnitGlobal = 0;
            $unitBulananRaw = [];

            foreach ($tables as $table) {
                $count = DB::table($table)->count();
                $key = str_replace('gadai_', '', $table);
                $totalUnitPerJenis[$key] = $count;
                $totalUnitGlobal += $count;

                $unitBulananRaw[$key] = DB::table($table)
                    ->select(DB::raw('MONTH(created_at) as bulan'), DB::raw('COUNT(id) as total'))
                    ->whereYear('created_at', $tahunSekarang)
                    ->groupBy('bulan')
                    ->pluck('total', 'bulan')
                    ->toArray();
            }

            $chartUnitDetail = [];
            for ($i = 1; $i <= 12; $i++) {
                $chartUnitDetail[] = [
                    'bulan' => Carbon::create()->month($i)->locale('id')->monthName,
                    'hp' => $unitBulananRaw['hp'][$i] ?? 0,
                    'perhiasan' => $unitBulananRaw['perhiasan'][$i] ?? 0,
                    'retro' => $unitBulananRaw['retro'][$i] ?? 0,
                    'logam_mulia' => $unitBulananRaw['logam_mulia'][$i] ?? 0,
                ];
            }

            // --- 4. PELELANGAN STATS ---
            $pelelanganRaw = Pelelangan::with(['detailGadai.type'])->get();
            
            $pelelanganSummary = [
                'siap' => [
                    'jumlah' => $pelelanganRaw->where('status_lelang', 'siap')->count(),
                    'nominal' => (float) $pelelanganRaw->where('status_lelang', 'siap')->sum(fn($p) => $p->detailGadai->uang_pinjaman ?? 0)
                ],
                'terlelang' => [
                    'jumlah' => $pelelanganRaw->where('status_lelang', 'terlelang')->count(),
                    'nominal' => (float) $pelelanganRaw->where('status_lelang', 'terlelang')->sum('harga_terjual')
                ],
                'lunas' => [
                    'jumlah' => $pelelanganRaw->where('status_lelang', 'lunas')->count(),
                    'nominal' => (float) $pelelanganRaw->where('status_lelang', 'lunas')->sum(fn($p) => $this->hitungKalkulasi($p->detailGadai, $p->tanggal_pelunasan ?? now())['total_hutang'])
                ]
            ];

            // --- 5. BRANKAS DASHBOARD & CHART ---
            $terakhirBrankas = DB::table('transaksi_brankas')->orderBy('id', 'desc')->first();
            $mutasiBrankasRaw = DB::table('transaksi_brankas')
                ->select(DB::raw('MONTH(created_at) as bulan'), DB::raw('SUM(pemasukan) as total_masuk'), DB::raw('SUM(pengeluaran) as total_keluar'))
                ->whereYear('created_at', $tahunSekarang)
                ->groupBy('bulan')->get()->keyBy('bulan');

            $brankasChart = ['masuk' => [], 'keluar' => [], 'kumulatif' => [], 'labels' => []];
            $currentSaldoKumulatif = 0;

            for ($i = 1; $i <= 12; $i++) {
                $m = $mutasiBrankasRaw->get($i);
                $in = (int)($m->total_masuk ?? 0);
                $out = (int)($m->total_keluar ?? 0);
                $currentSaldoKumulatif += ($in - $out);

                $brankasChart['masuk'][] = $in;
                $brankasChart['keluar'][] = $out;
                $brankasChart['kumulatif'][] = $currentSaldoKumulatif;
                $brankasChart['labels'][] = Carbon::create()->month($i)->locale('id')->shortMonthName;
            }

            // --- FINAL RESPONSE ---
            return response()->json([
                'success' => true,
                'message' => 'Dashboard Data Loaded Successfully',
                'meta' => [
                    'tahun' => (int)$tahunSekarang,
                    'bulan_nama' => Carbon::now()->locale('id')->monthName,
                ],
                'data' => [
                    'gadai_summary' => $summaryGadai,
                    'gadai_chart' => $chartGadai,
                    'unit_stats' => [
                        'total_global' => $totalUnitGlobal,
                        'per_jenis' => $totalUnitPerJenis,
                        'chart_detail' => $chartUnitDetail
                    ],
                    'pelelangan' => [
                        'summary' => $pelelanganSummary
                    ],
                    'brankas' => [
                        'summary' => [
                            'saldo_toko' => (int)($terakhirBrankas->saldo_akhir ?? 0),
                            'saldo_rekening' => (int)($terakhirBrankas->saldo_akhir_rekening ?? 0),
                            'modal_pusat' => (int)DB::table('transaksi_brankas')->where('kategori', 'topup_pusat')->sum('pemasukan'),
                            'setoran_admin' => (int)DB::table('transaksi_brankas')->where('kategori', 'setor_ke_admin')->where('status_validasi', 'tervalidasi')->sum('pengeluaran'),
                        ],
                        'chart' => $brankasChart
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper Fungsi Kalkulasi (Private)
     */
    private function hitungKalkulasi($detail, $tanggalAcuan = null)
    {
        if (!$detail) return ['total_hutang' => 0];
        $tanggalAcuan = $tanggalAcuan ?? now();
        $hariTerlambat = max(Carbon::parse($detail->jatuh_tempo)->diffInDays($tanggalAcuan, false), 0);
        
        $bulanGadai = max(Carbon::parse($detail->tanggal_gadai)->diffInMonths($tanggalAcuan), 1);
        $bunga = $detail->uang_pinjaman * 0.01 * $bulanGadai;
        
        $denda = 0;
        if ($hariTerlambat > 0) {
            $jenisBarang = strtolower($detail->type->nama_type ?? '');
            $rate = (str_contains($jenisBarang, 'hp') || str_contains($jenisBarang, 'handphone')) ? 0.003 : 0.0015;
            $denda = $detail->uang_pinjaman * $rate * $hariTerlambat;
        }

        return ['total_hutang' => $detail->uang_pinjaman + $bunga + 180 + $denda];
    }
}