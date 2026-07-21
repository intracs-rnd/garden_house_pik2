<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('iuran_pembayaran', function (Blueprint $table) {
            // Nominal transfer (opsional, karena warga bisa bayar kurang/lebih)
            $table->decimal('nominal_transfer', 12, 2)->nullable()->after('catatan');

            // Rekening tujuan (opsional)
            $table->string('rekening_tujuan', 100)->nullable()->after('nominal_transfer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iuran_pembayaran', function (Blueprint $table) {
            if (Schema::hasColumn('iuran_pembayaran', 'rekening_tujuan')) {
                $table->dropColumn('rekening_tujuan');
            }
            if (Schema::hasColumn('iuran_pembayaran', 'nominal_transfer')) {
                $table->dropColumn('nominal_transfer');
            }
        });
    }
};
