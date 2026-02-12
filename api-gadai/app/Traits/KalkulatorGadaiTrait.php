<?php

namespace App\Traits;

use Carbon\Carbon;

trait KalkulatorGadaiTrait
{
    /**
     * Membulatkan nilai ke kelipatan 500 terdekat ke atas.
     */
    private function bulatkanKe500($nilai)
    {
        return (int) (ceil($nilai / 500) * 500);
    }

    /**
     * Menghitung Denda dan Penalty berdasarkan keterlambatan.
     * Tanpa Double Tolerance karena toleransi sudah ada di tanggal Jatuh Tempo.
     */
    public function hitungDendaDanPenalty($pokok, $tglJatuhTempo, $tglTransaksi, $typeNama)
    {
        $jt = Carbon::parse($tglJatuhTempo)->startOfDay();
        $tgl = Carbon::parse($tglTransaksi)->startOfDay();
        $type = strtolower(trim($typeNama));
        
        // Deteksi Tipe Barang
        $isHp = str_contains($type, 'hp') || str_contains($type, 'handphone');
        
        $hariTerlambat = 0;
        
        if ($tgl->gt($jt)) {
            // Menghitung selisih hari murni
            // Jika JT tgl 2, bayar tgl 12, maka diff = 10 hari.
            $hariTerlambat = (int) $jt->diffInDays($tgl);
        }

        // Rate: HP 0.3%, Emas/Lainnya 0.1%
        $rateDenda = $isHp ? 0.003 : 0.001;
        $dendaRaw = $pokok * $rateDenda * $hariTerlambat;

        // Pembulatan denda sesuai SOP (ke 500 terdekat)
        $denda = $this->bulatkanKe500($dendaRaw);
        
        // Penalty: Jika terlambat lebih dari 15 hari
        $penalty = ($hariTerlambat > 15) ? 180000 : 0; 

        return [
            'hari_terlambat' => $hariTerlambat,
            'nominal_denda'  => (float) $denda,
            'nominal_penalty' => (float) $penalty,
        ];
    }

    /**
     * Menghitung biaya jasa jika nasabah melakukan perpanjangan.
     */
    public function hitungBiayaJasaPerpanjangan($pokok, $tglPerpanjanganTerakhir, $tglTransaksi)
    {
        $mulai = Carbon::parse($tglPerpanjanganTerakhir)->startOfDay();
        $akhir = Carbon::parse($tglTransaksi)->startOfDay();

        // Hitung selisih bulan, minimal 1 bulan
        $diffBulan = max((int) ceil($mulai->diffInMonths($akhir, true)), 1);

        $biayaJasaRaw = $pokok * 0.01 * $diffBulan;

        return (float) $this->bulatkanKe500($biayaJasaRaw);
    }

    /**
     * Menghitung total kewajiban untuk proses Lelang/Pelunasan Total.
     */
    public function hitungTotalTagihanLelang($detailGadai, $tglAcuan = null)
    {
        $tglAcuan = $tglAcuan ? Carbon::parse($tglAcuan) : Carbon::now();
        $pokok = (float) $detailGadai->uang_pinjaman;

        // Cari riwayat perpanjangan terakhir yang sudah lunas (untuk menentukan start date baru)
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