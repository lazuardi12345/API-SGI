<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ReportHelper;
use App\Models\ReportPrint;
use App\Models\DetailGadai;
use App\Models\PerpanjanganTempo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class LaporanHarianCheckerController extends Controller
{
    use ReportHelper;


    private function getAccStatus($type, $tanggal, $request) {
        $existing = ReportPrint::where('report_type', $type)->where('report_date', $tanggal)->first();
        return [
            'isApproved'  => $existing ? (bool)$existing->is_approved : $request->get('is_approved', false),
            'namaManager' => $existing ? $existing->approved_by : $request->get('manager_name', null),
            'docId'       => $existing ? $existing->doc_id : null
        ];
    }

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

public function cetakLaporanSerahTerima(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();

            $existing = ReportPrint::where('report_type', 'serah_terima')
                ->where('report_date', $tanggal)
                ->first();

            $isApproved = $existing ? (bool)$existing->is_approved : false;
            $namaManager = $existing ? $existing->approved_by : null;
            $docId = $existing ? $existing->doc_id : null;
            $qrCode = null;

            if ($isApproved && $namaManager && $docId) {
                $qrCode = $this->generateReportQr("Serah Terima Pelunasan", $tanggal, $docId, $namaManager);
            }
            $dataLunas = \App\Models\DetailGadai::with([
                    'nasabah', 
                    'hp.merk', 'hp.type_hp', 'hp.kelengkapanList',
                    'perhiasan', 
                    'logamMulia', 
                    'retro'
                ])
                ->where('status', 'lunas')
                ->whereDate('tanggal_bayar', $tanggal)
                ->whereNull('deleted_at')
                ->get();

           $formattedData = $dataLunas->map(function ($gadai) {
    $namaBarang = '-';
    $detailSpesifik = '-';
    $kelengkapan = [];

    if ($gadai->hp) {
        $hp = $gadai->hp;
        $namaBarang = "HP: " . ($hp->merk->nama_merk ?? '') . " " . ($hp->type_hp->nama_type ?? '');
        $detailSpesifik = "Warna: " . ($hp->warna ?? '-'); 
        $kelengkapan = $hp->kelengkapanList->pluck('nama_kelengkapan')->toArray();
    } 
    elseif ($gadai->perhiasan) {
        $p = $gadai->perhiasan;
        $namaBarang = "Perhiasan: " . ($p->nama_barang ?? 'Emas');
        $detailSpesifik = "Berat: {$p->berat} gr | Karat: " . ($p->karat ?? '-') . " | Kode Cap: " . ($p->kode_cap ?? '-');
    }
    elseif ($gadai->logamMulia) {
        $lm = $gadai->logamMulia;
        $namaBarang = "Logam Mulia: " . ($lm->nama_barang ?? 'LM');
        $detailSpesifik = "Berat: {$lm->berat} gr | Brand: " . ($lm->brand ?? '-');
    }
    elseif ($gadai->retro) {
        $r = $gadai->retro;
        $namaBarang = "Barang Retro: " . ($r->nama_barang ?? 'Retro');
        $detailSpesifik = "Ket: " . ($r->keterangan ?? '-');
    }

    return [
        'no_gadai'      => $gadai->no_gadai,
        'nasabah'       => $gadai->nasabah->nama_lengkap ?? 'Nasabah Tidak Ditemukan',
        'nama_barang'   => $namaBarang,
        'detail_spesifik' => $detailSpesifik,
        'kelengkapan'   => $kelengkapan,
        'nominal_lunas' => (float)$gadai->nominal_bayar,
        'tanggal_bayar' => $gadai->tanggal_bayar ? Carbon::parse($gadai->tanggal_bayar)->format('d-m-Y') : '-',
    ];
});

            // 4. Response JSON
            return response()->json([
                'success' => true,
                'metadata' => [
                    'halaman' => 2,
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                    'is_approved' => $isApproved,
                    'approved_by' => $namaManager,
                    'doc_id' => $docId,
                    'qr_code' => $qrCode,
                    'total_item' => $formattedData->count(),
                    'grand_total_lunas' => $formattedData->sum('nominal_lunas'),
                ],
                'data' => $formattedData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail serah terima: ' . $e->getMessage()
            ], 500);
        }
    }


