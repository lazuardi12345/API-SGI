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
     * 📊 Ringkasan Data Gadai
     */
public function summary()
{
    // 1. PINJAMAN BEREDAR (Semua yang statusnya 'proses' atau 'selesai' / belum lunas)
    $queryBeredar = DetailGadai::whereIn('status', ['proses', 'selesai']);
    $jumlahBeredar = $queryBeredar->count();
    $nominalBeredar = $queryBeredar->sum('uang_pinjaman');

    // 2. BELUM LUNAS (Khusus yang sudah lewat tempo tapi belum bayar / status 'selesai')
    $queryBelumLunas = DetailGadai::where('status', 'selesai');
    $jumlahBelumLunas = $queryBelumLunas->count();
    $nominalBelumLunas = $queryBelumLunas->sum('uang_pinjaman');

    // 3. SUDAH LUNAS (Status 'lunas')
    $queryLunas = DetailGadai::where('status', 'lunas');
    $jumlahLunas = $queryLunas->count();
    $nominalLunas = $queryLunas->sum('uang_pinjaman');

    return response()->json([
        'success' => true,
        'data' => [
            'beredar' => [
                'jumlah' => $jumlahBeredar,
                'nominal' => (float)$nominalBeredar
            ],
            'belum_lunas' => [
                'jumlah' => $jumlahBelumLunas,
                'nominal' => (float)$nominalBelumLunas
            ],
            'lunas' => [
                'jumlah' => $jumlahLunas,
                'nominal' => (float)$nominalLunas
            ]
        ]
    ]);
}

    /**
     * 💵 Total Pendapatan Gadai per Bulan (tahun berjalan)
     */
    public function pendapatanPerBulan()
    {
        $tahunSekarang = Carbon::now()->year;

        // Ambil total uang_pinjaman per bulan untuk tahun berjalan
        $data = DetailGadai::select(
                DB::raw('MONTH(tanggal_gadai) as bulan'),
                DB::raw('SUM(uang_pinjaman) as total_pinjaman')
            )
            ->whereYear('tanggal_gadai', $tahunSekarang)
            ->groupBy('bulan')
            ->get()
            ->pluck('total_pinjaman', 'bulan')
            ->toArray();

        // Isi bulan yang tidak ada data dengan 0
        $result = [];
        for ($i = 1; $i <= 12; $i++) {
            $result[] = [
                'bulan' => Carbon::create()->month($i)->locale('id')->monthName,
                'total_pinjaman' => (int)($data[$i] ?? 0),
                'total_pinjaman_formatted' => 'Rp ' . number_format(($data[$i] ?? 0), 0, ',', '.')
            ];
        }

        return response()->json([
            'success' => true,
            'tahun' => $tahunSekarang,
            'data' => $result
        ]);
    }

    /**
     * 👥 Jumlah Nasabah per Bulan (tahun berjalan)
     */
    public function nasabahPerBulan()
    {
        $tahunSekarang = Carbon::now()->year;

        $data = DetailGadai::select(
                DB::raw('MONTH(tanggal_gadai) as bulan'),
                DB::raw('COUNT(DISTINCT nasabah_id) as total_nasabah')
            )
            ->whereYear('tanggal_gadai', $tahunSekarang)
            ->groupBy('bulan')
            ->get()
            ->pluck('total_nasabah', 'bulan')
            ->toArray();

        // Isi bulan yang belum ada data dengan 0
        $result = [];
        for ($i = 1; $i <= 12; $i++) {
            $result[] = [
                'bulan' => Carbon::create()->month($i)->locale('id')->monthName,
                'total_nasabah' => (int)($data[$i] ?? 0)
            ];
        }

        return response()->json([
            'success' => true,
            'tahun' => $tahunSekarang,
            'data' => $result
        ]);
    }


