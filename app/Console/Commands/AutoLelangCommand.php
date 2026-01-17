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

    /**
     * Execute the console command.
     */
    public function handle()
{
    // 1. Cari data gadai yang melewati jatuh tempo DAN belum masuk pelelangan
    $dataJatuhTempo = DetailGadai::where('tanggal_jatuh_tempo', '<', now())
        ->whereDoesntHave('pelelangan')
        ->get();

    foreach ($dataJatuhTempo as $item) {
        // 2. Masukkan ke tabel pelelangan
        Pelelangan::create([
            'detail_gadai_id' => $item->id,
            'status_lelang'   => 'proses',
            'tanggal_dilelang' => now(),
            'keterangan'      => 'Otomatis masuk lelang karena jatuh tempo'
        ]);
    }
    
    $this->info('Data lelang berhasil diperbarui.');
}
}
