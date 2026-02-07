<?php

namespace App\Services;

use Carbon\Carbon;

class GadaiDateService
{
    /**
     * SOP: Hari ke-1 dihitung. Jadi tgl 1 + 14 hari = tgl 15.
     */
    public function hitungJatuhTempoOtomatis($tanggalGadai, $pilihanTenor = 15)
    {
        if (!$tanggalGadai) return null;
        return Carbon::parse($tanggalGadai)->addDays($pilihanTenor - 1)->toDateString();
    }

    /**
     * Mendeteksi paket tenor 15 atau 30 hari berdasarkan rentang tanggal.
     */
    public function deteksiTenor($tanggalGadai, $jatuhTempo)
    {
        $start = Carbon::parse($tanggalGadai);
        $end = Carbon::parse($jatuhTempo);
        
        // diffInDays murni + 1 hari karena tanggal mulai dihitung sebagai hari ke-1
        $selisih = (int) $start->diffInDays($end) + 1;

        return ($selisih <= 15) ? 15 : 30;
    }

    /**
     * Hitung Telat dengan Toleransi H+1
     * JT tgl 15 -> Bayar tgl 16 (Telat 1 hari - 1 = 0) -> AMAN
     * JT tgl 15 -> Bayar tgl 17 (Telat 2 hari - 1 = 1) -> DENDA 1 HARI
     */
    public function hitungHariTelat($jatuhTempo, $tanggalAcuan = null)
    {
        $acuan = $tanggalAcuan ? Carbon::parse($tanggalAcuan) : Carbon::now();
        $jt = Carbon::parse($jatuhTempo);

        if ($acuan->lte($jt)) return 0;

        $selisih = (int) $jt->diffInDays($acuan, false);
        
        // SOP Toleransi 1 Hari
        return max(0, $selisih - 1); 
    }
}