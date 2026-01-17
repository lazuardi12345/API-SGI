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

    if (!$tanggalAcuan) {
        if (in_array(strtolower($detail->status), ['lunas', 'terlelang', 'selesai'])) {
            $tanggalAcuan = $detail->tanggal_bayar ? Carbon::parse($detail->tanggal_bayar) : Carbon::parse($detail->updated_at);
        } else {
            $tanggalAcuan = Carbon::now();
        }
    } else {
        $tanggalAcuan = Carbon::parse($tanggalAcuan);
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
    $data = DetailGadai::with([
        'nasabah', 'type', 'approvals'
    ])->orderByDesc('created_at')->get();

    $result = $data->map(function ($d) {
        $kalkulasi = $this->hitungKalkulasi($d);

        $status_checker = $d->approvals->where('role', 'checker')->sortByDesc('created_at')->first()->status ?? "-";
        $status_hm = $d->approvals->where('role', 'hm')->sortByDesc('created_at')->first()->status ?? "-";

        return [
            'id' => $d->id,
            'no_gadai' => $d->no_gadai,
            'nama_nasabah' => $d->nasabah->nama_lengkap ?? "-",
            'status' => $d->status,
            'pinjaman_pokok' => $d->uang_pinjaman,
            'type' => $d->type->nama_type ?? "-",
            'tenor_pilihan' => $kalkulasi['tenor_pilihan'], 
            'hari_terlambat' => $kalkulasi['hari_terlambat'],  
            'total_hutang' => $kalkulasi['total_hutang'],
            'denda' => $kalkulasi['denda'],
            'acc_checker' => $status_checker,
            'acc_hm' => $status_hm,
            'jatuh_tempo' => $kalkulasi['jatuh_tempo']
        ];
    });

    return response()->json([
        'success' => true,
        'message' => 'Data laporan admin.',
        'data' => $result
    ]);
}

    public function detailAdmin($detailGadaiId, Request $request)
    {
        $detail = DetailGadai::with([
            'nasabah', 'type', 'approvals.user', 'perpanjanganTempos',
            'hp.merk', 'hp.type_hp', 'hp.grade', 'hp.kerusakanList', 'hp.kelengkapanList', 'hp.dokumenPendukungHp'
        ])->findOrFail($detailGadaiId);

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