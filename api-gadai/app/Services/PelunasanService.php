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
        $pokok = $detailGadai->uang_pinjaman;
        $perpanjanganTerbaru = $detailGadai->perpanjanganTempos()
            ->where('status_bayar', 'lunas')
            ->latest()
            ->first();
        $jatuhTempo = $perpanjanganTerbaru ? $perpanjanganTerbaru->jatuh_tempo_baru : $detailGadai->jatuh_tempo;

        $kalkulasi = $this->hitungDendaDanPenalty(
            $pokok, 
            $jatuhTempo, 
            Carbon::now(), 
            $detailGadai->type->nama_type ?? ''
        );
        $totalRaw = $pokok + $kalkulasi['nominal_denda'] + $kalkulasi['nominal_penalty'];
        $totalFinal = $totalRaw; 

        return [
            'pokok'          => (float)$pokok,
            'denda'          => (float)$kalkulasi['nominal_denda'], 
            'penalty'        => (float)$kalkulasi['nominal_penalty'],
            'hari_terlambat' => $kalkulasi['hari_terlambat'],
            'total_bayar'    => (float)$totalFinal,
            'jatuh_tempo'    => $jatuhTempo,
        ];
    }
}