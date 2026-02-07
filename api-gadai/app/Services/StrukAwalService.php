<?php

namespace App\Services;

use Carbon\Carbon;

class StrukAwalService
{
    public function hitungStrukAwal($gadai): array
    {
        $pinjaman = (float) $gadai->uang_pinjaman;
        $typeNama = strtolower($gadai->type->nama_type ?? '');
        
        $tglGadai = Carbon::parse($gadai->tanggal_gadai);
        $tglJT    = Carbon::parse($gadai->jatuh_tempo);
        $selisihHari = (int) $tglGadai->diffInDays($tglJT);
        $blokHari = [15, 30, 45, 60, 75, 90, 105, 120];
        foreach ($blokHari as $batas) {
            if ($selisihHari === $batas + 1) {
                $selisihHari = $batas;
                break;
            }
        }

        $persenJasa = 0;
        if (str_contains($typeNama, 'hp') || str_contains($typeNama, 'handphone')) {
            if ($selisihHari <= 15) $persenJasa = 0.045;
            elseif ($selisihHari <= 30) $persenJasa = 0.095;
            elseif ($selisihHari <= 45) $persenJasa = 0.145;
            elseif ($selisihHari <= 60) $persenJasa = 0.195;
            else {
                $extraBlocks = ceil(($selisihHari - 60) / 15);
                $persenJasa = 0.195 + ($extraBlocks * 0.05);
            }
        } else {
            if ($selisihHari <= 15) $persenJasa = 0.015;
            elseif ($selisihHari <= 30) $persenJasa = 0.025;
            elseif ($selisihHari <= 45) $persenJasa = 0.04;
            elseif ($selisihHari <= 60) $persenJasa = 0.05;
            else {
                $extraBlocks = ceil(($selisihHari - 60) / 15);
                $persenJasa = 0.05 + ($extraBlocks * 0.01);
            }
        }

        $jasaSewa = ceil(($pinjaman * $persenJasa) / 500) * 500;
        
        $adminRaw = $pinjaman * 0.01;
        if (in_array($typeNama, ['logam mulia', 'retro', 'perhiasan'])) {
            $adminRaw = max($adminRaw, 10000);
        } else {
            $adminRaw = max($adminRaw, 5000);
        }
        $admin = ceil($adminRaw / 500) * 500;

        $asuransi = 10000;
        $totalPotongan = $jasaSewa + $admin + $asuransi;

        return [
            'pokok'          => $pinjaman,
            'jasa_sewa'      => (float) $jasaSewa,
            'administrasi'   => (float) $admin,
            'asuransi'       => (float) $asuransi,
            'total_potongan' => (float) $totalPotongan,
            'total_diterima' => (float) ($pinjaman - $totalPotongan),
            'selisih_hari'   => $selisihHari,
            'persen_jasa'    => ($persenJasa * 100) . '%'
        ];
    }
}