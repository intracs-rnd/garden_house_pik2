<?php

namespace App\Console\Commands;

use App\Services\KartuService;
use Illuminate\Console\Command;

class BlacklistOverdueKartu extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kartu:blacklist-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Blacklist kartu akses yang memiliki tunggakan melebihi masa tenggang + 1 hari.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(KartuService $kartuService): int
    {
        $count = $kartuService->blacklistOverdue();

        if ($count > 0) {
            $this->info("{$count} kartu di-blacklist secara otomatis karena tunggakan pembayaran melewati masa tenggang +1 hari.");
        } else {
            $this->info('Tidak ada kartu yang perlu di-blacklist.');
        }

        return self::SUCCESS;
    }
}
