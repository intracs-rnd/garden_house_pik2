<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add standalone indexes for high-frequency query columns.
 *
 * Why standalone (not composite)?
 *   log_gate & gate_manual_control already have (gate_id, event_ts) composite
 *   indexes, but queries that filter ONLY on event_ts (e.g. dashboard today count,
 *   activity trends) cannot use the leading gate_id column and fall back to a
 *   full table scan.  A single-column event_ts index fixes that.
 *
 *   log_cctv.log_time is queried for ORDER BY + WHERE NOT NULL in the CCTV
 *   snapshot endpoint — no index existed before.
 *
 *   transactions.status + transactions.entry_time are used by the active-vehicle
 *   count and the transaction list endpoint respectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        // log_gate: standalone event_ts for date-range-only queries
        Schema::table('log_gate', function (Blueprint $table) {
            $table->index('event_ts', 'log_gate_event_ts_idx');
        });

        // gate_manual_control: standalone event_ts
        Schema::table('gate_manual_control', function (Blueprint $table) {
            $table->index('event_ts', 'gate_manual_control_event_ts_idx');
        });

        // log_cctv: order-by log_time in snapshot queries
        Schema::table('log_cctv', function (Blueprint $table) {
            $table->index('log_time', 'log_cctv_log_time_idx');
        });

        // transactions: status filter (active vehicle count) + entry_time sort
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('status',     'transactions_status_idx');
            $table->index('entry_time', 'transactions_entry_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('log_gate', function (Blueprint $table) {
            $table->dropIndex('log_gate_event_ts_idx');
        });

        Schema::table('gate_manual_control', function (Blueprint $table) {
            $table->dropIndex('gate_manual_control_event_ts_idx');
        });

        Schema::table('log_cctv', function (Blueprint $table) {
            $table->dropIndex('log_cctv_log_time_idx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_status_idx');
            $table->dropIndex('transactions_entry_time_idx');
        });
    }
};
