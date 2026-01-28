<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
   protected function schedule(Schedule $schedule): void
{
    // Baris ini yang benar tempatnya di Laravel 10
    $schedule->command('app:auto-lelang-command')->daily();
     $schedule->command('app:reminder-jatuh-tempo')->dailyAt('08:00');
}



    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    
}
