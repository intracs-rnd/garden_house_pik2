<?php

namespace App\Console\Commands;

use App\Models\ReplicationStatus;
use App\Services\CardReplicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorCardReplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cards:monitor-replication 
                            {--retry-failed : Retry failed syncs}
                            {--check-lag : Check replication lag}
                            {--log : Log output to file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor PostgreSQL Logical Replication status untuk cards & kartus tables';

    /**
     * Execute the console command.
     */
    public function handle(CardReplicationService $service): int
    {
        $service = app(CardReplicationService::class);

        $this->info('🔍 Monitoring Card Replication Status...');
        $this->newLine();

        // 1. Update status dari database
        ReplicationStatus::updateFromDatabase();
        $status = $service->getReplicationStatus();

        // 2. Display status
        $this->displayStatus($status);

        // 3. Check lag jika flag set
        if ($this->option('check-lag')) {
            $this->checkLag($service);
        }

        // 4. Retry failed syncs jika flag set
        if ($this->option('retry-failed')) {
            $this->retryFailedSyncs($service);
        }

        // 5. Summary
        $this->displaySummary($service);

        if ($this->option('log')) {
            Log::info('Card replication monitoring completed', $status);
        }

        return Command::SUCCESS;
    }

    /**
     * Display replication status
     */
    private function displayStatus(array $status): void
    {
        $this->line('📊 <info>REPLICATION STATUS</info>');
        $this->line('─────────────────────────────────────');

        $statusMap = [
            'healthy'      => ['icon' => '✓', 'label' => 'Sehat',   'color' => 'green'],
            'lagging'      => ['icon' => '⚠', 'label' => 'Lag',     'color' => 'yellow'],
            'disconnected' => ['icon' => '✗', 'label' => 'Terputus', 'color' => 'red'],
            'error'        => ['icon' => '✗', 'label' => 'Error',    'color' => 'red'],
        ];
        $s = $statusMap[$status['status']] ?? ['icon' => '?', 'label' => $status['status'], 'color' => 'white'];

        $this->line("Status: <fg={$s['color']}>{$s['icon']} {$s['label']}</>");

        // "Kondisi" kini berbasis status, bukan hanya is_connected boolean
        // Subscriber idle (lag kecil) = normal, bukan terputus
        $kondisi = match ($status['status']) {
            'healthy'      => '<fg=green>Sinkron</> (subscriber aktif atau idle - normal)',
            'lagging'      => '<fg=yellow>Lagging</> - subscriber tertinggal',
            'disconnected' => '<fg=red>Terputus</> - subscription belum dibuat',
            default        => '<fg=red>Error</>',
        };
        $this->line("Kondisi: $kondisi");

        $lagBytes = $status['lag_bytes'];
        $lagDisplay = $lagBytes > 1024 * 1024
            ? round($lagBytes / 1024 / 1024, 2) . ' MB'
            : ($lagBytes > 1024 ? round($lagBytes / 1024, 1) . ' KB' : "$lagBytes bytes");

        $lagColor = match (true) {
            $lagBytes < 1 * 1024 * 1024  => 'green',
            $lagBytes < 50 * 1024 * 1024 => 'yellow',
            default                       => 'red',
        };
        $this->line("Lag: <fg=$lagColor>$lagDisplay</>");

        $this->line("Total Replicated: <info>{$status['total_replicated']}</info>");
        $this->line("Failed: <info>{$status['failed_count']}</info>");
        $this->line("Last Replicated: <info>" . ($status['last_replicated_at'] ?? 'N/A') . "</info>");

        if ($status['error_message']) {
            $this->line("<fg=yellow>⚠  {$status['error_message']}</>");
        }

        $this->newLine();
    }

    /**
     * Check replication lag
     */
    private function checkLag(CardReplicationService $service): void
    {
        $this->line('⏱️  <info>REPLICATION LAG CHECK</info>');
        $this->line('─────────────────────────────────────');

        $lagSeconds = $service->checkReplicationLag();

        $statusColor = match (true) {
            $lagSeconds < 0 => 'red',
            $lagSeconds === 0 => 'green',
            $lagSeconds < 5 => 'green',
            $lagSeconds < 30 => 'yellow',
            default => 'red',
        };

        $statusLabel = match (true) {
            $lagSeconds < 0 => 'Error',
            $lagSeconds === 0 => 'In Sync',
            $lagSeconds < 5 => 'Healthy',
            $lagSeconds < 30 => 'Lagging',
            default => 'Critical',
        };

        $this->line("Lag: <fg=$statusColor>$lagSeconds seconds ($statusLabel)</>");
        $this->newLine();
    }

    /**
     * Retry failed syncs
     */
    private function retryFailedSyncs(CardReplicationService $service): void
    {
        $this->line('🔄 <info>RETRYING FAILED SYNCS</info>');
        $this->line('─────────────────────────────────────');

        $retried = $service->retryFailedSyncs(10);

        if ($retried === 0) {
            $this->line('<info>No failed syncs to retry</info>');
        } else {
            $this->line("<info>Retried $retried failed sync(s)</info>");
        }

        $this->newLine();
    }

    /**
     * Display summary
     */
    private function displaySummary(CardReplicationService $service): void
    {
        $this->line('📈 <info>CHANGES SUMMARY (Last 60 minutes)</info>');
        $this->line('─────────────────────────────────────');

        $summary = $service->getChangesSummary(null, 60);

        $this->line("Total Changes: <info>{$summary['total']}</info>");

        if ($summary['by_operation']) {
            $this->line('By Operation:');
            foreach ($summary['by_operation'] as $op => $count) {
                $this->line("  • $op: <info>$count</info>");
            }
        }

        if ($summary['sync_status']) {
            $this->line('Sync Status:');
            foreach ($summary['sync_status'] as $status => $count) {
                $color = $status === 'synced' ? 'green' : 'red';
                $this->line("  • <fg=$color>$status: $count</>");
            }
        }

        $this->newLine();
        $this->info('✓ Monitoring complete');
    }
}
