<?php

namespace App\Services;

use App\Traits\KalkulatorGadaiTrait;
use Carbon\Carbon;

class PerpanjanganService
{
    use KalkulatorGadaiTrait;

  public function hitungPerpanjangan($gadai, $tglPerpanjangan, $jtBaru)
{
    $pokok = (float) $gadai->uang_pinjaman;
    $typeNama = strtolower($gadai->type->nama_type ?? '');
    
    $tglPer = Carbon::parse($tglPerpanjangan);
    $jtLama = Carbon::parse($gadai->jatuh_tempo);
    $jtBaru = Carbon::parse($jtBaru);

    $durasiBaru = (int) $tglPer->diffInDays($jtBaru);
    $isElektronik = (str_contains($typeNama, 'hp') || str_contains($typeNama, 'elektronik'));
    
    if ($isElektronik) {
        $jasaPersen = ($durasiBaru <= 15) ? 0.045 : 0.095;
        $admin = 0;
    } else {
        $jasaPersen = ($durasiBaru <= 15) ? 0.015 : 0.025;
        $admin = 10000;
    }

    $kalkulasiDenda = $this->hitungDendaDanPenalty($pokok, $jtLama, $tglPer, $typeNama);

    $nominalJasa    = ceil(($pokok * $jasaPersen) / 500) * 500;
    $nominalDenda   = $kalkulasiDenda['nominal_denda'];
    $nominalPenalty = $kalkulasiDenda['nominal_penalty'];
    $nominalAdmin   = (float) $admin;

    $totalBayar = $nominalJasa + $nominalDenda + $nominalAdmin + $nominalPenalty;

    return [
        'pokok'             => $pokok,
        'durasi_baru'       => $durasiBaru,
        'hari_telat'        => $kalkulasiDenda['hari_terlambat'],
        'jasa_perpanjangan' => $nominalJasa,
        'denda_telat'       => $nominalDenda,
        'nominal_admin'     => $nominalAdmin,
        'penalty'           => $nominalPenalty,
        'total_bayar'       => $totalBayar,
    ];
}
}