public function totalSemua()
{
    $tahun = Carbon::now()->year;

    // Hitung total unit (nasabah) per jenis gadai
    $totalHp = DB::table('gadai_hp')->count();
    $totalPerhiasan = DB::table('gadai_perhiasan')->count();
    $totalRetro = DB::table('gadai_retro')->count();
    $totalLogamMulia = DB::table('gadai_logam_mulia')->count();

    $totalGlobal = $totalHp + $totalPerhiasan + $totalRetro + $totalLogamMulia;

    // Fungsi bantu: hitung jumlah unit per bulan (berdasarkan created_at)
    $ambilDataBulanan = function ($table) use ($tahun) {
        return DB::table($table)
            ->select(
                DB::raw('MONTH(created_at) as bulan'),
                DB::raw('COUNT(id) as total')
            )
            ->whereYear('created_at', $tahun)
            ->groupBy('bulan')
            ->pluck('total', 'bulan')
            ->toArray();
    };

    // Ambil data per jenis
    $dataHp = $ambilDataBulanan('gadai_hp');
    $dataPerhiasan = $ambilDataBulanan('gadai_perhiasan');
    $dataRetro = $ambilDataBulanan('gadai_retro');
    $dataLogamMulia = $ambilDataBulanan('gadai_logam_mulia');

    // Gabungkan hasil per bulan
    $result = [];
    for ($i = 1; $i <= 12; $i++) {
        $hpBulan = $dataHp[$i] ?? 0;
        $perhiasanBulan = $dataPerhiasan[$i] ?? 0;
        $retroBulan = $dataRetro[$i] ?? 0;
        $logamMuliaBulan = $dataLogamMulia[$i] ?? 0;

        $totalSemuaBulan = $hpBulan + $perhiasanBulan + $retroBulan + $logamMuliaBulan;

        $result[] = [
            'bulan' => Carbon::create()->month($i)->locale('id')->monthName,
            'hp' => $hpBulan,
            'perhiasan' => $perhiasanBulan,
            'retro' => $retroBulan,
            'logam_mulia' => $logamMuliaBulan,
            'total_unit_bulan' => $totalSemuaBulan,
        ];
    }

    return response()->json([
        'success' => true,
        'tahun' => $tahun,
        'total_unit_per_jenis' => [
            'hp' => $totalHp,
            'perhiasan' => $totalPerhiasan,
            'retro' => $totalRetro,
            'logam_mulia' => $totalLogamMulia,
        ],
        'total_unit_global' => $totalGlobal,
        'data_bulanan' => $result
    ]);
}

/**
 *  Statistik Pelelangan
 */
public function pelelanganStats()
{
    $tahun = Carbon::now()->year;

    // Base query + relasi
    $baseQuery = Pelelangan::with(['detailGadai.type']);

    /* ===============================
     * TOTAL GLOBAL
     * =============================== */

    // SIAP → uang pinjaman
    $siap = (clone $baseQuery)
        ->where('status_lelang', 'siap')
        ->get();

    $totalSiap = [
        'jumlah' => $siap->count(),
        'nominal' => $siap->sum(function ($p) {
            return (float) ($p->detailGadai->uang_pinjaman ?? 0);
        }),
    ];

    // TERLELANG → harga terjual
    $terlelang = (clone $baseQuery)
        ->where('status_lelang', 'terlelang')
        ->get();

    $totalTerlelang = [
        'jumlah' => $terlelang->count(),
        'nominal' => $terlelang->sum(function ($p) {
            return (float) ($p->harga_terjual ?? 0);
        }),
    ];

    // LUNAS → TOTAL HUTANG (hasil kalkulasi)
    $lunas = (clone $baseQuery)
        ->where('status_lelang', 'lunas')
        ->get();

    $totalLunas = [
        'jumlah' => $lunas->count(),
        'nominal' => $lunas->sum(function ($p) {
            if (!$p->detailGadai) return 0;

            $kalkulasi = $this->hitungKalkulasi(
                $p->detailGadai,
                $p->tanggal_pelunasan ?? now()
            );

            return (float) $kalkulasi['total_hutang'];
        }),
    ];

    /* ===============================
     * DATA BULANAN
     * =============================== */

    $dataBulanan = [];

    for ($bulan = 1; $bulan <= 12; $bulan++) {

        $queryBulan = (clone $baseQuery)
            ->whereYear('created_at', $tahun)
            ->whereMonth('created_at', $bulan);

        $siapBulan = (clone $queryBulan)
            ->where('status_lelang', 'siap')
            ->get();

        $terlelangBulan = (clone $queryBulan)
            ->where('status_lelang', 'terlelang')
            ->get();

        $lunasBulan = (clone $queryBulan)
            ->where('status_lelang', 'lunas')
            ->get();

        $dataBulanan[] = [
            'bulan' => Carbon::create()->month($bulan)->locale('id')->monthName,

            'siap' => [
                'jumlah' => $siapBulan->count(),
                'nominal' => $siapBulan->sum(function ($p) {
                    return (float) ($p->detailGadai->uang_pinjaman ?? 0);
                }),
            ],

            'terlelang' => [
                'jumlah' => $terlelangBulan->count(),
                'nominal' => $terlelangBulan->sum(function ($p) {
                    return (float) ($p->harga_terjual ?? 0);
                }),
            ],

            'lunas' => [
                'jumlah' => $lunasBulan->count(),
                'nominal' => $lunasBulan->sum(function ($p) {
                    if (!$p->detailGadai) return 0;

                    $kalkulasi = $this->hitungKalkulasi(
                        $p->detailGadai,
                        $p->tanggal_pelunasan ?? now()
                    );

                    return (float) $kalkulasi['total_hutang'];
                }),
            ],
        ];
    }

    return response()->json([
        'success' => true,
        'tahun' => $tahun,
        'total' => [
            'siap' => $totalSiap,
            'terlelang' => $totalTerlelang,
            'lunas' => $totalLunas,
        ],
        'data_bulanan' => $dataBulanan,
    ]);
}


