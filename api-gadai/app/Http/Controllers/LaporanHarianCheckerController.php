<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Traits\ReportHelper;
use App\Models\ReportPrint;
use App\Models\DetailGadai;
use App\Models\PerpanjanganTempo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


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
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
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
                ->get();
            $formattedPerpanjangan = $dataPerpanjangan->map(function ($p) {
                $gadai = $p->detailGadai;
                if (!$gadai) return null;

                $namaBarang = '-';
                $detailBarang = '-';
                if ($gadai->hp) {
                    $namaBarang = "HP: " . ($gadai->hp->merk->nama_merk ?? '') . " " . ($gadai->hp->type_hp->nama_type ?? '');
                    $detailBarang = "IMEI: " . ($gadai->hp->imei ?? '-');
                } elseif ($gadai->perhiasan) {
                    $namaBarang = "Perhiasan: " . ($gadai->perhiasan->nama_barang ?? 'Emas');
                    $detailBarang = "Berat: {$gadai->perhiasan->berat} gr | Kadar: {$gadai->perhiasan->kadar}%";
                } elseif ($gadai->logamMulia) {
                    $namaBarang = "LM: " . ($gadai->logamMulia->nama_barang ?? 'Logam Mulia');
                    $detailBarang = "Brand: {$gadai->logamMulia->brand} | Berat: {$gadai->logamMulia->berat} gr";
                } elseif ($gadai->retro) {
                    $namaBarang = "Retro: " . ($gadai->retro->nama_barang ?? 'Barang');
                    $detailBarang = "Ket: " . ($gadai->retro->keterangan ?? '-');
                }

                return [
                    'no_gadai' => $gadai->no_gadai,
                    'nasabah' => $gadai->nasabah->nama_lengkap ?? '-',
                    'barang' => $namaBarang,
                    'detail' => $detailBarang,
                    'jt_lama' => $p->tgl_jatuh_tempo_lama ? Carbon::parse($p->tgl_jatuh_tempo_lama)->format('d/m/Y') : '-',
                    'jt_baru' => $p->jatuh_tempo_baru ? Carbon::parse($p->jatuh_tempo_baru)->format('d/m/Y') : '-',
                    'nominal_pembayaran' => (float)$p->nominal_admin,
                    'metode' => strtoupper($p->metode_pembayaran ?? 'CASH')
                ];
            })->filter()->values();

            return response()->json([
                'success' => true,
                'metadata' => [
                    'halaman' => 3,
                    'tanggal_laporan' => Carbon::parse($tanggal)->translatedFormat('l, d F Y'),
                    'is_approved' => $isApproved,
                    'approved_by' => $namaManager,
                    'doc_id' => $docId,
                    'qr_code' => $qrCode,
                    'total_dana_masuk' => $formattedPerpanjangan->sum('nominal_pembayaran'),
                    'jumlah_transaksi' => $formattedPerpanjangan->count()
                ],
                'data' => $formattedPerpanjangan
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Gagal memuat laporan perpanjangan: ' . $e->getMessage()
            ], 500);
        }
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


    public function cetakLaporanBrankasHarian(Request $request)
    {
        try {
            $tanggal = $request->get('tanggal') ?? Carbon::today()->toDateString();
            
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

            $mutasi = DB::table('transaksi_brankas')
                ->whereDate('created_at', $tanggal)
                ->orderBy('id', 'asc')
                ->get();


            $totalMasuk = $mutasi->where('tipe_transaksi', 'masuk')->sum('nominal');
            $totalKeluar = $mutasi->where('tipe_transaksi', 'keluar')->sum('nominal');
            
            $lastRow = $mutasi->last();
            $saldoAkhirHariIni = $lastRow ? (float)$lastRow->saldo_akhir : 0;

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
                    'formatted_saldo_akhir' => 'Rp ' . number_format($saldoAkhirHariIni, 0, ',', '.')
                ],
                'data_mutasi' => $mutasi
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


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

public function ajukanLaporanChecker(Request $request)
{
    try {
        $tanggal = $request->report_date ?? date('Y-m-d');
        $tipeLaporan = [
            'serah_terima'  => 'REP-LNS',
            'perpanjangan'  => 'REP-PJG',
            'pelelangan'    => 'REP-LLG',
            'brankas'       => 'REP-BRK'
        ];

        DB::beginTransaction();
        foreach ($tipeLaporan as $type => $prefix) {
            ReportPrint::updateOrCreate(
                ['report_type' => $type, 'report_date' => $tanggal],
                [
                    'doc_id'      => $prefix . '-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(5)),
                    'printed_by'  => auth()->user()->name,
                    'is_approved' => false,
                    'printed_at'  => now(),
                    'ip_address'  => $request->ip(), 
                ]
            );
        }
        DB::commit();

        return response()->json(['success' => true, 'message' => 'Laporan Audit (Hal 2-5) berhasil diajukan sekaligus!']);
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


    public function approveReport(Request $request, $doc_id)
    {
        try {
            $report = ReportPrint::where('doc_id', $doc_id)->firstOrFail();
            $report->update([
                'is_approved' => true,
                'approved_by' => auth()->user()->name,
                'approved_at' => now(),
            ]);

            return response()->json([
                'success' => true, 
                'message' => "Laporan [{$doc_id}] berhasil di-ACC.",
                'data' => $report
            ]);
        } catch (\Exception $e) {
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