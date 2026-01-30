<?php

namespace App\Services;

use App\Http\Controllers\NotificationServiceController;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $controller;

    public function __construct()
    {
        $this->controller = app(NotificationServiceController::class);
    }

    public function notifyNewTransaction($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $senderId = auth()->id();
        $namaNasabah = $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama';

        Log::info('🔔 [NEW_PAWN] Preparing notification', [
            'no_gadai' => $detailGadai->no_gadai,
            'petugas_id' => $senderId,
            'nasabah' => $namaNasabah
        ]);

        $result = $this->controller->sendNotification([
            'user_id'          => (int) $senderId, 
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $namaNasabah,
            'title'            => 'Gadai Baru Dimulai',
            'message'          => "Transaksi {$detailGadai->no_gadai} atas nama {$namaNasabah} berhasil dibuat.",
            'status_transaksi' => 'proses',
            'type'             => 'NEW_PAWN', 
            'url'              => "/gadai/detail/{$detailGadai->no_gadai}"
        ]);

        Log::info('📤 [NEW_PAWN] Notification result', ['result' => $result]);
        return $result;
    }

    public function notifyUnitSelesai($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $userId = auth()->id();
        $namaNasabah = $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama';
        
        Log::info('🔔 [UNIT_VALIDATED] Preparing notification', [
            'no_gadai' => $detailGadai->no_gadai,
            'user_id' => $userId,
            'nasabah' => $namaNasabah,
            'nominal' => $detailGadai->uang_pinjaman
        ]);

        $result = $this->controller->sendNotification([
            'user_id'          => (int) $userId,
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $namaNasabah,
            'status_transaksi' => 'selesai', 
            'nominal_cair'     => (int) $detailGadai->uang_pinjaman, 
            'title'            => 'Unit Selesai Divalidasi',
            'message'          => "Unit {$detailGadai->no_gadai} DITERIMA. Silakan cairkan dana Rp " . number_format($detailGadai->uang_pinjaman, 0, ',', '.'),
            'type'             => 'UNIT_VALIDATED',
            'url'              => "/gadai/detail/{$detailGadai->no_gadai}"
        ]);

        if (!$result['success']) {
            Log::error('❌ [UNIT_VALIDATED] Failed to send notification', [
                'error' => $result['message'] ?? $result['error'] ?? 'Unknown error'
            ]);
        } else {
            Log::info('✅ [UNIT_VALIDATED] Notification sent successfully');
        }

        return $result;
    }

    public function notifyPelunasan($detailGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $totalPelunasan = $detailGadai->nominal_bayar ?? ($detailGadai->uang_pinjaman + ($detailGadai->biaya_sewa ?? 0));

        Log::info('🔔 [PAYMENT_SUCCESS] Preparing notification', [
            'no_gadai' => $detailGadai->no_gadai,
            'nominal' => $totalPelunasan,
            'user_id' => auth()->id()
        ]);

        $result = $this->controller->sendNotification([    
            'user_id'          => (int) auth()->id(),
            'no_gadai'         => (string) $detailGadai->no_gadai,
            'nama_nasabah'     => $detailGadai->nasabah->nama_lengkap ?? 'Tanpa Nama',
            'status_transaksi' => 'lunas', 
            'nominal_masuk'    => (int) $totalPelunasan, 
            'title'            => 'Pelunasan Berhasil',
            'message'          => "PELUNASAN BERHASIL: {$detailGadai->no_gadai}. Total uang masuk Rp " . number_format($totalPelunasan, 0, ',', '.') . ". Silakan serahkan unit ke nasabah.",
            'type'             => 'PAYMENT_SUCCESS',
            'url'              => "/gadai/detail/{$detailGadai->no_gadai}"
        ]);

        Log::info('📤 [PAYMENT_SUCCESS] Notification result', ['result' => $result]);
        return $result;
    }

    public function notifyPelunasanSukses($detailGadai)
    {
        return $this->notifyPelunasan($detailGadai);
    }

    public function notifyRepeatOrder($detailGadai, $totalGadai)
    {
        $detailGadai->loadMissing('nasabah');
        $senderId = auth()->id();

        Log::info('🔔 [REPEAT_ORDER] Preparing notification', [
            'no_gadai' => $detailGadai->no_gadai,
            'total_gadai' => $totalGadai
        ]);

        $result = $this->controller->sendNotification([
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

        Log::info('📤 [REPEAT_ORDER] Notification result', ['result' => $result]);
        return $result;
    }

public function notifyBarangLelang($pelelangan)
{
    // 1. Load data dengan aman
    $pelelangan->loadMissing(['detailGadai.nasabah']);
    $gadai = $pelelangan->detailGadai;
    
    if (!$gadai) {
        Log::error('❌ [ITEM_AUCTIONED] Gadai data not found for Pelelangan ID: ' . $pelelangan->id);
        return ['success' => false, 'message' => 'Data gadai tidak ditemukan'];
    }

    $namaNasabah = $gadai->nasabah->nama_lengkap ?? 'Tanpa Nama';
    
    // 2. Proteksi hitungKalkulasi agar tidak bikin crash
    try {
        $pelelanganController = app(\App\Http\Controllers\PelelanganController::class);
        $kalkulasi = $pelelanganController->hitungKalkulasi($gadai);
        $totalHutang = $kalkulasi['total_hutang'] ?? 0;
        $totalHutangFormatted = "Rp " . number_format($totalHutang, 0, ',', '.');
    } catch (\Exception $e) {
        Log::warning('⚠️ [ITEM_AUCTIONED] Gagal hitung kalkulasi: ' . $e->getMessage());
        $totalHutang = 0;
        $totalHutangFormatted = "Cek detail";
    }

    Log::info('🔔 [ITEM_AUCTIONED] Preparing notification', [
        'no_gadai' => $gadai->no_gadai,
        'total_hutang' => $totalHutang
    ]);

    // 3. Kirim ke Controller Service dengan format yang pas
    return $this->controller->sendNotification([
        'user_id'          => (int) auth()->id() ?? 0, 
        'no_gadai'         => (string) $gadai->no_gadai,
        'nama_nasabah'     => $namaNasabah,
        'title'            => '⚠️ Barang Masuk Daftar Lelang',
        'message'          => "Unit {$gadai->no_gadai} ({$namaNasabah}) telah masuk daftar lelang. Total Hutang: {$totalHutangFormatted}",
        'status_transaksi' => 'lelang',
        'type'             => 'ITEM_AUCTIONED', 
        'url'              => "/lelang/detail/{$gadai->id}",
        'total_gadai'      => (int) $totalHutang // Biar NestJS dapet angka murninya juga
    ]);
}

    public function notifyDueDateReminder($payload)
    {
        Log::info('🔔 [DUE_DATE_REMINDER] Preparing notification', [
            'no_gadai' => $payload['no_gadai'],
            'label' => $payload['title']
        ]);

        $result = $this->controller->sendNotification([
            'user_id'          => 0, 
            'no_gadai'         => (string) $payload['no_gadai'],
            'nama_nasabah'     => (string) $payload['nama_nasabah'],
            'title'            => (string) $payload['title'],
            'message'          => (string) $payload['message'],
            'status_transaksi' => 'reminder',
            'type'             => 'DUE_DATE_REMINDER', 
            'url'              => (string) $payload['url']
        ]);

        Log::info('📤 [DUE_DATE_REMINDER] Notification result', ['result' => $result]);
        return $result;
    }
}