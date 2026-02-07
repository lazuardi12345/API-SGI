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
        $isElektronik = (str_contains($typeNama, 'hp') || str_contains($typeNama, 'handphone') || str_contains($typeNama, 'laptop') || str_contains($typeNama, 'elektronik'));

        $tglPer = Carbon::parse($tglPerpanjangan);
        $jtLama = Carbon::parse($gadai->jatuh_tempo);
        $jtBaru = Carbon::parse($jtBaru);

        $hariTelat = 0;
        if ($tglPer->gt($jtLama)) {
            $hariTelat = (int) $jtLama->diffInDays($tglPer);
        }

        $durasiBaru = (int) $tglPer->diffInDays($jtBaru);

        $jasaPersen = 0;
        $dendaPersen = 0;
        $admin = 0;
        $penalty = 0;

        if ($isElektronik) {
            $jasaPersen = ($durasiBaru <= 15) ? 0.045 : 0.095;
            if ($hariTelat >= 2) {
                $dendaPersen = 0.003; 
                if ($hariTelat >= 16) {
                    $kalkulasiTrait = $this->hitungDendaDanPenalty($pokok, $jtLama, $tglPer, $typeNama);
                    $penalty = $kalkulasiTrait['nominal_penalty'];
                }
            }
        } else {
            $admin = 10000; 
            $jasaPersen = ($durasiBaru <= 15) ? 0.015 : 0.025;
            if ($hariTelat >= 2) {
                $dendaPersen = 0.001; 
                if ($hariTelat >= 16) {
                    $kalkulasiTrait = $this->hitungDendaDanPenalty($pokok, $jtLama, $tglPer, $typeNama);
                    $penalty = $kalkulasiTrait['nominal_penalty'];
                }
            }
        }

        // --- PEMBULATAN CEIL PER 500 HANYA UNTUK JASA & DENDA ---
        $nominalJasa    = ceil(($pokok * $jasaPersen) / 500) * 500;
        $nominalDenda   = ceil(($pokok * $dendaPersen * $hariTelat) / 500) * 500;
        
        // --- PENALTY TETAP (FIXED) ---
        $nominalPenalty = (float) $penalty; 
        $nominalAdmin   = (float) $admin;

        $totalBayar = $nominalJasa + $nominalDenda + $nominalAdmin + $nominalPenalty;

        return [
            'pokok'             => $pokok,
            'is_elektronik'     => $isElektronik,
            'durasi_baru'       => $durasiBaru,
            'hari_telat'        => $hariTelat,
            'jasa_perpanjangan' => $nominalJasa,
            'denda_telat'       => $nominalDenda,
            'nominal_admin'     => $nominalAdmin,
            'penalty'           => $nominalPenalty, // Tetap 180.000 gak geser
            'total_bayar'       => $totalBayar,
            'rincian_persen'    => [
                'jasa'  => ($jasaPersen * 100) . '%',
                'denda' => ($dendaPersen * 100) . '% / hari'
            ]
        ];
    }
}