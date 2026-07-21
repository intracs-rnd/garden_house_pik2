<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Non-aktifkan kartu yang masa berlakunya sudah habis setiap jam,
        // sehingga kartu otomatis nonaktif pada jam kedaluwarsanya.
        $schedule->command('kartu:deactivate-expired')->hourly();

        // Blacklist kartu dengan tunggakan yang melewati masa tenggang + 1 hari
        // dijalankan setiap hari pada jam 00:00
        $schedule->command('kartu:blacklist-overdue')->dailyAt('00:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