public function cetakLaporanPerpanjangan(Request $request)
{
    try {
        // 1. Inisialisasi Tanggal
        $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
        
        // 2. Ambil Metadata Report (Status Approval Manager)
        $existing = ReportPrint::where('report_type', 'perpanjangan')
            ->where('report_date', $tanggal)
            ->first();

        $isApproved = $existing ? (bool)$existing->is_approved : false;
        $namaManager = $existing ? $existing->approved_by : null;
        $docId = $existing ? $existing->doc_id : null;
        $qrCode = null;

        if ($isApproved && $namaManager && $docId) {
            $qrCode = $this->generateReportQr("Perpanjangan Gadai", $tanggal, $docId, $namaManager);
        }

        // 3. Query Data Perpanjangan dengan Eager Loading
        $dataPerpanjangan = \App\Models\PerpanjanganTempo::with([
                'detailGadai.nasabah',
                'detailGadai.hp.merk',
                'detailGadai.hp.type_hp',
                'detailGadai.perhiasan',
                'detailGadai.logamMulia',
                'detailGadai.retro'
            ])
            ->where('status_bayar', 'lunas')
            ->whereDate('updated_at', $tanggal) 
            ->orderBy('updated_at', 'asc')
            ->get();

        // 4. Mapping Data untuk Frontend
        $formattedPerpanjangan = $dataPerpanjangan->map(function ($p) {
            $gadai = $p->detailGadai;
            if (!$gadai) return null;

            // Logika Penentuan Nama & Detail Barang
            $namaBarang = '-';
            $detailBarang = '-';

            if ($gadai->hp) {
                $namaBarang = "HP: " . ($gadai->hp->merk->nama_merk ?? '') . " " . ($gadai->hp->type_hp->nama_type ?? '');
                $detailBarang = "IMEI: " . ($gadai->hp->imei ?? '-');
            } elseif ($gadai->perhiasan) {
                $p_emas = $gadai->perhiasan;
                $namaBarang = "Perhiasan: " . ($p_emas->nama_barang ?? 'Emas');
                $detailBarang = "Berat: {$p_emas->berat} gr | Karat: {$p_emas->karat}%";
            } elseif ($gadai->logamMulia) {
                $lm = $gadai->logamMulia;
                $namaBarang = "LM: " . ($lm->nama_barang ?? 'Logam Mulia');
                $detailBarang = "Brand: {$lm->brand} | Berat: {$lm->berat} gr";
            } elseif ($gadai->retro) {
                $namaBarang = "Retro: " . ($gadai->retro->nama_barang ?? 'Barang');
                $detailBarang = "Ket: " . ($gadai->retro->keterangan ?? '-');
            }

            // --- LOGIKA ANTI STRIP (FIXED) ---
            // 1. Cek kolom tgl_jatuh_tempo_lama di tabel perpanjangan
            // 2. Kalau kosong, ambil dari kolom tanggal_jatuh_tempo di tabel gadai
            // 3. Kalau masih kosong (data lama), asumsi jatuh tempo adalah saat dia bayar perpanjangan
            $jtLamaRaw = $p->tgl_jatuh_tempo_lama 
                         ?? $gadai->tanggal_jatuh_tempo 
                         ?? $p->created_at;

            return [
                'no_gadai' => $gadai->no_gadai,
                'nasabah' => $gadai->nasabah->nama_lengkap ?? '-',
                'barang' => $namaBarang,
                'detail' => $detailBarang,
                'jt_lama' => Carbon::parse($jtLamaRaw)->format('d/m/Y'),
                'jt_baru' => $p->jatuh_tempo_baru ? Carbon::parse($p->jatuh_tempo_baru)->format('d/m/Y') : '-',
                'nominal_pembayaran' => (float)$p->nominal_admin,
                'metode' => strtoupper($p->metode_pembayaran ?? 'CASH')
            ];
        })->filter()->values();

        // 5. Response JSON
        return response()->json([
            'success' => true,
            'metadata' => [
                'halaman' => 3,
                'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                'is_approved' => $isApproved,
                'approved_by' => $namaManager,
                'doc_id' => $docId,
                'qr_code' => $qrCode,
            ],
            'summary' => [
                'total_dana_masuk' => (float)$formattedPerpanjangan->sum('nominal_pembayaran'),
                'jumlah_transaksi' => $formattedPerpanjangan->count(),
                'formatted_total' => 'Rp ' . number_format($formattedPerpanjangan->sum('nominal_pembayaran'), 0, ',', '.')
            ],
            'data' => $formattedPerpanjangan
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false, 
            'message' => 'Gagal memuat laporan: ' . $e->getMessage()
        ], 500);
    }
}


