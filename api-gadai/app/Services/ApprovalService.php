<?php

namespace App\Services;

use App\Models\DetailGadai;

class ApprovalService
{
    public function isSmallLimit($item)
    {
        $nominal = (float) $item->uang_pinjaman;
        $namaType = strtolower($item->type->nama_type ?? '');

        if (preg_match('/(hp|handphone)/', $namaType)) return $nominal <= 2000000;
        if (preg_match('/(emas|perhiasan|logam_mulia|retro)/', $namaType)) return $nominal <= 4000000;
        
        return false;
    }

    public function getPendingQuery($user)
    {
        $query = DetailGadai::with(['type', 'nasabah', 'approvals', 'hp', 'perhiasan', 'logamMulia', 'retro']);

        if ($user->role === 'hm') {
            return $query->whereHas('approvals', function ($q) {
                $q->whereIn('status', ['approved_checker', 'rejected_checker']);
            })->whereDoesntHave('approvals', function ($q) {
                $q->whereIn('status', ['approved_hm', 'rejected_hm']);
            });
        }

        // Role Checker: Ambil yang 'Selesai' tapi belum ada action approval
        return $query->where('status', 'Selesai')->whereDoesntHave('approvals');
    }

  public function formatItem($item)
{
    $namaBarang = '-';
    if ($item->hp) $namaBarang = $item->hp->nama_barang;
    elseif ($item->perhiasan) $namaBarang = $item->perhiasan->nama_barang ?? 'Perhiasan';
    elseif ($item->logamMulia) $namaBarang = $item->logamMulia->nama_barang;
    elseif ($item->retro) $namaBarang = $item->retro->nama_barang;
    $statusChecker = $item->approvals->where('role', 'checker')->first()?->status ?? 'pending';
    $statusHM = $item->approvals->where('role', 'hm')->first()?->status ?? 'pending';

    return [
        'id'               => $item->id,
        'no_gadai'         => $item->no_gadai,
        'nama_nasabah'     => $item->nasabah->nama_lengkap ?? '-',
        'jenis_barang'     => $item->type->nama_type ?? '-',
        'detail_barang'    => $namaBarang,
        'taksiran'         => (float) $item->taksiran,
        'uang_pinjaman'    => (float) $item->uang_pinjaman,
        'status_gadai'     => $item->status, 
        'status_checker'   => $statusChecker, 
        'status_hm'        => $statusHM,      
        'tanggal_gadai'    => $item->tanggal_gadai,
    ];
}
}