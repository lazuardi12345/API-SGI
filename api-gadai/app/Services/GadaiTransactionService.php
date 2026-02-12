<?php

namespace App\Services;

use App\Models\GadaiAwalDetail;
use App\Models\PelunasanLog;
use App\Services\GadaiDateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GadaiTransactionService
{
    public function recordGadaiAwal($gadai, array $hitung)
    {
        $dateService = new GadaiDateService();
        

        $tenorMurni = $dateService->hitungTenorMurni($gadai->tanggal_gadai, $gadai->jatuh_tempo);

        return GadaiAwalDetail::updateOrCreate(
            ['detail_gadai_id' => $gadai->id],
            [
                'pokok'          => $hitung['pokok'],
                'jasa_sewa'      => $hitung['jasa_sewa'],
                'administrasi'   => $hitung['administrasi'],
                'asuransi'       => $hitung['asuransi'],
                'total_diterima' => $hitung['total_diterima'],
                'tenor_hari'     => $tenorMurni, 
                'persen_jasa'    => (float) str_replace('%', '', $hitung['persen_jasa'] ?? '4.5'),
            ]
        );
    }

    public function recordPelunasan($gadai, array $perhitungan, $request, $pathBukti = null)
    {
        return PelunasanLog::create([
            'detail_gadai_id'   => $gadai->id,
            'user_id'           => Auth::id(),
            'pokok'             => $perhitungan['pokok'],
            'denda'             => $perhitungan['denda'],
            'penalty'           => $perhitungan['penalty'],
            'total_bayar'       => $perhitungan['total_bayar'],
            'hari_terlambat'    => $perhitungan['hari_terlambat'],
            'metode_pembayaran' => $request->metode_pembayaran,
            'bukti_transfer'    => $pathBukti,
            'tanggal_bayar'     => now(),
        ]);
    }
    
}