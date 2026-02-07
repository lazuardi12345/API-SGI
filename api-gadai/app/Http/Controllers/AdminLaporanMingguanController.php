<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\DetailGadai;
use App\Models\Pelelangan;
use App\Services\PelunasanService;
use App\Services\StrukAwalService; 
use App\Services\PerpanjanganService;
use Illuminate\Support\Facades\DB;

class AdminLaporanMingguanController extends Controller
{

    protected $pelunasanService;
    protected $strukAwalService;
    protected $perpanjanganService;

    public function __construct(
        PelunasanService $pelunasanService,
        StrukAwalService $strukAwalService,
        PerpanjanganService $perpanjanganService
    ) {
        $this->pelunasanService = $pelunasanService;
        $this->strukAwalService = $strukAwalService;
        $this->perpanjanganService = $perpanjanganService;
    }

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


public function strukAwalMingguan(Request $request)
{
    try {
        $startDate = $request->query('tanggal_mulai') ?? Carbon::today()->startOfWeek()->toDateString();
        $endDate = $request->query('tanggal_selesai') ?? Carbon::today()->endOfWeek()->toDateString();

        $dataGadai = DetailGadai::with([
            'type', 
            'nasabah.user', 
            'hp.merk', 'hp.type_hp', 'hp.kerusakanList', 'hp.kelengkapanList',
            'retro.kelengkapan', 
            'perhiasan.kelengkapan', 
            'logamMulia.kelengkapanEmas'
        ])
        ->whereBetween('tanggal_gadai', [$startDate, $endDate])
        ->orderBy('tanggal_gadai', 'asc')
        ->get();

        $formattedData = $dataGadai->map(function ($item) {
            $kalkulasi = $this->strukAwalService->hitungStrukAwal($item);

            // Penentuan Nama Barang
            $namaBarang = $item->nama_barang;
            if ($item->hp) $namaBarang = $item->hp->nama_barang;
            elseif ($item->retro) $namaBarang = $item->retro->nama_barang;
            elseif ($item->perhiasan) $namaBarang = $item->perhiasan->nama_barang;
            elseif ($item->logamMulia) $namaBarang = $item->logamMulia->nama_barang;

            return [
                'id'            => $item->id,
                'no_gadai'      => $item->no_gadai,
                'tanggal_gadai' => $item->tanggal_gadai,
                'jatuh_tempo'   => $item->jatuh_tempo,
                'taksiran'      => (float)$item->taksiran,
                'nama_nasabah'  => $item->nasabah->nama_lengkap ?? '-',
                'petugas'       => $item->nasabah->user->name ?? '-', 
                'nama_type'     => $item->type->nama_type ?? '-',
                'nama_barang'   => $namaBarang,
                
                // Data Waktu Riil (Pastikan locale Indonesia sudah diset di AppConfig atau Carbon)
                'waktu_formatted' => Carbon::parse($item->created_at)->translatedFormat('l, d F Y'),
                'jam_formatted'   => Carbon::parse($item->created_at)->format('H:i'),

                // Detail Relasi untuk Frontend renderDetailBarang
                'hp'          => $item->hp,
                'perhiasan'   => $item->perhiasan,
                'logam_mulia' => $item->logamMulia,
                'retro'       => $item->retro,
                
                'kalkulasi' => [
                    'jasa_sewa'      => $kalkulasi['jasa_sewa'],
                    'admin'          => $kalkulasi['administrasi'],
                    'asuransi'       => $kalkulasi['asuransi'],
                    'total_potongan' => $kalkulasi['total_potongan'],
                    'total_diterima' => $kalkulasi['total_diterima']
                ]
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

public function rekapPerpanjanganMingguan(Request $request)
{
    try {
        $startDate = $request->query('tanggal_mulai');
        $endDate = $request->query('tanggal_selesai');

        $data = \App\Models\PerpanjanganTempo::with([
            'detailGadai.type', 
            'detailGadai.nasabah.user',
            'detailGadai.hp.merk', 
            'detailGadai.hp.type_hp', 
            'detailGadai.hp.kerusakanList', 
            'detailGadai.hp.kelengkapanList',
            'detailGadai.retro.kelengkapan', 
            'detailGadai.perhiasan.kelengkapan', 
            'detailGadai.logamMulia.kelengkapanEmas'
        ])
        ->where('status_bayar', 'lunas')
        ->whereBetween('tanggal_perpanjangan', [$startDate, $endDate])
        ->get()
        ->map(function ($item) {
            $gadai = $item->detailGadai;
            if (!$gadai) return $item;

            $itungan = $this->perpanjanganService->hitungPerpanjangan($gadai, $item->tanggal_perpanjangan, $item->jatuh_tempo_baru);

            // Mapping Field agar sinkron dengan Frontend
            $item->no_gadai    = $gadai->no_gadai;
            $item->nama_nasabah = $gadai->nasabah->nama_lengkap ?? '-';
            $item->nama_type    = $gadai->type->nama_type ?? '-';
            $item->petugas      = $gadai->nasabah->user->name ?? '-';
            $item->hp           = $gadai->hp;
            $item->perhiasan    = $gadai->perhiasan;
            $item->logam_mulia  = $gadai->logamMulia;
            $item->retro        = $gadai->retro;

            // Waktu Berdasarkan Tanggal Bayar Perpanjangan
            $item->waktu_formatted = Carbon::parse($item->tanggal_perpanjangan)->translatedFormat('l, d F Y');
            $item->jam_formatted   = Carbon::parse($item->tanggal_perpanjangan)->format('H:i');

            $namaBarang = $gadai->nama_barang;
            if ($gadai->hp) $namaBarang = $gadai->hp->nama_barang;
            elseif ($gadai->retro) $namaBarang = $gadai->retro->nama_barang;
            elseif ($gadai->perhiasan) $namaBarang = $gadai->perhiasan->nama_barang;
            elseif ($gadai->logamMulia) $namaBarang = $gadai->logamMulia->nama_barang;
            $item->nama_barang = $namaBarang; // Digunakan di struk

            $item->perhitungan_detail = [
                'jasa'    => (float) $itungan['jasa_perpanjangan'],
                'denda'   => (float) $itungan['denda_telat'],
                'penalty' => (float) $itungan['penalty'],
                'admin'   => (float) $itungan['nominal_admin'],
                'total'   => (float) $itungan['total_bayar'],
                'hari_telat' => $itungan['hari_telat']
            ];

            return $item;
        });

        return response()->json(['success' => true, 'data' => $data]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

public function rekapPelunasanMingguan(Request $request)
{
    try {
        $startDate = $request->query('tanggal_mulai');
        $endDate = $request->query('tanggal_selesai');

        $data = DetailGadai::with([
            'nasabah.user', 'type', 'perpanjanganTempos',
            'hp.merk', 'hp.type_hp', 'hp.kerusakanList', 'hp.kelengkapanList',
            'perhiasan.kelengkapan', 'logamMulia.kelengkapanEmas', 'retro.kelengkapan'
        ])
        ->where('status', 'lunas')
        ->whereBetween('tanggal_bayar', [
            Carbon::parse($startDate)->startOfDay(), 
            Carbon::parse($endDate)->endOfDay()
        ])
        ->get();

        $formattedData = $data->map(function ($item) {
            $kalkulasi = $this->pelunasanService->hitungPelunasan($item);

            // Penentuan Nama Barang
            $namaBarang = $item->nama_barang;
            if ($item->hp) $namaBarang = $item->hp->nama_barang;
            elseif ($item->retro) $namaBarang = $item->retro->nama_barang;
            elseif ($item->perhiasan) $namaBarang = $item->perhiasan->nama_barang;
            elseif ($item->logamMulia) $namaBarang = $item->logamMulia->nama_barang;

            return [
                'id'            => $item->id,
                'no_gadai'      => $item->no_gadai,
                'nama_nasabah'  => $item->nasabah->nama_lengkap ?? '-',
                'petugas'       => $item->nasabah->user->name ?? '-',
                'nama_type'     => $item->type->nama_type ?? '-',
                'nama_barang'   => $namaBarang,
                
                // Detail Relasi
                'hp'          => $item->hp,
                'perhiasan'   => $item->perhiasan,
                'logam_mulia' => $item->logamMulia,
                'retro'       => $item->retro,

                // Waktu Pelunasan Riil
                'waktu_formatted' => Carbon::parse($item->tanggal_bayar)->translatedFormat('l, d F Y'),
                'jam_formatted'   => Carbon::parse($item->tanggal_bayar)->format('H:i'),

                'kalkulasi_rekap' => [
                    'pokok'          => $kalkulasi['pokok'],
                    'hari_telat'     => $kalkulasi['hari_terlambat'],
                    'denda'          => $kalkulasi['denda'], 
                    'penalty'        => $kalkulasi['penalty'],
                    'total_bayar'    => $kalkulasi['total_bayar'],
                    'metode'         => strtoupper($item->metode_pembayaran ?? 'CASH'),
                    'tanggal_lunas'  => Carbon::parse($item->tanggal_bayar)->format('d-m-Y H:i')
                ]
            ];
        });

        return response()->json(['success' => true, 'data' => $formattedData]);
    } catch (\Exception $e) {
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}


private function calculateLateDays($gadai) {
    $perpanjanganTerbaru = $gadai->perpanjangan_tempos->last();
    $jatuhTempo = $perpanjanganTerbaru ? $perpanjanganTerbaru->jatuh_tempo_baru : $gadai->jatuh_tempo;
    
    $tglBayar = \Carbon\Carbon::parse($gadai->tanggal_bayar);
    $jtDate = \Carbon\Carbon::parse($jatuhTempo);

    $selisihHari = $tglBayar->diffInDays($jtDate, false);

    if ($selisihHari >= -1) {
        return 0;
    } else {
        return abs($selisihHari) - 1;
    }
}


public function rekapBulananPelelangan(Request $request)
{
    try {
        \Carbon\Carbon::setLocale('id');
        if ($request->has('tanggal_mulai') && $request->has('tanggal_selesai')) {
            $startDate = Carbon::parse($request->get('tanggal_mulai'))->startOfDay()->toDateTimeString();
            $endDate = Carbon::parse($request->get('tanggal_selesai'))->endOfDay()->toDateTimeString();
            $periodeLabel = Carbon::parse($startDate)->translatedFormat('d F Y') . ' - ' . Carbon::parse($endDate)->translatedFormat('d F Y');
        } else {
            $bulanInput = $request->get('bulan') ?? Carbon::today()->format('Y-m');
            $startDate = Carbon::parse($bulanInput)->startOfMonth()->toDateTimeString();
            $endDate = Carbon::parse($bulanInput)->endOfMonth()->endOfDay()->toDateTimeString();
            $periodeLabel = Carbon::parse($bulanInput)->translatedFormat('F Y');
        }

        $dataLelang = Pelelangan::with([
            'detailGadai.nasabah', 
            'detailGadai.type',
            'detailGadai.hp.merk', 
            'detailGadai.hp.type_hp', 
            'detailGadai.perhiasan',
            'detailGadai.logamMulia', 
            'detailGadai.retro'
        ])
        ->whereBetween('waktu_bayar', [$startDate, $endDate])
        ->whereIn('status_lelang', ['terlelang', 'lunas'])
        ->orderBy('waktu_bayar', 'asc')
        ->get();

        $laporanTabel = [];
        $totalModalKembali = 0;
        $totalKeuntunganLelang = 0;
        $totalPenerimaanDitebus = 0;

        $controllerLelang = new PelelanganController();

        foreach ($dataLelang as $item) {
            if (!$item->detailGadai) continue;

            $status = strtolower($item->status_lelang);
            $kalkulasi = $controllerLelang->hitungKalkulasi($item->detailGadai, $item->waktu_bayar);
            $laporanTabel[] = [
                'tanggal' => Carbon::parse($item->waktu_bayar)->translatedFormat('l, d F Y'),
                'waktu' => Carbon::parse($item->waktu_bayar)->format('H:i'),
                'label_waktu' => ($status === 'lunas') ? 'Waktu Pelunasan' : 'Waktu Terlelang',
                
                'no_gadai' => $item->detailGadai->no_gadai ?? '-',
                'nama_nasabah' => $item->detailGadai->nasabah->nama_lengkap ?? '-',
                'status' => strtoupper($status),
                'detail_full' => $item->detailGadai, 
                'kalkulasi_full' => [
                    'bunga' => (float) ($kalkulasi['bunga'] ?? 0),
                    'denda' => (float) ($kalkulasi['denda'] ?? 0),
                    'penalty' => (float) ($kalkulasi['penalty'] ?? 0),
                    'hari_terlambat' => $kalkulasi['hari_terlambat'] ?? 0,
                    'total_hutang' => (float) ($kalkulasi['total_hutang'] ?? 0),
                ],
                
                'hutang_sistem' => (float) ($kalkulasi['total_hutang'] ?? 0),
                'nominal_masuk' => (float) $item->nominal_diterima,
                'profit_lelang' => (float) $item->keuntungan_lelang,
            ];
            if ($status === 'lunas') {
                $totalPenerimaanDitebus += (float) $item->nominal_diterima;
            } else {
                $totalModalKembali += (float) ($kalkulasi['total_hutang'] ?? 0);
                $totalKeuntunganLelang += (float) $item->keuntungan_lelang;
            }
        }

        return response()->json([
            'success' => true,
            'metadata' => [
                'judul' => 'Laporan Rekap Pelelangan & Pelunasan',
                'periode' => $periodeLabel,
                'total_data' => count($laporanTabel),
                'generated_at' => now()->translatedFormat('d F Y H:i')
            ],
            'data_tabel' => $laporanTabel,
            'summary' => [
                'total_ditebus_nasabah' => $totalPenerimaanDitebus, 
                'total_modal_lelang_kembali' => $totalModalKembali,  
                'total_keuntungan_murni_lelang' => $totalKeuntunganLelang, 
                'grand_total_kas_masuk' => $totalPenerimaanDitebus + $totalModalKembali + $totalKeuntunganLelang
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error Laporan: ' . $e->getMessage() . ' di baris ' . $e->getLine()
        ], 500);
    }
}

}