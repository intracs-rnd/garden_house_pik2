<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CardImportController extends Controller
{
    /**
     * GET /api/cards
     * Daftar semua cards dengan pencarian & paginasi (Super Admin only).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Card::writeQuery()->orderByDesc('id');

        if ($search = trim($request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('uid', 'ilike', "%{$search}%")
                  ->orWhere('name', 'ilike', "%{$search}%")
                  ->orWhere('unit', 'ilike', "%{$search}%")
                  ->orWhere('status', 'ilike', "%{$search}%");
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $cards   = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $cards->items(),
            'meta'    => [
                'current_page' => $cards->currentPage(),
                'last_page'    => $cards->lastPage(),
                'per_page'     => $cards->perPage(),
                'total'        => $cards->total(),
            ],
        ]);
    }

    /**
     * POST /api/cards/import
     *
     * Import kartu (cards) dari file CSV dengan format:
     *   No,UID,Status,Datetime
     *
     * Rules:
     *   - name & unit dikosongkan
     *   - grace_days = 0
     *   - kartus_id = null
     *   - expiry diambil dari kolom Datetime CSV
     *   - UID yang sudah ada di tabel di-skip (tidak gagal, dilaporkan)
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuka file CSV.',
            ], 422);
        }

        // Skip header row
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return response()->json([
                'success' => false,
                'message' => 'File CSV kosong atau tidak valid.',
            ], 422);
        }

        // Normalize header: lowercase + trim
        $header = array_map(fn($h) => strtolower(trim($h)), $header);

        $uidIdx      = array_search('uid', $header);
        $statusIdx   = array_search('status', $header);
        $datetimeIdx = array_search('datetime', $header);

        if ($uidIdx === false || $statusIdx === false || $datetimeIdx === false) {
            fclose($handle);
            return response()->json([
                'success' => false,
                'message' => 'Format CSV tidak valid. Kolom yang dibutuhkan: No, UID, Status, Datetime',
            ], 422);
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];
        $rowNum   = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;

            if (count($row) < 4) {
                $errors[] = "Baris $rowNum: kolom tidak lengkap.";
                continue;
            }

            $uid      = trim($row[$uidIdx]);
            $status   = strtoupper(trim($row[$statusIdx]));
            $datetime = trim($row[$datetimeIdx]);

            if (empty($uid)) {
                $errors[] = "Baris $rowNum: UID kosong.";
                continue;
            }

            // Validate allowed status values
            $allowedStatuses = ['ALLOW', 'REJECT'];
            if (!in_array($status, $allowedStatuses, true)) {
                $errors[] = "Baris $rowNum: Status '$status' tidak dikenal.";
                continue;
            }

            // Validate datetime
            $expiry = null;
            try {
                $expiry = \Carbon\Carbon::parse($datetime)->toDateString();
            } catch (\Exception $e) {
                $errors[] = "Baris $rowNum: Format datetime '$datetime' tidak valid.";
                continue;
            }

            // Skip duplicate UID
            if (Card::where('uid', $uid)->exists()) {
                $skipped++;
                continue;
            }

            try {
                Card::create([
                    'uid'       => $uid,
                    'name'      => '',
                    'unit'      => '',
                    'status'    => $status,
                    'expiry'    => $expiry,
                    'grace_days'=> 0,
                    'kartus_id' => null,
                ]);
                $imported++;
            } catch (\Exception $e) {
                Log::error("CardImport baris $rowNum: " . $e->getMessage());
                $errors[] = "Baris $rowNum: Gagal simpan — " . $e->getMessage();
            }
        }

        fclose($handle);

        return response()->json([
            'success'  => true,
            'message'  => "Import selesai. Berhasil: $imported, Dilewati (duplikat): $skipped, Error: " . count($errors),
            'data'     => [
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ],
        ]);
    }

    /**
     * PUT /api/cards/manage/{id}
     * Update card langsung — superadmin only, tanpa implicit route model binding.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Force read dari write host agar konsisten dengan operasi DML
        $card = Card::writeQuery()->find($id);

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'Card tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|nullable|string',
            'unit'       => 'sometimes|nullable|string',
            'status'     => 'sometimes|required|string|in:ALLOW,REJECT',
            'expiry'     => 'sometimes|nullable|date',
            'grace_days' => 'sometimes|nullable|integer|min:0',
        ]);

        // Kosongkan expiry jika dikirim sebagai string kosong
        if (array_key_exists('expiry', $validated) && $validated['expiry'] === '') {
            $validated['expiry'] = null;
        }

        // Pastikan name & unit tidak null agar tidak melanggar NOT NULL constraint di DB
        if (array_key_exists('name', $validated) && $validated['name'] === null) {
            $validated['name'] = '';
        }
        if (array_key_exists('unit', $validated) && $validated['unit'] === null) {
            $validated['unit'] = '';
        }

        try {
            $card->fill($validated)->save();

            return response()->json([
                'success' => true,
                'message' => 'Card berhasil diperbarui.',
                'data'    => Card::writeQuery()->find($id),
            ]);
        } catch (\Exception $e) {
            Log::error("CardImport update #{$id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui card: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/cards/manage/{id}
     * Hapus card langsung — superadmin only, tanpa implicit route model binding.
     */
    public function destroy(int $id): JsonResponse
    {
        // Force read dari write host
        $card = Card::writeQuery()->find($id);

        if (! $card) {
            return response()->json([
                'success' => false,
                'message' => 'Card tidak ditemukan.',
            ], 404);
        }

        try {
            $card->delete();

            return response()->json([
                'success' => true,
                'message' => 'Card berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            Log::error("CardImport delete #{$id}: " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus card: ' . $e->getMessage(),
            ], 500);
        }
    }
}
