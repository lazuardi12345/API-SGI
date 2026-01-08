<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Models\DetailGadai;
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


public function strukAwalMingguan(Request $request)
{
    try {
        $tanggalInput = $request->get('tanggal') ?? Carbon::today()->toDateString();
        $date = Carbon::parse($tanggalInput);
        $startDate = $date->startOfWeek()->toDateString();
        $endDate = $date->endOfWeek()->toDateString();
        $dataGadai = DetailGadai::with([
                'type', 
                'nasabah.user', 
                'hp.merk', 
                'hp.type_hp', 
                'hp.kerusakanList', 
                'hp.kelengkapanList',
                'retro.kelengkapan', 
                'perhiasan.kelengkapan', 
                'logamMulia.kelengkapanEmas'
            ])
            ->whereBetween('tanggal_gadai', [$startDate, $endDate])
            ->orderBy('tanggal_gadai', 'asc')
            ->get();

        $formattedData = $dataGadai->map(function ($item) {
            $pinjaman = (float) $item->uang_pinjaman;
            $tglGadai = Carbon::parse($item->tanggal_gadai);
            $tglJatuhTempo = Carbon::parse($item->jatuh_tempo);
            $selisihHari = $tglGadai->diffInDays($tglJatuhTempo);
            $blokHari = [15, 30, 45, 60, 75, 90, 105, 120];
            foreach ($blokHari as $batas) {
                if ($selisihHari == $batas + 1) {
                    $selisihHari = $batas;
                    break;
                }
            }

            $typeLower = strtolower($item->type->nama_type ?? '');
            $persenJasa = 0;
            if ($typeLower == "handphone" || $typeLower == "hp") {
                if ($selisihHari <= 15) $persenJasa = 0.045;
                elseif ($selisihHari <= 30) $persenJasa = 0.095;
                elseif ($selisihHari <= 45) $persenJasa = 0.145;
                elseif ($selisihHari <= 60) $persenJasa = 0.195;
                else $persenJasa = 0.195 + (ceil(($selisihHari - 60) / 15) * 0.05);
            } else {
                if ($selisihHari <= 15) $persenJasa = 0.015;
                elseif ($selisihHari <= 30) $persenJasa = 0.025;
                elseif ($selisihHari <= 45) $persenJasa = 0.04;
                elseif ($selisihHari <= 60) $persenJasa = 0.05;
                else $persenJasa = 0.05 + (ceil(($selisihHari - 60) / 15) * 0.01);
            }

            $adminPersen = $pinjaman * 0.01;
            $admin = in_array($typeLower, ["logam mulia", "retro", "perhiasan"]) 
                     ? max($adminPersen, 10000) 
                     : max($adminPersen, 5000);
            $asuransi = 10000;
            $jasaSewaRaw = $pinjaman * $persenJasa;      
            $totalPotonganBulat = ceil(($jasaSewaRaw + $admin + $asuransi) / 1000) * 1000;
            $jasaSewaFinal = $totalPotonganBulat - $admin - $asuransi;
            $totalDiterima = $pinjaman - $totalPotonganBulat;
            $namaBarang = $item->nama_barang;
            if ($item->hp) $namaBarang = $item->hp->nama_barang;
            elseif ($item->retro) $namaBarang = $item->retro->nama_barang;
            elseif ($item->perhiasan) $namaBarang = $item->perhiasan->nama_barang;
            elseif ($item->logamMulia) $namaBarang = $item->logamMulia->nama_barang;

            return [
                'id' => $item->id,
                'no_gadai' => $item->no_gadai,
                'tanggal_gadai' => $item->tanggal_gadai,
                'jatuh_tempo' => $item->jatuh_tempo,
                'taksiran' => (float) $item->taksiran,
                'nama_nasabah' => $item->nasabah->nama_lengkap ?? '-',
                'petugas' => $item->nasabah->user->name ?? '-', 
                'nama_type' => $item->type->nama_type ?? '-',
                'nama_barang' => $namaBarang,
                'uang_pinjaman' => $pinjaman,
                'hp' => $item->hp,
                'perhiasan' => $item->perhiasan,
                'logam_mulia' => $item->logamMulia,
                'retro' => $item->retro,
                'kalkulasi' => [
                    'jasa_sewa' => $jasaSewaFinal,
                    'admin' => $admin,
                    'asuransi' => $asuransi,
                    'total_potongan' => $totalPotonganBulat,
                    'total_diterima' => $totalDiterima
                ]
            ];
        });

        return response()->json([
            'success' => true,
            'metadata' => [
                'judul' => 'Rekap Struk Awal Mingguan',
                'periode' => Carbon::parse($startDate)->translatedFormat('d F') . " s/d " . Carbon::parse($endDate)->translatedFormat('d F Y'),
                'total_transaksi' => $formattedData->count(),
            ],
            'data' => $formattedData
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false, 
            'message' => 'Error Braqder: ' . $e->getMessage()
        ], 500);
    }
}

