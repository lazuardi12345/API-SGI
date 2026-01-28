<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AutoLelangCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:auto-lelang-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

public function handle()
{
    $notif = app(\App\Services\NotificationService::class);
    $today = \Carbon\Carbon::today()->startOfDay();
    $batasMinimalLelang = $today->copy()->subDays(15);
    $dataJatuhTempo = \App\Models\DetailGadai::whereDate('jatuh_tempo', '<=', $batasMinimalLelang)
        ->whereNotIn('status', ['lunas', 'terlelang', 'selesai']) 
        ->whereDoesntHave('pelelangan')
        ->get();

    if ($dataJatuhTempo->isEmpty()) {
        $this->info('Tidak ada barang baru yang memenuhi syarat lelang hari ini.');
        return;
    }

    foreach ($dataJatuhTempo as $item) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($item, $notif) {
            $pelelangan = \App\Models\Pelelangan::create([
                'detail_gadai_id' => $item->id,
                'status_lelang'   => 'siap',
                'keterangan'      => 'Otomatis masuk daftar lelang (Toleransi 15 hari jatuh tempo)'
            ]);
            $notif->notifyBarangLelang($pelelangan);
        });
        
        $this->info("Berhasil didaftarkan lelang & notif terkirim: {$item->no_gadai}");
    }
    
    $this->info('Proses Auto-Lelang & Notifikasi selesai.');
}
}
