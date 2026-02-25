<?php

namespace App\Services;

use App\Models\DetailGadai;
use App\Traits\KalkulatorGadaiTrait;
use Carbon\Carbon;

class PelunasanService
{
    use KalkulatorGadaiTrait;

    public function hitungPelunasan(DetailGadai $detailGadai): array
    {
        $kalkulasi = $this->hitungTotalTagihanLelang($detailGadai, Carbon::now(), true);

        return [
            'pokok'          => $kalkulasi['pokok'],
            'biaya_jasa'     => 0, 
            'denda'          => $kalkulasi['denda'], 
            'penalty'        => $kalkulasi['penalty'],
            'hari_terlambat' => $kalkulasi['hari_terlambat'],
            'total_bayar'    => $kalkulasi['total_hutang'], 
            'jatuh_tempo'    => $kalkulasi['jatuh_tempo_used'],
        ];
    }
}