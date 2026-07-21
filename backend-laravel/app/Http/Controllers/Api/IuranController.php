<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IuranPembayaran;
use App\Models\IuranPerumahan;
use App\Models\Kartu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class IuranController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Index (list tagihan)
    |--------------------------------------------------------------------------
    */

    /**
     * Daftar tagihan iuran.
     *
     * - Admin / Super Admin : semua tagihan, bisa filter by no_kk / periode / status.
     * - Warga               : hanya tagihan KK-nya sendiri (filter by no_kk user).
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        $query = IuranPerumahan::with('pembayaran.paidByUser')
            ->withCount(['kartus as kartus_active_count' => function ($q) {
                $q->where('status', Kartu::STATUS_AKTIF)
                  ->where('is_blacklisted', false);
            }])
            ->orderBy('deadline', 'desc');

        // Warga hanya melihat tagihan KK-nya
        if (! $isAdmin) {
            $query->byNoKk($user->no_kk ?? '');
        } else {
            // Admin bisa filter opsional by no_kk
            if ($request->filled('no_kk')) {
                $query->byNoKk($request->query('no_kk'));
            }
        }

        if ($request->filled('periode')) {
            $query->byPeriode($request->query('periode'));
        }

        if ($request->filled('status')) {
            $query->byStatus($request->query('status'));
        }

        // Auto-sync status terlambat sebelum query
        $this->syncOverdueStatuses($request->query('no_kk', $isAdmin ? null : ($user->no_kk ?? '')));

        $perPage = (int) $request->query('per_page', 15);
        $iurans  = $query->paginate($perPage);

        return $this->paginatedResponse($iurans, 'Daftar iuran berhasil diambil.');
    }

    /*
    |--------------------------------------------------------------------------
    | Store (buat tagihan baru) — Admin only
    |--------------------------------------------------------------------------
    */

    /**
     * Buat tagihan iuran baru (hanya Admin / Super Admin).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'no_kk'      => ['required', 'string', 'max:30'],
            'periode'    => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'jumlah'     => ['required', 'numeric', 'min:0'],
            'deadline'   => ['required', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);

        // Cek duplikat no_kk + periode
        $exists = IuranPerumahan::where('no_kk', $data['no_kk'])
            ->where('periode', $data['periode'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Tagihan untuk KK ini pada periode tersebut sudah ada.',
            ], 422);
        }

        $data['status'] = IuranPerumahan::STATUS_BELUM_BAYAR;

        $iuran = IuranPerumahan::create($data);

        return $this->successResponse($iuran, 'Tagihan iuran berhasil dibuat.', 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Show (detail tagihan)
    |--------------------------------------------------------------------------
    */

    /**
     * Detail satu tagihan iuran.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        $iuran = IuranPerumahan::with('pembayaran.paidByUser')->findOrFail($id);

        // Warga hanya boleh lihat tagihan KK-nya sendiri
        if (! $isAdmin && $iuran->no_kk !== $user->no_kk) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        $iuran->syncStatus();

        return $this->successResponse($iuran, 'Detail tagihan berhasil diambil.');
    }

    /*
    |--------------------------------------------------------------------------
    | Update (edit tagihan) — Admin only
    |--------------------------------------------------------------------------
    */

    /**
     * Update data tagihan iuran (hanya Admin / Super Admin).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $iuran = IuranPerumahan::findOrFail($id);

        $data = $request->validate([
            'no_kk'      => ['sometimes', 'required', 'string', 'max:30'],
            'periode'    => ['sometimes', 'required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'jumlah'     => ['sometimes', 'required', 'numeric', 'min:0'],
            'deadline'   => ['sometimes', 'required', 'date'],
            'status'     => ['sometimes', 'required', 'in:belum_bayar,lunas,terlambat'],
            'keterangan' => ['nullable', 'string'],
        ]);

        // Cek duplikat jika no_kk / periode berubah
        if (isset($data['no_kk']) || isset($data['periode'])) {
            $newNoKk   = $data['no_kk']   ?? $iuran->no_kk;
            $newPeriode = $data['periode'] ?? $iuran->periode;

            $exists = IuranPerumahan::where('no_kk', $newNoKk)
                ->where('periode', $newPeriode)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tagihan untuk KK ini pada periode tersebut sudah ada.',
                ], 422);
            }
        }

        $iuran->update($data);

        return $this->successResponse($iuran->fresh('pembayaran'), 'Tagihan iuran berhasil diperbarui.');
    }

    /*
    |--------------------------------------------------------------------------
    | Destroy (hapus tagihan) — Admin only
    |--------------------------------------------------------------------------
    */

    /**
     * Hapus tagihan iuran beserta riwayat pembayarannya.
     */
    public function destroy(int $id): JsonResponse
    {
        $iuran = IuranPerumahan::findOrFail($id);
        $iuran->pembayaran()->delete();
        $iuran->delete();

        return $this->successResponse(null, 'Tagihan iuran berhasil dihapus.');
    }

    /*
    |--------------------------------------------------------------------------
    | Pay (bayar iuran) — Warga
    |--------------------------------------------------------------------------
    */

    /**
     * Warga membayar iuran.
     *
     * Satu pembayaran melunasi seluruh tagihan untuk no_kk tersebut di periode itu.
     * Semua akun dengan no_kk yang sama otomatis dianggap sudah membayar karena
     * status tagihan (iuran_perumahan) di-update ke "lunas".
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $iuran = IuranPerumahan::findOrFail($id);

        // Warga tidak bisa bayar tagihan KK lain
        if ($iuran->no_kk !== $user->no_kk) {
            return response()->json(['success' => false, 'message' => 'Tidak diizinkan.'], 403);
        }

        // Sudah lunas
        if ($iuran->isPaid()) {
            return response()->json(['success' => false, 'message' => 'Tagihan ini sudah lunas.'], 422);
        }

        $data = $request->validate([
            'metode_bayar'     => ['required', 'in:transfer'],
            'catatan'          => ['nullable', 'string'],
            'nominal_transfer' => ['nullable', 'numeric', 'min:0'],
            'rekening_tujuan'  => ['nullable', 'string', 'max:100'],
            'bukti_bayar'      => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'], // up to 5MB
        ]);

        // Simpan file bukti pembayaran ke storage (public disk)
        $buktiPath = null;
        if ($request->hasFile('bukti_bayar')) {
            $buktiPath = $request->file('bukti_bayar')->store('iuran_bukti', 'public');
        }

        // Simpan riwayat pembayaran
        $pembayaran = IuranPembayaran::create([
            'iuran_perumahan_id' => $iuran->id,
            'no_kk'              => $iuran->no_kk,
            'paid_by_user_id'    => $user->id,
            'jumlah_bayar'       => $iuran->jumlah,
            'metode_bayar'       => 'transfer',
            'bukti_bayar'        => $buktiPath,
            'catatan'            => $data['catatan'] ?? null,
            'nominal_transfer'   => $data['nominal_transfer'] ?? null,
            'rekening_tujuan'    => $data['rekening_tujuan'] ?? null,
            'paid_at'            => Carbon::now(),
        ]);

        // Update status tagihan menjadi lunas
        $iuran->update(['status' => IuranPerumahan::STATUS_LUNAS]);

        // Kurangi / clear outstanding_balance untuk semua akun yang memiliki KK sama.
        // Menggunakan CASE untuk mencegah nilai negatif.
        try {
            $amount = (float) $iuran->jumlah;
            if (! empty($iuran->no_kk) && $amount > 0) {
                \Illuminate\Support\Facades\DB::table('users')
                    ->where('no_kk', $iuran->no_kk)
                    ->update([
                        'outstanding_balance' => \Illuminate\Support\Facades\DB::raw(
                            "CASE WHEN outstanding_balance > {$amount} THEN outstanding_balance - {$amount} ELSE 0 END"
                        ),
                    ]);
            }
        } catch (\Exception $e) {
            // Jangan gagalkan pembayaran hanya karena update saldo pengguna gagal.
            // Log error jika perlu (logger tidak di-inject di controller ini).
        }

        return $this->successResponse(
            $iuran->fresh('pembayaran.paidByUser'),
            'Pembayaran iuran berhasil. Terima kasih!'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | History (riwayat pembayaran)
    |--------------------------------------------------------------------------
    */

    /**
     * Riwayat pembayaran iuran.
     *
     * - Admin / Super Admin : semua riwayat, bisa filter by no_kk.
     * - Warga               : riwayat KK-nya sendiri.
     */
    public function history(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = in_array($user->role, ['admin', 'superadmin'], true);

        $query = IuranPembayaran::with(['iuranPerumahan', 'paidByUser'])
            ->orderBy('paid_at', 'desc');

        if (! $isAdmin) {
            $query->where('no_kk', $user->no_kk ?? '');
        } else {
            if ($request->filled('no_kk')) {
                $query->where('no_kk', $request->query('no_kk'));
            }
        }

        $perPage  = (int) $request->query('per_page', 15);
        $riwayat  = $query->paginate($perPage);

        return $this->paginatedResponse($riwayat, 'Riwayat pembayaran berhasil diambil.');
    }

    /*
    |--------------------------------------------------------------------------
    | Generate (batch buat tagihan per KK) — Admin only
    |--------------------------------------------------------------------------
    */

    /**
     * Generate tagihan iuran secara batch untuk semua no_kk unik yang ada di tabel users.
     * Berguna untuk keperluan rutin awal bulan.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'periode'    => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'jumlah'     => ['required', 'numeric', 'min:0'],
            'deadline'   => ['required', 'date'],
            'keterangan' => ['nullable', 'string'],
        ]);

        // Ambil semua no_kk unik dari tabel users yang memiliki kartu aktif (is_deleted = false)
        $noKkList = \App\Models\User::query()
            ->whereNotNull('no_kk')
            ->where('no_kk', '!=', '')
            ->whereExists(function ($query) {
                $query->select(\Illuminate\Support\Facades\DB::raw(1))
                    ->from('kartus')
                    ->whereColumn('kartus.user_id', 'users.id')
                    ->where('kartus.is_deleted', false);
            })
            ->distinct()
            ->pluck('no_kk');

        $created = 0;
        $skipped = 0;

        foreach ($noKkList as $noKk) {
            $exists = IuranPerumahan::where('no_kk', $noKk)
                ->where('periode', $data['periode'])
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            IuranPerumahan::create([
                'no_kk'      => $noKk,
                'periode'    => $data['periode'],
                'jumlah'     => $data['jumlah'],
                'deadline'   => $data['deadline'],
                'status'     => IuranPerumahan::STATUS_BELUM_BAYAR,
                'keterangan' => $data['keterangan'] ?? null,
            ]);

            $created++;
        }

        return $this->successResponse(
            ['created' => $created, 'skipped' => $skipped, 'total_kk' => $noKkList->count()],
            "Berhasil generate {$created} tagihan. {$skipped} KK sudah memiliki tagihan untuk periode ini."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Private helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Sync status "terlambat" untuk tagihan yang melewati deadline.
     */
    private function syncOverdueStatuses(?string $noKk = null): void
    {
        $query = IuranPerumahan::where('status', IuranPerumahan::STATUS_BELUM_BAYAR)
            ->where('deadline', '<', Carbon::today());

        if ($noKk) {
            $query->where('no_kk', $noKk);
        }

        $query->update(['status' => IuranPerumahan::STATUS_TERLAMBAT]);
    }
}