public function rekapPerpanjanganMingguan(Request $request)
{
    $startDate = $request->query('tanggal_mulai');
    $endDate = $request->query('tanggal_selesai');

    $data = \App\Models\PerpanjanganTempo::with([
        'detailGadai.nasabah', 
        'detailGadai.type', 
        'detailGadai.hp.merk', 
        'detailGadai.hp.type_hp',
        'detailGadai.perhiasan'
    ])
    ->where('status_bayar', 'lunas')
    ->whereBetween('tanggal_perpanjangan', [$startDate, $endDate])
    ->get()
    ->map(function ($item) {
        $gadai = $item->detailGadai;         
        if (!$gadai) return $item;
        $pokok = (float) $gadai->uang_pinjaman;
        $typeNama = strtolower($gadai->type->nama_type ?? '');
        $tglExtend = \Carbon\Carbon::parse($item->tanggal_perpanjangan);
        $jtLama = \Carbon\Carbon::parse($gadai->tanggal_gadai); 
        $jtBaru = \Carbon\Carbon::parse($item->jatuh_tempo_baru);

        $totalTelat = max(0, $jtLama->diffInDays($tglExtend, false));
        $periodeBaruHari = max(0, $tglExtend->diffInDays($jtBaru, false));
        $isHp = in_array($typeNama, ['handphone', 'hp', 'elektronik']);
        $rateJasa = $isHp ? (($periodeBaruHari <= 15) ? 0.045 : 0.095) : (($periodeBaruHari <= 15) ? 0.015 : 0.025);
        
        $item->perhitungan_detail = [
            'jasa' => $pokok * $rateJasa,
            'denda' => $pokok * ($isHp ? 0.003 : 0.001) * $totalTelat,
            'penalty' => ($totalTelat > 15) ? 180000 : 0,
            'admin' => !$isHp ? max($pokok * 0.01, 10000) : 0,
        ];

        return $item;
    });

    return response()->json([
        'success' => true,
        'data' => $data
    ]);
}

public function rekapPelunasanMingguan(Request $request)
{
    try {
        $startDate = $request->query('tanggal_mulai');
        $endDate = $request->query('tanggal_selesai');

        // Gunakan DetailGadai dan relasi perpanjanganTempos (sesuai Model kamu)
        $data = DetailGadai::with([
            'nasabah.user', 
            'type', 
            'hp.merk', 
            'hp.type_hp',
            'perhiasan',
            'perpanjanganTempos' // Pakai CamelCase sesuai Model!
        ])
        ->where('status', 'lunas')
        ->whereBetween('tanggal_bayar', [$startDate, $endDate])
        ->whereNull('deleted_at')
        ->orderBy('tanggal_bayar', 'asc')
        ->get();

        $formattedData = $data->map(function ($item) {
            $pokok = (float) $item->uang_pinjaman;
            $typeLower = strtolower($item->type->nama_type ?? '');
            $isHp = in_array($typeLower, ['handphone', 'hp', 'elektronik']);

            // 1. Cari Jatuh Tempo Terakhir pakai relasi perpanjanganTempos
            $perpanjanganTerakhir = $item->perpanjanganTempos->last();
            $jatuhTempoTerakhir = $perpanjanganTerakhir 
                ? $perpanjanganTerakhir->jatuh_tempo_baru 
                : $item->jatuh_tempo;

            // 2. Hitung Telat (Tanggal Bayar vs Jatuh Tempo Terakhir)
            $tglBayar = Carbon::parse($item->tanggal_bayar);
            $jtDate = Carbon::parse($jatuhTempoTerakhir);
            
            // diffInDays menghasilkan selisih hari
            $selisihHari = (int) $jtDate->diffInDays($tglBayar, false);
            
            // Logic toleransi 1 hari: telat 1 hari masih dianggap 0 (sesuai request)
            $hariDenda = ($selisihHari <= 1) ? 0 : $selisihHari - 1;

            // 3. Hitung Denda & Penalty
            $persenDenda = $isHp ? 0.003 : 0.001;
            $denda = $pokok * $persenDenda * $hariDenda;
            $penalty = ($hariDenda > 15) ? 180000 : 0;

            // 4. Pembulatan (Ceil ke 1000 terdekat)
            $totalSblmBulat = $pokok + $denda + $penalty;
            $totalBayar = ceil($totalSblmBulat / 1000) * 1000;
            $pembulatan = $totalBayar - $totalSblmBulat;

            // Tempelkan data perhitungan supaya FE gampang manggilnya
            $item->kalkulasi_pelunasan = [
                'jatuh_tempo_terakhir' => $jatuhTempoTerakhir,
                'hari_telat' => $hariDenda,
                'denda' => $denda,
                'penalty' => $penalty,
                'pembulatan' => $pembulatan,
                'total_bayar' => $totalBayar
            ];

            return $item;
        });

        return response()->json([
            'success' => true,
            'metadata' => [
                'periode' => Carbon::parse($startDate)->format('d/m/Y') . " - " . Carbon::parse($endDate)->format('d/m/Y'),
                'total_transaksi' => $formattedData->count()
            ],
            'data' => $formattedData
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false, 
            'message' => 'Error Braqder: ' . $e->getMessage()
        ], 500);
    }
}

}