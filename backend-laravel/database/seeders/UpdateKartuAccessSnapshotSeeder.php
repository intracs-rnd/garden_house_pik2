<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Kartu;
use Illuminate\Support\Carbon;

class UpdateKartuAccessSnapshotSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $query = Kartu::withDeleted();
        $total = (int) $query->count();

        $bar = null;
        if ($this->command && $total > 0) {
            $bar = $this->command->getOutput()->createProgressBar($total);
            $bar->start();
        }

        foreach ($query->cursor() as $kartu) {
            try {
                $decision = $kartu->evaluateAccess();

                $kartu->access_allowed = (bool) ($decision['allowed'] ?? false);
                $kartu->access_reason  = $decision['reason'] ?? null;
                $kartu->access_message = $decision['message'] ?? null;
                $kartu->access_at      = Carbon::now();

                // Persist without firing model events to avoid side-effects like syncing Card
                $kartu->saveQuietly();
            } catch (\Throwable $e) {
                // Log and continue
                if (app()->bound('log')) {
                    app('log')->error('Failed updating access snapshot for Kartu id ' . ($kartu->id ?? 'n/a') . ': ' . $e->getMessage());
                }
            }

            if ($bar) {
                $bar->advance();
            }
        }

        if ($bar) {
            $bar->finish();
            $this->command->getOutput()->writeln('');
        }
    }
}