private function hitungKalkulasiInternal($detail, $tanggalAcuan = null) {
        $pokok = $detail->uang_pinjaman;
        $bunga = $pokok * 0.01 * 1; 
        return ['total_hutang' => (int)($pokok + $bunga + 180000)];
    }

public function cetakLaporanPelelangan(Request $request)
{
    try {
        $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
        
        $existing = ReportPrint::where('report_type', 'pelelangan')
            ->where('report_date', $tanggal)
            ->first();

        $isApproved = $existing ? (bool)$existing->is_approved : false;
        $namaManager = $existing ? $existing->approved_by : null;
        $docId = $existing ? $existing->doc_id : null;
        $qrCode = null;

        if ($isApproved && $namaManager && $docId) {
            $qrCode = $this->generateReportQr("Laporan Pelelangan", $tanggal, $docId, $namaManager);
        }
        $dataLelang = \App\Models\DetailGadai::with([
                'nasabah', 
                'pelelangan', 
                'type',
                'hp.merk', 'hp.type_hp', 
                'perhiasan',             
                'logamMulia'           
            ])
            ->whereHas('pelelangan', function($q) use ($tanggal) {
                $q->whereDate('waktu_bayar', $tanggal)
                  ->whereIn('status_lelang', ['terlelang', 'lunas']);
            })->get();

        $formatted = $dataLelang->map(function ($gadai) {
            $lelang = $gadai->pelelangan;
            $kalkulasi = $this->hitungKalkulasiInternal($gadai, $lelang->waktu_bayar);
            $hutang = (float)($kalkulasi['total_hutang'] ?? 0);
            $nominalMasuk = (float)($lelang->nominal_diterima ?? 0);

            $detail = "-";
            if ($gadai->hp) {
                $hp = $gadai->hp;
                $detail = ($hp->merk->nama_merk ?? '') . " " . ($hp->type_hp->nama_type ?? '') . "\n";
                $detail .= "IMEI: " . ($hp->imei ?? '-');
            } 
            elseif ($gadai->perhiasan) {
                $p = $gadai->perhiasan;
                $detail = "Perhiasan: " . ($p->nama_barang ?? 'Emas') . "\n";
                $detail .= "Berat: " . ($p->berat ?? '0') . " gr | Karat: " . ($p->karat ?? '-') . " | Kode cap: " . ($p->kode_cap ?? '-');
            }
            elseif ($gadai->logamMulia) {
                $lm = $gadai->logamMulia;
                $detail = "LM: " . ($lm->nama_barang ?? 'Logam Mulia') . "\n";
                $detail .= "Berat: " . ($lm->berat ?? '0') . " gr | Brand: " . ($lm->brand ?? '-');
            }

            return [
                'no_gadai' => $gadai->no_gadai,
                'nasabah' => $gadai->nasabah->nama_lengkap ?? '-',
                'barang' => $gadai->type->nama_type ?? 'Barang',
                'detail_barang' => trim($detail), 
                'hutang_nasabah' => $hutang,
                'nominal_masuk' => $nominalMasuk,
                'keuntungan' => $nominalMasuk - $hutang,
                'status' => strtoupper($lelang->status_lelang)
            ];
        });

        return response()->json([
            'success' => true,
            'metadata' => [
                'halaman' => 4,
                'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                'is_approved' => $isApproved,
                'approved_by' => $namaManager,
                'doc_id' => $docId,
                'qr_code' => $qrCode,
                'grand_total_masuk' => (float)$formatted->sum('nominal_masuk'),
                'grand_total_keuntungan' => (float)$formatted->sum('keuntungan'),
                'jumlah_barang' => $formatted->count()
            ],
            'data' => $formatted
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        ], 500);
    }
}

