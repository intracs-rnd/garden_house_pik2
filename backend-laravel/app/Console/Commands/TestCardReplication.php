<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestCardReplication extends Command
{
    protected $signature = 'cards:test-replication
                            {--cleanup : Hapus semua test data yang tersisa}
                            {--wait=5  : Detik tunggu sebelum cek replica (default 5)}';

    protected $description = 'Test apakah PostgreSQL Logical Replication berjalan: insert di master → cek di replica';

    // UID test agar mudah dibersihkan
    private const TEST_UID_PREFIX = 'TEST_REPL_';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $this->printHeader();

        // 1. Cek koneksi kedua sisi
        [$masterOk, $replicaOk] = $this->checkConnections();
        if (!$masterOk || !$replicaOk) {
            return Command::FAILURE;
        }

        // 2. Cek publication di master
        if (!$this->checkPublication()) {
            return Command::FAILURE;
        }

        // 3. Jalankan skenario test
        $this->runTestScenario();

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Koneksi
    // -------------------------------------------------------------------------

    private function checkConnections(): array
    {
        $this->line('🔌 <info>CHECKING CONNECTIONS</info>');
        $this->line('─────────────────────────────────────');

        $masterOk  = $this->ping('pgsql',         'Master (PC Admin 192.168.214.161)');
        $replicaOk = $this->ping('pgsql_replica',  'Replica (Server  192.168.214.163)');

        $this->newLine();
        return [$masterOk, $replicaOk];
    }

    private function ping(string $connection, string $label): bool
    {
        try {
            DB::connection($connection)->selectOne('SELECT 1');
            $this->line("  ✓ <fg=green>$label</>");
            return true;
        } catch (\Exception $e) {
            $this->line("  ✗ <fg=red>$label</> — {$e->getMessage()}");
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Cek Publication
    // -------------------------------------------------------------------------

    private function checkPublication(): bool
    {
        $pubName = config('replication.publication.name', 'cards_kartus_pub');

        try {
            $pub = DB::connection('pgsql')->selectOne(
                'SELECT pubname FROM pg_publication WHERE pubname = ?',
                [$pubName]
            );

            if (!$pub) {
                $this->error("Publication '{$pubName}' tidak ditemukan di master!");
                $this->line("Jalankan dulu: <comment>CREATE PUBLICATION {$pubName} FOR TABLE cards, kartus;</comment>");
                return false;
            }

            $this->line("✓ Publication <fg=green>{$pubName}</> aktif di master");
            $this->newLine();
            return true;
        } catch (\Exception $e) {
            $this->error("Gagal cek publication: {$e->getMessage()}");
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Skenario Test
    // -------------------------------------------------------------------------

    private function runTestScenario(): void
    {
        $waitSec = (int) $this->option('wait');
        $uid     = self::TEST_UID_PREFIX . now()->format('Ymd_His');

        $this->line('🧪 <info>TEST SCENARIO</info>');
        $this->line('─────────────────────────────────────');
        $this->line("Test ID  : <comment>{$uid}</comment>");
        $this->line("Waktu    : <comment>" . now()->format('d-m-Y H:i:s') . "</comment>");
        $this->newLine();

        // --- Step 1: Snapshot sebelum insert ---
        $beforeMaster  = $this->countCards('pgsql');
        $beforeReplica = $this->countCards('pgsql_replica');
        $this->line("Jumlah cards sebelum test:");
        $this->line("  Master  : <info>{$beforeMaster}</info>");
        $this->line("  Replica : <info>{$beforeReplica}</info>");
        $this->newLine();

        // --- Step 2: INSERT di master ---
        $this->line("📝 <fg=yellow>Step 1</> — INSERT card di <fg=cyan>Master (PC Admin)</>...");
        $insertedAt = now();
        DB::connection('pgsql')->table('cards')->insert([
            'uid'    => $uid,
            'name'   => 'TEST Replication Card',
            'unit'   => 'TEST-UNIT-999',
            'status' => 'Active',
            'expiry' => now()->addYear()->toDateString(),
        ]);
        $this->line("  ✓ INSERT berhasil pukul <info>{$insertedAt->format('H:i:s')}</info>");
        $this->newLine();

        // --- Step 3: Tunggu propagasi ---
        $this->line("⏳ Menunggu {$waitSec} detik untuk propagasi ke replica...");
        for ($i = $waitSec; $i > 0; $i--) {
            $this->output->write("\r  Sisa: <comment>{$i}s</comment>   ");
            sleep(1);
        }
        $this->output->writeln('');
        $this->newLine();

        // --- Step 4: Cek di replica ---
        $this->line("🔍 <fg=yellow>Step 2</> — Cek data di <fg=magenta>Replica (Server)</>...");
        $foundAt     = now();
        $cardOnReplica = DB::connection('pgsql_replica')
            ->table('cards')
            ->where('uid', $uid)
            ->first();

        if ($cardOnReplica) {
            $elapsed = $insertedAt->diffInMilliseconds($foundAt);
            $this->line("  ✓ <fg=green>SUKSES!</> Card ditemukan di replica");
            $this->line("  UID    : <info>{$cardOnReplica->uid}</info>");
            $this->line("  Name   : <info>{$cardOnReplica->name}</info>");
            $this->line("  Status : <info>{$cardOnReplica->status}</info>");
            $this->line("  Waktu  : <fg=green>~{$elapsed}ms</> (termasuk {$waitSec}s tunggu)");
        } else {
            $this->line("  ✗ <fg=red>GAGAL</> — Card belum muncul di replica setelah {$waitSec} detik");
            $this->line("  Coba lagi dengan wait lebih lama: <comment>--wait=15</comment>");
            $this->line("  Atau cek log PostgreSQL di server replica");
        }
        $this->newLine();

        // --- Step 5: UPDATE di master → cek di replica ---
        $this->line("📝 <fg=yellow>Step 3</> — UPDATE card di <fg=cyan>Master</> lalu cek di replica...");
        DB::connection('pgsql')->table('cards')
            ->where('uid', $uid)
            ->update(['status' => 'Inactive', 'name' => 'TEST Replication Card (UPDATED)']);
        $this->line("  ✓ UPDATE status → Inactive");
        sleep(min($waitSec, 3));

        $updatedOnReplica = DB::connection('pgsql_replica')
            ->table('cards')->where('uid', $uid)->first();

        if ($updatedOnReplica && $updatedOnReplica->status === 'Inactive') {
            $this->line("  ✓ <fg=green>SUKSES!</> UPDATE tersinkron di replica");
        } else {
            $replicaStatus = $updatedOnReplica->status ?? 'tidak ditemukan';
            $this->line("  ✗ <fg=red>Belum tersinkron</> (replica status: {$replicaStatus})");
        }
        $this->newLine();

        // --- Step 6: DELETE di master → cek di replica ---
        $this->line("📝 <fg=yellow>Step 4</> — DELETE card di <fg=cyan>Master</> lalu cek di replica...");
        DB::connection('pgsql')->table('cards')->where('uid', $uid)->delete();
        $this->line("  ✓ DELETE berhasil di master");
        sleep(min($waitSec, 3));

        $deletedOnReplica = DB::connection('pgsql_replica')
            ->table('cards')->where('uid', $uid)->first();

        if (!$deletedOnReplica) {
            $this->line("  ✓ <fg=green>SUKSES!</> DELETE tersinkron di replica (row hilang)");
        } else {
            $this->line("  ✗ <fg=red>Belum tersinkron</> — row masih ada di replica");
        }
        $this->newLine();

        // --- Step 7: Cek monitor ---
        $this->line("📊 <fg=yellow>Step 5</> — Status replication setelah test:");
        $this->call('cards:monitor-replication');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function countCards(string $connection): int
    {
        try {
            return DB::connection($connection)->table('cards')->count();
        } catch (\Exception $e) {
            return -1;
        }
    }

    private function cleanup(): int
    {
        $deleted = DB::connection('pgsql')
            ->table('cards')
            ->where('uid', 'LIKE', self::TEST_UID_PREFIX . '%')
            ->delete();

        $this->info("✓ Dihapus {$deleted} test card(s) dari master (replica akan sync otomatis)");
        return Command::SUCCESS;
    }

    private function printHeader(): void
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════╗');
        $this->line('║   <info>PostgreSQL Replication Test</info>            ║');
        $this->line('║   Master (PC Admin) → Replica (Server)   ║');
        $this->line('╚══════════════════════════════════════════╝');
        $this->newLine();
    }
}
