<?php

namespace App\Services;

use App\Http\Controllers\NotificationServiceController;

class NotificationService
{
    protected $controller;

    public function __construct()
    {
        $this->controller = app(NotificationServiceController::class);
    }

    /**
     * ✅ Notifikasi Gadai Baru
     */
    public function notifyNewTransaction($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $senderId = auth()->id();
        $namaNasabah = $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama';

        \Log::info('🔔 Kirim notifikasi GADAI BARU ke NestJS', [
            'no_gadai' => $detailGadai->no_gadai,
            'petugas_id' => $senderId
        ]);

        return $this->controller->sendNotification([
            'user_id'          => (int) $senderId, 
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $namaNasabah,
            'title'            => 'Gadai Baru Dimulai',
            'message'          => "Transaksi {$detailGadai->no_gadai} atas nama {$namaNasabah} berhasil dibuat.",
            'status_transaksi' => 'proses',
            'type'             => 'NEW_PAWN', // ✅ Type untuk routing
            'url'              => "/gadai/detail/{$detailGadai->no_gadai}"
        ]);
    }

    /**
     * ✅ Notifikasi Unit Selesai Divalidasi (Status: proses → selesai)
     * Endpoint: /notifications/pawn-apps/new-pawn-application-status-after-check
     */
    public function notifyUnitSelesai($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        
        \Log::info('🔔 Kirim notifikasi VALIDASI SELESAI ke NestJS', [
            'no_gadai' => $detailGadai->no_gadai,
            'user_id' => auth()->id()
        ]);

        return $this->controller->sendNotification([
            'user_id'          => (int) auth()->id(),
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama',
            'status_transaksi' => 'selesai', 
            'nominal_cair'     => (int) $detailGadai->uang_pinjaman, 
            'title'            => 'Unit Selesai Divalidasi',
            'message'          => "Unit {$detailGadai->no_gadai} DITERIMA. Silakan cairkan dana Rp " . number_format($detailGadai->uang_pinjaman, 0, ',', '.'),
            'type'             => 'UNIT_VALIDATED' // ✅ Type untuk routing ke after-check
        ]);
    }

    /**
     * ✅ Notifikasi Pelunasan (Status: selesai → lunas)
     * Endpoint: /notifications/pawn-apps/new-pawn-application-status-after-repayment
     */
    public function notifyPelunasan($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $totalPelunasan = $detailGadai->nominal_bayar ?? ($detailGadai->uang_pinjaman + ($detailGadai->biaya_sewa ?? 0));

        \Log::info('🔔 Kirim notifikasi PELUNASAN ke NestJS', [
            'no_gadai' => $detailGadai->no_gadai,
            'nominal' => $totalPelunasan,
            'user_id' => auth()->id()
        ]);

        return $this->controller->sendNotification([    
            'user_id'          => (int) auth()->id(),
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama',
            'status_transaksi' => 'lunas', 
            'nominal_masuk'    => (int) $totalPelunasan, 
            'title'            => 'Pelunasan Berhasil',
            'message'          => "PELUNASAN BERHASIL: {$detailGadai->no_gadai}. Total uang masuk Rp " . number_format($totalPelunasan, 0, ',', '.') . ". Silakan serahkan unit ke nasabah.",
            'type'             => 'PAYMENT_SUCCESS' // ✅ Type untuk routing ke after-repayment
        ]);
    }

    /**
     * ✅ Alias untuk notifyPelunasan
     */
    public function notifyPelunasanSukses($detailGadai)
    {
        return $this->notifyPelunasan($detailGadai);
    }

    /**
     * ✅ Notifikasi Repeat Order
     */
public function notifyRepeatOrder($detailGadai, $totalGadai)
{
    $detailGadai->loadMissing('nasabah');
    $senderId = auth()->id();

    // Log lokal untuk memastikan fungsi ini terpanggil
    \Log::info('Triggering notifyRepeatOrder for: ' . $detailGadai->no_gadai);

    return $this->controller->sendNotification([
        'user_id'      => (int) $senderId, 
        'no_gadai'     => (string) $detailGadai->no_gadai,
        'nama_nasabah' => (string) ($detailGadai->nasabah->nama_lengkap),
        'title'        => 'Repeat Order',
        'message'      => "Nasabah {$detailGadai->nasabah->nama_lengkap} melakukan gadai ulang yang ke-{$totalGadai}",
        'type'         => 'REPEAT_ORDER',
        'url'          => "/gadai/detail/{$detailGadai->no_gadai}", 
        'total_gadai'  => (int) $totalGadai,
        'is_repeat'    => true 
    ]);
}
}