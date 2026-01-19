<?php

namespace App\Http\Controllers;

use App\Models\DetailGadai;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminApprovalController extends Controller
{
private function hitungKalkulasi($detail, $tanggalAcuan = null)
{
    $tglGadai = Carbon::parse($detail->tanggal_gadai);
    $perpanjanganTerakhir = $detail->perpanjanganTempos->last();
    $tglJatuhTempo = $perpanjanganTerakhir 
        ? Carbon::parse($perpanjanganTerakhir->jatuh_tempo_baru) 
        : Carbon::parse($detail->jatuh_tempo);

    $statusStr = strtolower($detail->status);
    $statusSelesai = in_array($statusStr, ['lunas', 'terlelang', 'selesai']);
    $statusProses = ($statusStr === 'proses'); 

    if (!$tanggalAcuan) {
        if ($statusSelesai) {
            $tanggalAcuan = $detail->tanggal_bayar ? Carbon::parse($detail->tanggal_bayar) : Carbon::parse($detail->updated_at);
        } else {
            $tanggalAcuan = Carbon::now();
        }
    } else {
        $tanggalAcuan = Carbon::parse($tanggalAcuan);
    }

    if ($statusProses) {
        return [
            'tenor_pilihan' => "0 Hari",
            'hari_terlambat' => 0,
            'bunga' => 0,
            'admin' => 0,
            'asuransi' => 0,
            'penalty' => 0,
            'denda' => 0,
            'total_hutang' => 0,
            'jatuh_tempo' => $tglJatuhTempo->format('Y-m-d'),
            'tanggal_yang_dipakai' => $tanggalAcuan->format('Y-m-d')
        ];
    }

    $hariTerlambat = 0;
    $isTerlambat = false;

    if ($tanggalAcuan->gt($tglJatuhTempo)) {
        $hariTerlambatAsli = (int) $tglJatuhTempo->diffInDays($tanggalAcuan);
        $toleransi = 1;
        
        if ($hariTerlambatAsli > $toleransi) {
            $hariTerlambat = $hariTerlambatAsli - $toleransi;
            $isTerlambat = true;
        }
    }

    $pinjaman = (float) $detail->uang_pinjaman;
    $jenisBarang = strtolower($detail->type->nama_type ?? '');
    $isHP = str_contains($jenisBarang, 'hp') || str_contains($jenisBarang, 'handphone') || str_contains($jenisBarang, 'elektronik');
    
    $bunga = 0;
    $denda = 0;
    $penalty = 0;

    if ($isTerlambat) {
        $totalHariPinjam = (int) $tglGadai->diffInDays($tanggalAcuan);
        $persenJasa = 0;

        if ($isHP) {
            if ($totalHariPinjam <= 15) $persenJasa = 0.045;
            elseif ($totalHariPinjam <= 30) $persenJasa = 0.095;
            elseif ($totalHariPinjam <= 45) $persenJasa = 0.145;
            elseif ($totalHariPinjam <= 60) $persenJasa = 0.195;
            else {
                $extraBlocks = ceil(($totalHariPinjam - 60) / 15);
                $persenJasa = 0.195 + ($extraBlocks * 0.05);
            }
        } else {
            if ($totalHariPinjam <= 15) $persenJasa = 0.015;
            elseif ($totalHariPinjam <= 30) $persenJasa = 0.025;
            elseif ($totalHariPinjam <= 45) $persenJasa = 0.04;
            elseif ($totalHariPinjam <= 60) $persenJasa = 0.05;
            else {
                $extraBlocks = ceil(($totalHariPinjam - 60) / 15);
                $persenJasa = 0.05 + ($extraBlocks * 0.01);
            }
        }
        $bunga = $pinjaman * $persenJasa;

        $rateDenda = $isHP ? 0.003 : 0.001;
        $denda = $pinjaman * $rateDenda * $hariTerlambat;

        if ($hariTerlambat > 15) {
            $penalty = 180000;
        }
    }

    $adminRaw = $pinjaman * 0.01;
    $isEmas = str_contains($jenisBarang, 'emas') || str_contains($jenisBarang, 'logam mulia') || str_contains($jenisBarang, 'retro') || str_contains($jenisBarang, 'perhiasan');
    $adminInfo = $isEmas ? max($adminRaw, 10000) : max($adminRaw, 5000);
    $asuransiInfo = 10000;

    $totalRaw = $pinjaman + $bunga + $denda + $penalty;
    $totalHutang = (int) (ceil($totalRaw / 1000) * 1000);

    if ($statusSelesai) {
        $totalHutang = 0;
    }

    return [
        'tenor_pilihan' => (int) $tglGadai->diffInDays($tanggalAcuan) . " Hari",
        'hari_terlambat' => $hariTerlambat,
        'bunga' => round($bunga),
        'admin' => round($adminInfo),
        'asuransi' => $asuransiInfo,
        'penalty' => $penalty,
        'denda' => round($denda),
        'total_hutang' => $totalHutang, 
        'jatuh_tempo' => $tglJatuhTempo->format('Y-m-d'),
        'tanggal_yang_dipakai' => $tanggalAcuan->format('Y-m-d')
    ];
}

public function index(Request $request)
{
    // Default filter hanya menampilkan yang 'lunas'
    $statusFilter = $request->get('status', 'lunas'); 

    $query = DetailGadai::with([
        'nasabah', 
        'type', 
        'approvals',
        'hp.type_hp.hargaTerbaru', 
        'perhiasan', 
        'logamMulia', 
        'retro'
    ]);

    if ($statusFilter !== 'all') {
        $query->where('status', $statusFilter);
    }

    $data = $query->orderByDesc('created_at')->get();

    $result = $data->map(function ($d) {
        $kalkulasi = $this->hitungKalkulasi($d);
        $hargaBarangMaster = 0;

        if ($d->hp && $d->hp->type_hp && $d->hp->type_hp->hargaTerbaru) {
            $hargaBarangMaster = $d->hp->type_hp->hargaTerbaru->harga_barang;
        } else {
            $hargaBarangMaster = 0;
        }

        $status_checker = $d->approvals->where('role', 'checker')->sortByDesc('created_at')->first()->status ?? "-";
        $status_hm = $d->approvals->where('role', 'hm')->sortByDesc('created_at')->first()->status ?? "-";
        $totalHutang = (strtolower($d->status) === 'lunas') 
            ? (float) $d->nominal_bayar 
            : (float) ($kalkulasi['total_hutang'] ?? 0);

        return [
            'id' => $d->id,
            'no_gadai' => $d->no_gadai,
            'nama_nasabah' => $d->nasabah->nama_lengkap ?? "-",
            'status' => $d->status, 
            'type' => $d->type->nama_type ?? "-",
            'harga_barang'   => (float) $hargaBarangMaster,  
            'taksiran'       => (float) $d->taksiran,        
            'pinjaman_pokok' => (float) $d->uang_pinjaman,   

            'tenor_pilihan'  => $kalkulasi['tenor_pilihan'] ?? "-", 
            'hari_terlambat' => $kalkulasi['hari_terlambat'] ?? 0,  
            'total_hutang'   => $totalHutang,
            'denda'          => (float) ($kalkulasi['denda'] ?? 0),
            'acc_checker'    => $status_checker,
            'acc_hm'         => $status_hm,
            'jatuh_tempo'    => $d->jatuh_tempo
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Laporan Admin - Status: ' . $statusFilter,
        'data' => $result
    ]);
}

public function detailAdmin($detailGadaiId, Request $request)
{
    $detail = DetailGadai::with([
        'nasabah', 
        'type', 
        'approvals.user', 
        'perpanjanganTempos',
        'hp.merk', 'hp.type_hp', 'hp.grade', 'hp.kerusakanList', 'hp.kelengkapanList', 'hp.dokumenPendukungHp',
        'perhiasan.kelengkapan', 'perhiasan.dokumenPendukung',
        'logamMulia.kelengkapanEmas', 'logamMulia.dokumenPendukung',
        'retro.kelengkapan', 'retro.dokumenPendukung'
    ])->findOrFail($detailGadaiId);


    $relasiEmas = ['perhiasan', 'logamMulia', 'retro'];

    foreach ($relasiEmas as $rel) {
        if ($detail->$rel && $detail->$rel->dokumenPendukung) {
            $dokumen = $detail->$rel->dokumenPendukung;
            $converted = [];
            
            foreach ($dokumen->getAttributes() as $key => $path) {

                if (!in_array($key, ['id', 'emas_type', 'emas_id', 'created_at', 'updated_at']) && $path) {
                    $converted[$key] = url("api/files/{$path}");
                }
            }

            $detail->$rel->setAttribute('url_dokumen', $converted);
        }
    }

    $kalkulasi = $this->hitungKalkulasi($detail);

    return response()->json([
        'success' => true,
        'data' => [
            'detail_gadai' => $detail,
            'perhitungan_awal' => [
                'tenor_hari' => $kalkulasi['tenor_pilihan'], 
                'pinjaman' => $detail->uang_pinjaman,
            ],
            'perhitungan_keterlambatan' => $kalkulasi 
        ]
    ]);
}
}