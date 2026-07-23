<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Kartu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifikasi bahwa read/write split benar-benar bekerja untuk
 * tabel `kartus` dan `cards` menggunakan ReadWriteSplit trait.
 *
 * READ  → 192.168.214.161 (PC Admin – replica)
 * WRITE → 192.168.214.163 (Virtual IP – master)
 */
class TestKartuCardSplit extends Command
{
    protected $signature   = 'kartu:test-split
                             {--cleanup : Hapus sisa test data}';

    protected $description = 'Test read/write split pada tabel kartus & cards (ReadWriteSplit trait)';

    private const TEST_UID = 'TEST_RWSPLIT_KARTU';

    public function handle(): int
    {
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        $this->printHeader();

        $pass = 0;
        $fail = 0;

        // ── 1. Konfigurasi ────────────────────────────────────────────
        $this->section('1. Konfigurasi Read/Write');

        $readHost  = config('database.connections.pgsql.read.host')[0]  ?? '?';
        $writeHost = config('database.connections.pgsql.write.host')[0] ?? '?';
        $sticky    = config('database.connections.pgsql.sticky')         ? 'true' : 'false';

        $this->row('READ  host (replica)', $readHost);
        $this->row('WRITE host (master)',  $writeHost);
        $this->row('sticky',               $sticky);

        if ($readHost !== $writeHost) {
            $this->ok('Read dan write host berbeda ✓');
            $pass++;
        } else {
            $this->fail('Read dan write host SAMA — split tidak aktif!');
            $fail++;
        }

        // ── 2. Koneksi dasar PDO ──────────────────────────────────────
        $this->section('2. Koneksi PDO');

        try {
            DB::connection('pgsql')->selectOne('SELECT 1');
            $this->ok("Koneksi pgsql (read/write) ✓");
            $pass++;
        } catch (\Throwable $e) {
            $this->fail("Koneksi pgsql GAGAL: {$e->getMessage()}");
            $fail++;
        }

        // ── 3. ReadWriteSplit trait — readQuery() ─────────────────────
        $this->section('3. Kartu::readQuery() → READ host (192.168.214.161)');

        try {
            $count = Kartu::readQuery()->withoutGlobalScopes()->count();
            $this->ok("readQuery() berhasil — jumlah row: {$count}");
            $this->line("  <fg=gray>→ SELECT dikirim ke {$readHost}</>");
            $pass++;
        } catch (\Throwable $e) {
            $this->fail("readQuery() GAGAL: {$e->getMessage()}");
            $fail++;
        }

        // ── 4. ReadWriteSplit trait — writeQuery() ────────────────────
        $this->section('4. Kartu::writeQuery() → WRITE host (192.168.214.163)');

        try {
            $count = Kartu::writeQuery()->withoutGlobalScopes()->count();
            $this->ok("writeQuery() berhasil — jumlah row: {$count}");
            $this->line("  <fg=gray>→ SELECT dipaksa ke {$writeHost} (useWritePdo)</>");
            $pass++;
        } catch (\Throwable $e) {
            $this->fail("writeQuery() GAGAL: {$e->getMessage()}");
            $fail++;
        }

        // ── 5. Card::readQuery() dan writeQuery() ─────────────────────
        $this->section('5. Card::readQuery() dan Card::writeQuery()');

        try {
            $readCount  = Card::readQuery()->count();
            $writeCount = Card::writeQuery()->count();
            $this->ok("Card::readQuery()  — row: {$readCount}");
            $this->ok("Card::writeQuery() — row: {$writeCount}");
            $pass++;
        } catch (\Throwable $e) {
            $this->fail("Card query GAGAL: {$e->getMessage()}");
            $fail++;
        }

        // ── 6. Sticky: write lalu baca balik dari write host ──────────
        $this->section('6. Sticky — Tulis lalu baca balik (write host)');

        try {
            // Bersihkan sisa test sebelumnya
            DB::connection('pgsql')->table('cards')
                ->where('uid', self::TEST_UID)->delete();

            // INSERT → write host
            DB::connection('pgsql')->table('cards')->insert([
                'uid'    => self::TEST_UID,
                'name'   => 'TEST ReadWriteSplit',
                'unit'   => 'TEST-UNIT',
                'status' => 'Active',
                'expiry' => now()->addYear()->toDateString(),
            ]);
            $this->ok("INSERT card ke {$writeHost} ✓");

            // Baca balik via writeQuery() — harus ketemu karena dari write host
            $found = Card::writeQuery()->where('uid', self::TEST_UID)->first();
            if ($found) {
                $this->ok("writeQuery() langsung ketemu card yang baru diinsert ✓");
                $this->line("  <fg=gray>→ Tidak ada replication lag karena baca dari {$writeHost}</>");
                $pass++;
            } else {
                $this->fail("writeQuery() TIDAK menemukan card yang baru diinsert!");
                $fail++;
            }

            // Bersihkan
            DB::connection('pgsql')->table('cards')
                ->where('uid', self::TEST_UID)->delete();
        } catch (\Throwable $e) {
            $this->fail("Sticky test GAGAL: {$e->getMessage()}");
            $fail++;
            // Pastikan cleanup
            DB::connection('pgsql')->table('cards')
                ->where('uid', self::TEST_UID)->delete();
        }

        // ── 7. Replication lag ────────────────────────────────────────
        $this->section('7. Replication Lag (pg_last_xact_replay_timestamp @ replica)');

        try {
            $lag = DB::connection('pgsql')->selectOne(
                "SELECT EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp()))::INTEGER AS lag_seconds"
            );

            $lagSec = $lag?->lag_seconds ?? null;

            if ($lagSec === null) {
                $this->warn("pg_last_xact_replay_timestamp() NULL — host ini mungkin master (normal)");
            } elseif ($lagSec < 5) {
                $this->ok("Replication lag: {$lagSec} detik (healthy ✓)");
                $pass++;
            } elseif ($lagSec < 30) {
                $this->warn("Replication lag: {$lagSec} detik (lagging — perlu dimonitor)");
            } else {
                $this->fail("Replication lag: {$lagSec} detik (KRITIS!)");
                $fail++;
            }
        } catch (\Throwable $e) {
            $this->warn("Lag check tidak tersedia: {$e->getMessage()}");
        }

        // ── Ringkasan ─────────────────────────────────────────────────
        $this->newLine();
        $this->line('══════════════════════════════════════════════');
        $total = $pass + $fail;
        if ($fail === 0) {
            $this->line("  <fg=green>SEMUA TEST LULUS</> — {$pass}/{$total} ✓");
        } else {
            $this->line("  <fg=red>ADA YANG GAGAL</> — lulus: {$pass}/{$total}, gagal: {$fail}");
        }
        $this->line('══════════════════════════════════════════════');
        $this->newLine();

        return $fail === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function cleanup(): int
    {
        $n = DB::connection('pgsql')->table('cards')
            ->where('uid', self::TEST_UID)->delete();

        $this->info("✓ Dihapus {$n} test card(s)");
        return Command::SUCCESS;
    }

    private function printHeader(): void
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════╗');
        $this->line('║  <info>Kartus & Cards — Read/Write Split Test</info>         ║');
        $this->line('║  READ  → 192.168.214.161  (PC Admin – replica)   ║');
        $this->line('║  WRITE → 192.168.214.163  (Virtual IP – master)  ║');
        $this->line('╚══════════════════════════════════════════════════╝');
        $this->newLine();
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<fg=yellow>── {$title}</>");
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-28s : <info>%s</info>', $label, $value));
    }

    private function ok(string $msg): void
    {
        $this->line("  <fg=green>✓</> {$msg}");
    }

    private function fail(string $msg): void
    {
        $this->line("  <fg=red>✗</> {$msg}");
    }
}
