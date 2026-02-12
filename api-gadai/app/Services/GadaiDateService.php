<?php

namespace App\Services;

use Carbon\Carbon;

class GadaiDateService
{
    /**
     * Menghitung Jatuh Tempo Otomatis
     * Tgl 12 + (15 - 1) = Tgl 26.
     */
    public function hitungJatuhTempoOtomatis($tanggalGadai, $pilihanTenor = 15)
    {
        if (!$tanggalGadai) return null;
        return Carbon::parse($tanggalGadai)->addDays($pilihanTenor - 1)->toDateString();
    }

    /**
     * INI YANG TADI KURANG: Hitung Selisih Hari Murni (H+1)
     * Contoh: 12 Feb ke 26 Feb = 15 Hari.
     */
    public function hitungTenorMurni($tanggalGadai, $jatuhTempo)
    {
        $start = Carbon::parse($tanggalGadai)->startOfDay();
        $end = Carbon::parse($jatuhTempo)->startOfDay();
        
        // diffInDays 12 ke 26 adalah 14. + 1 supaya jadi 15.
        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Mendeteksi Paket Tenor
     */
    public function deteksiTenor($tanggalGadai, $jatuhTempo)
    {
        $selisih = $this->hitungTenorMurni($tanggalGadai, $jatuhTempo);
        return ($selisih <= 15) ? 15 : 30;
    }

    /**
     * Hitung Hari Telat (Toleransi H+1)
     */
    public function hitungHariTelat($jatuhTempo, $tanggalAcuan = null)
    {
        $acuan = $tanggalAcuan ? Carbon::parse($tanggalAcuan)->startOfDay() : Carbon::now()->startOfDay();
        $jt = Carbon::parse($jatuhTempo)->startOfDay();

        if ($acuan->lte($jt)) return 0;

        $selisih = (int) $jt->diffInDays($acuan, false);
        
        // Toleransi 1 hari setelah JT (H+1 aman)
        return max(0, $selisih - 1); 
    }
}