private function hitungKalkulasi($detail, $tanggalAcuan = null)
    {
        $tanggalAcuan = $tanggalAcuan ?? now();

        $hariTerlambat = Carbon::parse($detail->jatuh_tempo)
            ->diffInDays($tanggalAcuan, false);

        $hariTerlambat = max($hariTerlambat, 0);

        $penalty = 180;

        $bulanGadai = Carbon::parse($detail->tanggal_gadai)
            ->diffInMonths($tanggalAcuan);

        $bunga = $detail->uang_pinjaman * 0.01 * max($bulanGadai, 1);

        $denda = 0;
        if ($hariTerlambat > 0) {
            $jenisBarang = strtolower($detail->type->nama_type ?? '');
            $rate = (str_contains($jenisBarang, 'hp') || str_contains($jenisBarang, 'handphone'))
                ? 0.003
                : 0.0015;

            $denda = $detail->uang_pinjaman * $rate * $hariTerlambat;
        }

        return [
            'hari_terlambat' => $hariTerlambat,
            'bunga' => $bunga,
            'penalty' => $penalty,
            'denda' => $denda,
            'total_hutang' => $detail->uang_pinjaman + $bunga + $penalty + $denda,
        ];
    }


public function brankasDashboard()
{
    try {
        // 1. Ambil transaksi paling terakhir untuk saldo riil (Cash & Rekening)
        $terakhir = DB::table('transaksi_brankas')
            ->orderBy('id', 'desc')
            ->first();

        // 2. Info Waktu
        $bulanSekarang = now()->month;
        $tahunSekarang = now()->year;

        // 3. Hitung Modal & Setoran (Logika dari fungsi index)
        $totalModalPusat = (float) DB::table('transaksi_brankas')
            ->where('kategori', 'topup_pusat')
            ->sum('pemasukan');

        $totalSetoranTervalidasi = (float) DB::table('transaksi_brankas')
            ->where('kategori', 'setor_ke_admin')
            ->where('status_validasi', 'tervalidasi')
            ->sum('pengeluaran');

        $totalSetoranPending = (float) DB::table('transaksi_brankas')
            ->where('kategori', 'setor_ke_admin')
            ->where('status_validasi', 'pending')
            ->sum('pengeluaran');

        // 4. Hitung Mutasi Khusus Bulan Ini (Pemasukan & Pengeluaran)
        $totalMasukBulanan = DB::table('transaksi_brankas')
            ->whereMonth('created_at', $bulanSekarang)
            ->whereYear('created_at', $tahunSekarang)
            ->sum('pemasukan') ?? 0;

        $totalKeluarBulanan = DB::table('transaksi_brankas')
            ->whereMonth('created_at', $bulanSekarang)
            ->whereYear('created_at', $tahunSekarang)
            ->sum('pengeluaran') ?? 0;

        return response()->json([
            'success' => true,
            'summary' => [
                // Saldo Riil Saat Ini
                'saldo_toko_saat_ini' => (int) ($terakhir->saldo_akhir ?? 0),
                'saldo_rekening_saat_ini' => (int) ($terakhir->saldo_akhir_rekening ?? 0),
                
                // Akumulasi Modal & Setoran
                'total_modal_dari_pusat' => (int) $totalModalPusat,
                'total_setoran_ke_admin' => (int) $totalSetoranTervalidasi,
                'total_setoran_pending' => (int) $totalSetoranPending,
                
                // Mutasi Bulanan (Untuk info tambahan)
                'total_pemasukan_bulan_ini' => (int) $totalMasukBulanan,
                'total_pengeluaran_bulan_ini' => (int) $totalKeluarBulanan,
            ],
            'info' => [
                'bulan' => now()->locale('id')->monthName,
                'tahun' => $tahunSekarang
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function brankasYearlyChart(Request $request)
{
    try {
        $tahun = $request->query('tahun', now()->year);

        $mutasiBulanan = DB::table('transaksi_brankas')
            ->select(
                DB::raw('MONTH(created_at) as bulan'),
                DB::raw('SUM(pemasukan) as total_masuk'),
                DB::raw('SUM(pengeluaran) as total_keluar')
            )
            ->whereYear('created_at', $tahun)
            ->groupBy(DB::raw('MONTH(created_at)'))
            ->orderBy('bulan', 'asc')
            ->get();

        $pemasukan = array_fill(0, 12, 0);
        $pengeluaran = array_fill(0, 12, 0);
        $saldoBulanan = array_fill(0, 12, 0); 

        foreach ($mutasiBulanan as $data) {
            $pemasukan[$data->bulan - 1] = (int)$data->total_masuk;
            $pengeluaran[$data->bulan - 1] = (int)$data->total_keluar;
        }

        $currentSaldo = 0;
        for ($i = 0; $i < 12; $i++) {
            $currentSaldo += ($pemasukan[$i] - $pengeluaran[$i]);
            $saldoBulanan[$i] = $currentSaldo;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'pemasukan' => $pemasukan,
                'pengeluaran' => $pengeluaran,
                'saldo_kumulatif' => $saldoBulanan,
                'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des']
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

}