public function ajukanLaporanChecker(Request $request)
    {
        try {
            $tanggal = $request->report_date ?? date('Y-m-d');
            
            $tipeLaporan = [
                'harian'        => 'REP-REK', 
                'serah_terima'  => 'REP-LNS', 
                'perpanjangan'  => 'REP-PJG', 
                'pelelangan'    => 'REP-LLG', 
            ];

            DB::beginTransaction();
            foreach ($tipeLaporan as $type => $prefix) {
                ReportPrint::updateOrCreate(
                    ['report_type' => $type, 'report_date' => $tanggal],
                    [
                        'doc_id'      => $prefix . '-' . date('Ymd') . '-' . strtoupper(Str::random(5)),
                        'printed_by'  => auth()->user()->name,
                        'is_approved' => false,
                        'printed_at'  => now(),
                        'ip_address'  => $request->ip(), 
                    ]
                );
            }
            DB::commit();

            return response()->json(['success' => true, 'message' => 'Laporan Audit (Halaman 1-4) berhasil diajukan!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


private function syncToReportPrints($type, $tanggal, $isApproved, $managerName, $docId, $request)
{
    $user = auth()->user();
    $prefix = $this->getPrefixByType($type);

    if (!$docId) {
        $docId = \App\Models\ReportPrint::generateDocId($prefix);
    }

    \App\Models\ReportPrint::updateOrCreate(
        [
            'report_type' => $type,
            'report_date' => $tanggal,
        ],
        [

            'printed_by'   => $user->name ?? 'System',
            'printed_at'   => now(), 
            'ip_address'   => $request->ip(), 
            'is_approved'  => $isApproved,
            'approved_by'  => $managerName,
            'doc_id'       => $docId,
            'prefix'       => $prefix
        ]
    );
}

private function getPrefixByType($type)
{
    $prefixes = [
        'serah_terima' => 'REP-LNS',
        'perpanjangan' => 'REP-PJG',
        'pelelangan'   => 'REP-LLG',
        'brankas'      => 'REP-BRK',
    ];

    return $prefixes[$type] ?? 'REP';
}


public function getReportHistory(Request $request)
    {
        try {
            $query = ReportPrint::query();
            if ($request->has('tanggal')) {
                $query->whereDate('report_date', $request->tanggal);
            }
            $reports = $query->orderBy('report_date', 'desc')
                             ->orderBy('created_at', 'desc')
                             ->get();

            return response()->json(['success' => true, 'data' => $reports]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


   public function approveReport(Request $request, $doc_id = null)
{
    try {
        DB::beginTransaction();
        $docIds = $request->input('doc_ids'); 
        if (!$docIds && $doc_id) {
            $docIds = [$doc_id];
        }

        if (empty($docIds)) {
            return response()->json(['success' => false, 'message' => 'Pilih laporan dulu brader!'], 400);
        }
        $updatedCount = ReportPrint::whereIn('doc_id', $docIds)
            ->update([
                'is_approved' => 1, 
                'approved_by' => auth()->user()->name,
            ]);

        DB::commit();

        return response()->json([
            'success' => true, 
            'message' => "{$updatedCount} Laporan resmi di-ACC Manager!",
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('[ApproveReport] Error: ' . $e->getMessage());
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}
    

    public function publicVerify(Request $request, $doc_id)
    {
        $reportPrint = ReportPrint::where('doc_id', $doc_id)
            ->where('is_approved', true)
            ->first();
        if (!$reportPrint) {
            return "
            <html>
            <head>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>VERIFIKASI GAGAL</title>
                <style>
                    body { font-family: 'Segoe UI', sans-serif; background: #eceff1; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
                    .card { background: white; padding: 0; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); max-width: 400px; width: 90%; overflow: hidden; }
                    .header { background: #c62828; color: white; padding: 20px; text-align: center; }
                    .content { padding: 25px; text-align: center; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <div class='header'>
                        <div style='font-size: 50px;'>✘</div>
                        <h2 style='margin:0;'>DOKUMEN TIDAK VALID</h2>
                    </div>
                    <div class='content'>
                        <p>QR Code tidak terdaftar dalam sistem atau belum mendapat persetujuan resmi dari Manager.</p>
                        <small style='color: #777;'>ID: $doc_id</small>
                    </div>
                </div>
            </body>
            </html>";
        }
        $dataRaw = $request->query('d');
        $info = json_decode(base64_decode($dataRaw), true) ?? [];

        $judul      = $info['title'] ?? 'Laporan Gadai';
        $petugas    = $reportPrint->printed_by;
        $manager    = $reportPrint->approved_by;
        $waktuAcc   = Carbon::parse($reportPrint->approved_at)->translatedFormat('d F Y H:i:s');
        $tglLaporan = Carbon::parse($reportPrint->report_date)->translatedFormat('l, d F Y');

        return "
        <html>
        <head>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>SGI VERIFICATION SYSTEM</title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #eceff1; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
                .card { background: white; padding: 0; border-radius: 15px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); max-width: 450px; width: 90%; overflow: hidden; }
                .header { background: #2e7d32; color: white; padding: 20px; text-align: center; }
                .status-badge { background: #e8f5e9; color: #2e7d32; padding: 6px 18px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; margin-top: 10px; border: 1px solid #2e7d32; }
                .content { padding: 25px; }
                .row { margin-bottom: 15px; border-bottom: 1px solid #f0f0f0; padding-bottom: 8px; }
                .label { color: #90a4ae; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
                .value { color: #263238; font-weight: 600; font-size: 15px; margin-top: 3px; }
                .footer { background: #f8f9fa; padding: 15px; text-align: center; font-size: 11px; color: #90a4ae; border-top: 1px dashed #cfd8dc; }
                .check-icon { font-size: 50px; margin-bottom: 5px; }
            </style>
        </head>
        <body>
            <div class='card'>
                <div class='header'>
                    <div class='check-icon'>✔</div>
                    <h2 style='margin:0; font-size: 20px;'>VERIFIKASI BERHASIL</h2>
                    <div class='status-badge'>DOKUMEN ASLI & TERDAFTAR</div>
                </div>
                <div class='content'>
                    <div class='row'>
                        <div class='label'>Jenis Laporan</div>
                        <div class='value'>$judul</div>
                    </div>
                    <div class='row'>
                        <div class='label'>ID Dokumen (Digital Signature)</div>
                        <div class='value' style='color:#1565c0; font-family: monospace;'>$doc_id</div>
                    </div>
                    <div class='row'>
                        <div class='label'>Tanggal Laporan</div>
                        <div class='value'>$tglLaporan</div>
                    </div>
                    <div class='row' style='display:flex; gap:10px;'>
                        <div style='flex:1'>
                            <div class='label'>Dibuat Oleh (Checker)</div>
                            <div class='value'>$petugas</div>
                        </div>
                        <div style='flex:1'>
                            <div class='label'>Disetujui Oleh (Manager)</div>
                            <div class='value'>$manager</div>
                        </div>
                    </div>
                    <div class='row'>
                        <div class='label'>Waktu Approval Sistem</div>
                        <div class='value'>$waktuAcc WIB</div>
                    </div>
                </div>
                <div class='footer'>
                    <b>PT SENTRA GADAI INDONESIA - DIGITAL SIGNATURE</b><br>
                    Keamanan dokumen ini dilindungi oleh sistem enkripsi.<br>
                    Dokumen ini sah tanpa tanda tangan basah.
                </div>
            </div>
        </body>
        </html>";
    }
}