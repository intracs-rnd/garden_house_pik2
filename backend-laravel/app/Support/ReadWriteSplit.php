<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Read/Write Split trait for Eloquent models.
 *
 * ┌──────────────────────────────────────────────────────────┐
 * │          READ/WRITE SPLIT ARCHITECTURE                   │
 * │                                                          │
 * │  READ  (SELECT)  → 192.168.214.161  (PC Admin – replica) │
 * │  WRITE (DML)     → 192.168.214.163  (Virtual IP – master)│
 * │                                                          │
 * │  Logical Replication: 163 → 161 (otomatis, real-time)    │
 * └──────────────────────────────────────────────────────────┘
 *
 * Laravel's `pgsql` connection handles routing automatically:
 *   - SELECT queries  → read  host (192.168.214.161)
 *   - INSERT/UPDATE/DELETE → write host (192.168.214.163)
 *   - sticky = true   → setelah write, reads juga ke write host dalam request yang sama
 *
 * Helper ini membuat intent READ/WRITE eksplisit di kode.
 */
trait ReadWriteSplit
{
    /**
     * Query builder yang membaca dari READ host (replica – 192.168.214.161).
     *
     * Gunakan untuk: listing, show, status check, gate decision.
     * Ini adalah default Eloquent; helper ini hanya mendokumentasikan intent.
     */
    public static function readQuery(): Builder
    {
        return static::query();
    }

    /**
     * Query builder yang dipaksa membaca dari WRITE host (master – 192.168.214.163).
     *
     * Gunakan ketika butuh data segar tepat setelah write untuk menghindari
     * replication lag — misalnya refresh setelah create/update kartu.
     *
     * Sticky connection (DB_STICKY=true) biasanya sudah menangani ini di dalam
     * satu request; helper ini menjamin perilaku yang sama bahkan di luar request
     * (scheduled command, event queue, dsb.).
     */
    public static function writeQuery(): Builder
    {
        $builder = (new static)->newQueryWithoutScopes();

        // Force underlying QueryBuilder ke write PDO sehingga SELECT pun
        // dikirim ke write host (192.168.214.163).
        $builder->getQuery()->useWritePdo();

        return $builder;
    }
}
