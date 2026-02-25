<?php

namespace App\Traits;

use Carbon\Carbon;

trait KalkulatorGadaiTrait
{
    private function bulatkanKe500($nilai)
    {
        return (int) (ceil($nilai / 500) * 500);
    }

    public function hitungDendaDanPenalty($pokok, $tglJatuhTempo, $tglTransaksi, $typeNama)
    {
        $jt = Carbon::parse($tglJatuhTempo)->startOfDay();
        $tgl = Carbon::parse($tglTransaksi)->startOfDay();
        $type = strtolower(trim($typeNama));
        
        $isHp = str_contains($type, 'hp') || str_contains($type, 'handphone') || str_contains($type, 'laptop') || str_contains($type, 'elektronik');
        
        $hariTerlambat = 0;
        if ($tgl->gt($jt)) {
            $hariTerlambat = (int) $jt->diffInDays($tgl);
        }
        
        $rateDenda = $isHp ? 0.003 : 0.001;
        $dendaRaw = $pokok * $rateDenda * $hariTerlambat;
        $denda = $this->bulatkanKe500($dendaRaw);
        $penalty = ($hariTerlambat > 15) ? 180000 : 0; 
        
        return [
            'hari_terlambat' => $hariTerlambat,
            'nominal_denda'  => (float) $denda,
            'nominal_penalty' => (float) $penalty,
        ];
    }

    public function hitungBiayaJasaPerpanjangan($pokok, $tglPerpanjanganTerakhir, $tglTransaksi)
    {
        $mulai = Carbon::parse($tglPerpanjanganTerakhir)->startOfDay();
        $akhir = Carbon::parse($tglTransaksi)->startOfDay();
        $diffBulan = max((int) ceil($mulai->diffInMonths($akhir, true)), 1);
        $biayaJasaRaw = $pokok * 0.01 * $diffBulan;
        return (float) $this->bulatkanKe500($biayaJasaRaw);
    }

    /**
     * MODIFIKASI: Tambahkan $isPelunasan = false sebagai default
     */
    public function hitungTotalTagihanLelang($detailGadai, $tglAcuan = null, $isPelunasan = false)
    {
        $tglAcuan = $tglAcuan ? Carbon::parse($tglAcuan) : Carbon::now();
        $pokok = (float) $detailGadai->uang_pinjaman;
        
        if ($detailGadai->relationLoaded('perpanjanganTempos')) {
            $perpanjangan = $detailGadai->perpanjanganTempos
                ->where('status_bayar', 'lunas')
                ->sortByDesc('created_at')
                ->first();
        } else {
            $perpanjangan = $detailGadai->perpanjanganTempos()
                ->where('status_bayar', 'lunas')
                ->latest()
                ->first();
        }
        
        $biayaJasa = 0;
        $jatuhTempoAktif = $detailGadai->jatuh_tempo; 
        
        if ($perpanjangan) {
            $jatuhTempoAktif = $perpanjangan->jatuh_tempo_baru;
            
            // JIKA BUKAN PELUNASAN (Misal untuk Lelang), hitung jasanya.
            // JIKA PELUNASAN, biayaJasa tetap 0.
            if (!$isPelunasan) {
                $biayaJasa = $this->hitungBiayaJasaPerpanjangan(
                    $pokok, 
                    $perpanjangan->created_at, 
                    $tglAcuan
                );
            }
        }
        
        $kalkulasiDendaPenalty = $this->hitungDendaDanPenalty(
            $pokok, 
            $jatuhTempoAktif, 
            $tglAcuan, 
            $detailGadai->type->nama_type ?? ''
        );
        
        $totalHutang = $pokok 
                     + $biayaJasa 
                     + $kalkulasiDendaPenalty['nominal_denda'] 
                     + $kalkulasiDendaPenalty['nominal_penalty'];
        
        return [
            'jatuh_tempo_used' => $jatuhTempoAktif,
            'hari_terlambat'   => $kalkulasiDendaPenalty['hari_terlambat'],
            'has_perpanjangan' => (bool) $perpanjangan, 
            'pokok'            => (float) $pokok,
            'biaya_jasa'       => (float) $biayaJasa, 
            'denda'            => (float) $kalkulasiDendaPenalty['nominal_denda'],
            'penalty'          => (float) $kalkulasiDendaPenalty['nominal_penalty'],
            'total_hutang'     => (float) $totalHutang,
        ];
    }
}