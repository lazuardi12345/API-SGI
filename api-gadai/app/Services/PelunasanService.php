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

        // Hitung via Trait
        $kalkulasi = $this->hitungDendaDanPenalty(
            $pokok, 
            $jatuhTempo, 
            Carbon::now(), 
            $detailGadai->type->nama_type ?? ''
        );

        $totalRaw = $pokok + $kalkulasi['nominal_denda'] + $kalkulasi['nominal_penalty'];
        $totalFinal = ceil($totalRaw / 1000) * 1000; 

        return [
            'pokok' => (float)$pokok,
            'denda' => (float)$kalkulasi['nominal_denda'], 
            'penalty' => (float)$kalkulasi['nominal_penalty'],
            'hari_terlambat' => $kalkulasi['hari_terlambat'],
            'total_bayar' => (float)$totalFinal,
            'jatuh_tempo' => $jatuhTempo,
        ];
    }
}