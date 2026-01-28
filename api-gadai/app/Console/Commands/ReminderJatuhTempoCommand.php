<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DetailGadai;
use App\Services\NotificationService;
use Carbon\Carbon;

class ReminderJatuhTempoCommand extends Command
{
    protected $signature = 'app:reminder-jatuh-tempo';
    protected $description = 'Kirim notifikasi pengingat jatuh tempo H-3, Hari H, dan H+3';

    public function handle()
    {
        $notif = app(NotificationService::class);
        $today = Carbon::today();

        // Target tanggal yang kita cari
        $targets = [
            'H-3' => $today->copy()->addDays(3), 
            'H-0' => $today->copy(),             
            'H+3' => $today->copy()->subDays(3), 
        ];

        foreach ($targets as $label => $date) {
            $items = DetailGadai::with(['nasabah'])
                ->whereDate('jatuh_tempo', $date->format('Y-m-d'))
                ->where('status', 'selesai') 
                ->get();

            foreach ($items as $item) {
                $this->sendReminder($notif, $item, $label);
            }
        }

        $this->info('Semua pengingat telah diproses.');
    }

    private function sendReminder($notif, $item, $label)
    {
        $nama = $item->nasabah->nama_lengkap ?? 'Nasabah';
        
        $payload = [
            'type' => 'REMINDER_JATUH_TEMPO',
            'no_gadai' => $item->no_gadai,
            'nama_nasabah' => $nama,
            'status_transaksi' => $item->status,
        ];

        if ($label === 'H-3') {
            $payload['title'] = "⏳ 3 Hari Lagi Jatuh Tempo";
            $payload['message'] = "Mengingatkan nasabah {$nama} ({$item->no_gadai}) akan jatuh tempo pada " . Carbon::parse($item->jatuh_tempo)->format('d-m-Y');
        } elseif ($label === 'H-0') {
            $payload['title'] = "🚨 HARI INI JATUH TEMPO";
            $payload['message'] = "Segera hubungi {$nama} ({$item->no_gadai}) untuk pelunasan atau perpanjangan hari ini.";
        } else { // H+3
            $payload['title'] = "⚠️ LEWAT JATUH TEMPO (H+3)";
            $payload['message'] = "Gadai {$item->no_gadai} ({$nama}) sudah lewat 3 hari. Segera tindak lanjuti sebelum masuk daftar lelang.";
        }

        $notif->controller->sendNotification($payload);
        $this->info("Kirim {$label} untuk {$item->no_gadai}");
    }
}