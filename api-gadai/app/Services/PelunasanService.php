<?php

namespace App\Services;

use App\Models\DetailGadai;
use Carbon\Carbon;

class PelunasanService
{
    public function hitungPelunasan(DetailGadai $detailGadai): array
    {
        $pokok = $detailGadai->uang_pinjaman;

        $perpanjanganTerbaru = $detailGadai->perpanjangan_tempos()
            ->orderBy('created_at', 'desc')
            ->first();
        
        $jatuhTempo = $perpanjanganTerbaru 
            ? $perpanjanganTerbaru->jatuh_tempo_baru 
            : $detailGadai->jatuh_tempo;

        $today = Carbon::now();
        $jatuhTempoDate = Carbon::parse($jatuhTempo);

        $selisihHari = $today->diffInDays($jatuhTempoDate, false);

        $toleransi = 1;
        if ($selisihHari >= -$toleransi) {
            $selisihHari = 0;
        } else {
            $selisihHari = abs($selisihHari) - $toleransi;
        }
        
        $jenisSkema = $this->tentukanJenisSkema($detailGadai);

        $dendaMurni = 0;
        $penalty = 0;

        if ($selisihHari > 0) {
            $persenDendaPerHari = $jenisSkema === 'hp' ? 0.003 : 0.001;
            $dendaMurni = $pokok * $persenDendaPerHari * $selisihHari;
            
            if ($selisihHari > 15) {
                $penalty = 180000;
            }
        }

        $totalTanpaBulat = $pokok + $dendaMurni + $penalty;
        $totalFinal = ceil($totalTanpaBulat / 1000) * 1000; 

        $selisihPembulatan = $totalFinal - $totalTanpaBulat;
        $dendaFinal = $dendaMurni + $selisihPembulatan;

        return [
            'pokok' => $pokok,
            'denda' => $dendaFinal, 
            'penalty' => $penalty,
            'hari_terlambat' => $selisihHari,
            'total_bayar' => $totalFinal,
            'jatuh_tempo' => $jatuhTempo,
            'jenis_skema' => $jenisSkema,

        ];
    }

    private function tentukanJenisSkema(DetailGadai $detailGadai): string
    {
        $typeNama = strtolower($detailGadai->type->nama_type ?? '');
        $skemaHp = ['handphone', 'elektronik'];
        return in_array($typeNama, $skemaHp) ? 'hp' : 'non-hp';
    }
}