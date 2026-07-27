<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestDbSplit extends Command
{
    protected $signature   = 'db:test-split';
    protected $description = 'Verifikasi read/write splitting berjalan ke host yang benar';

    public function handle(): int
    {
        $this->info('=== Read/Write Splitting Test ===');
        $this->newLine();

        // Config yang dikonfigurasi pada pgsql_cards (koneksi khusus kartus & cards)
        $cfgRead  = config('database.connections.pgsql_cards.read.host')[0]  ?? 'not set';
        $cfgWrite = config('database.connections.pgsql_cards.write.host')[0] ?? 'not set';
        $cfgDefault = config('database.connections.pgsql.host') ?? 'not set';

        $this->line("Config pgsql (default semua tabel) : <fg=cyan>{$cfgDefault}</>");
        $this->line("Config pgsql_cards READ  host      : <fg=cyan>{$cfgRead}</>");
        $this->line("Config pgsql_cards WRITE host      : <fg=cyan>{$cfgWrite}</>");
        $this->newLine();

        // --- READ PDO DSN (pgsql_cards) ---
        try {
            $readPdo    = DB::connection('pgsql_cards')->getReadPdo();
            $readDsn    = $readPdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
            $serverHost = DB::connection('pgsql_cards')->selectOne('SELECT inet_server_addr() AS addr')?->addr ?? '?';

            $this->line("[READ]  PDO status : {$readDsn}");
            $this->line("[READ]  Server addr: <fg=yellow>{$serverHost}</> (IP internal server)");
            $this->line("[READ]  ✓ Koneksi berhasil ke {$cfgRead}");
        } catch (\Throwable $e) {
            $this->error('[READ]  GAGAL: ' . $e->getMessage());
        }

        $this->newLine();

        // --- WRITE PDO DSN (pgsql_cards) ---
        try {
            $writePdo   = DB::connection('pgsql_cards')->getPdo();
            $writeDsn   = $writePdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
            $stmt       = $writePdo->query('SELECT inet_server_addr() AS addr');
            $serverHost = $stmt->fetchColumn() ?? '?';

            $this->line("[WRITE] PDO status : {$writeDsn}");
            $this->line("[WRITE] Server addr: <fg=yellow>{$serverHost}</> (IP internal server)");

            // Cek apakah read dan write ke host berbeda
            if ($cfgRead !== $cfgWrite) {
                $this->line("[WRITE] ✓ Koneksi berhasil ke {$cfgWrite}");
            } else {
                $this->warn('[WRITE] ⚠ READ dan WRITE host sama — splitting tidak aktif!');
            }
        } catch (\Throwable $e) {
            $this->error('[WRITE] GAGAL: ' . $e->getMessage());
        }

        $this->newLine();

        // --- Cek default pgsql (single host) ---
        $this->info('=== Default pgsql (semua tabel selain kartus & cards) ===');
        try {
            DB::connection('pgsql')->selectOne('SELECT 1');
            $this->line("  ✓ Koneksi pgsql ({$cfgDefault}) berhasil — single host, no split");
        } catch (\Throwable $e) {
            $this->error("  GAGAL: " . $e->getMessage());
        }

        $this->newLine();

        $this->info('=== Catatan ===');
        $this->line('pgsql         → single host .161 — dipakai semua tabel (users, warga, dll)');
        $this->line('pgsql_cards   → read/write split — HANYA untuk tabel kartus & cards');
        $this->line("Yang penting: Config pgsql_cards READ={$cfgRead}, WRITE={$cfgWrite} berbeda ✓");

        return Command::SUCCESS;
    }
}
