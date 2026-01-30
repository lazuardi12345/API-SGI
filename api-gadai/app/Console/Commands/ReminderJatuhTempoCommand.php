<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DetailGadai;
use App\Services\NotificationService;
use Carbon\Carbon;

class ReminderJatuhTempoCommand extends Command
{
    // Nama command tetap sama agar tidak perlu ubah Kernel
    protected $signature = 'app:reminder-jatuh-tempo';
    protected $description = 'Kirim notifikasi pengingat jatuh tempo H-3, Hari H, dan H+3';

    public function handle()
    {
        $notif = app(NotificationService::class);
        $today = Carbon::today();


        $targetDates = [
            $today->copy()->addDays(3)->format('Y-m-d'),  
            $today->copy()->format('Y-m-d'),             
            $today->copy()->subDays(3)->format('Y-m-d'),  
        ];

        $items = DetailGadai::with(['nasabah'])
            ->whereIn('jatuh_tempo', $targetDates)
            ->where('status', 'selesai') 
            ->get();

        if ($items->isEmpty()) {
            $this->info('Tidak ada jadwal pengingat jatuh tempo untuk hari ini.');
            return;
        }

        foreach ($items as $item) {
            $notif->notifyDueDateReminder($item);
            
            $this->info("Notifikasi dikirim untuk: {$item->no_gadai} (Jatuh Tempo: {$item->jatuh_tempo})");
        }

        $this->info('Semua pengingat telah diproses.');
    }
}