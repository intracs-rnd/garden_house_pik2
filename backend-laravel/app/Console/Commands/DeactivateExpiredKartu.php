<?php

namespace App\Console\Commands;

use App\Services\KartuService;
use Illuminate\Console\Command;

class DeactivateExpiredKartu extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kartu:deactivate-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Non-aktifkan kartu akses yang masa berlakunya (termasuk masa tenggang) sudah habis.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(KartuService $kartuService): int
    {
        $count = $kartuService->deactivateExpired();

        if ($count > 0) {
            $this->info("{$count} kartu dinonaktifkan secara otomatis karena masa berlakunya telah habis.");
        } else {
            $this->info('Tidak ada kartu yang perlu dinonaktifkan.');
        }

        return self::SUCCESS;
    }
}
