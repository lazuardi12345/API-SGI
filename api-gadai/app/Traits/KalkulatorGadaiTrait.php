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
    $jt = Carbon::parse($tglJatuhTempo);
    $tgl = Carbon::parse($tglTransaksi);
    $type = strtolower(trim($typeNama));
    $isHp = $type === 'handphone' || str_contains($type, 'hp');
    
    $hariTerlambat = 0;
    
    if ($tgl->gt($jt)) {
        $diff = (int) $jt->diffInDays($tgl);
        
        // SOP: JT tanggal 15, bayar tanggal 16 (diff=1) -> 1 - 1 = 0 (Toleransi)
        // SOP: JT tanggal 15, bayar tanggal 17 (diff=2) -> 2 - 1 = 1 (Telat 1 hari)
        // SOP: JT tanggal 29 Des, bayar 3 Feb (diff=36) -> 36 - 1 = 35 (Telat 35 hari)
        $hariTerlambat = max(0, $diff - 1); 
    }

    $rateDenda = $isHp ? 0.003 : 0.001;
    $dendaRaw = $pokok * $rateDenda * $hariTerlambat;

    $denda = $this->bulatkanKe500($dendaRaw);
    
    // Penalty juga pakai hariTerlambat yang sudah dipotong toleransi
    $penalty = ($hariTerlambat > 15) ? 180000 : 0; 

    return [
        'hari_terlambat' => $hariTerlambat,
        'nominal_denda'  => (float) $denda,
        'nominal_penalty' => (float) $penalty,
    ];
}

    public function hitungBiayaJasaPerpanjangan($pokok, $tglPerpanjanganTerakhir, $tglTransaksi)
    {
        $mulai = Carbon::parse($tglPerpanjanganTerakhir);
        $akhir = Carbon::parse($tglTransaksi);

        $diffBulan = max((int) ceil($mulai->diffInMonths($akhir, true)), 1);

        $biayaJasaRaw = $pokok * 0.01 * $diffBulan;

        return (float) $this->bulatkanKe500($biayaJasaRaw);
    }

    public function hitungTotalTagihanLelang($detailGadai, $tglAcuan = null)
    {
        $tglAcuan = $tglAcuan ? Carbon::parse($tglAcuan) : Carbon::now();
        $pokok = (float) $detailGadai->uang_pinjaman;

        $perpanjangan = $detailGadai->perpanjanganTempos()
            ->where('status_bayar', 'lunas')
            ->latest()
            ->first();

        $biayaJasa = 0;
        $jatuhTempoAktif = $detailGadai->jatuh_tempo; 
        
        if ($perpanjangan) {
            $jatuhTempoAktif = $perpanjangan->jatuh_tempo_baru;
            $biayaJasa = $this->hitungBiayaJasaPerpanjangan(
                $pokok, 
                $perpanjangan->created_at, 
                $tglAcuan
            );
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
            'hari_terlambat' => $kalkulasiDendaPenalty['hari_terlambat'],
            'has_perpanjangan' => $perpanjangan ? true : false, 
            'pokok' => (float) $pokok,
            'biaya_jasa' => (float) $biayaJasa, 
            'denda' => (float) $kalkulasiDendaPenalty['nominal_denda'],
            'penalty' => (float) $kalkulasiDendaPenalty['nominal_penalty'],
            'total_hutang' => (float) $totalHutang,
        ];
    }
}