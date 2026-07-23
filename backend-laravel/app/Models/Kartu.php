<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Models\IuranPerumahan;
use App\Models\Card;
use App\Support\ReadWriteSplit;
use Illuminate\Support\Facades\Log;

class Kartu extends Model
{
    use HasFactory, ReadWriteSplit;

    /**
     * Koneksi database yang digunakan model ini.
     *
     * Koneksi `pgsql` sudah dikonfigurasi dengan read/write split:
     *   READ  → 192.168.214.161  (PC Admin – replica, SELECT otomatis)
     *   WRITE → 192.168.214.163  (Virtual IP – master, DML otomatis)
     *
     * @var string
     */
    protected $connection = 'pgsql';

    /**
     * Status constants (integer-based).
     */
    public const STATUS_AKTIF     = 1;
    public const STATUS_NONAKTIF  = 2;
    public const STATUS_BLACKLIST = 3;

    /**
     * Maximum number of access cards allowed per Kartu Keluarga (no_kk).
     */
    public const MAX_CARDS_PER_KK = 4;

    /**
     * Map of status value => human-readable label.
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_AKTIF     => 'Aktif',
        self::STATUS_NONAKTIF  => 'Non Aktif',
        self::STATUS_BLACKLIST => 'Blacklist',
    ];

    /**
     * Access decision reason codes.
     */
    public const REASON_OK           = 'ok';
    public const REASON_GRACE_PERIOD = 'grace_period';
    public const REASON_UNKNOWN_CARD = 'unknown_card';
    public const REASON_INACTIVE     = 'inactive';
    public const REASON_BLACKLISTED  = 'blacklisted';
    public const REASON_OUTSTANDING  = 'outstanding_payment';
    public const REASON_NOT_YET_VALID = 'not_yet_valid';
    public const REASON_EXPIRED      = 'expired';

    /**
     * Human-readable message per reason code.
     *
     * @var array<string, string>
     */
    public const REASON_MESSAGES = [
        self::REASON_OK            => 'Akses diberikan.',
        self::REASON_GRACE_PERIOD  => 'Akses diberikan (dalam masa tenggang).',
        self::REASON_UNKNOWN_CARD  => 'Kartu tidak dikenali.',
        self::REASON_INACTIVE      => 'Kartu tidak aktif.',
        self::REASON_BLACKLISTED   => 'Kartu diblokir (blacklist).',
        self::REASON_OUTSTANDING   => 'Akses ditolak: terdapat tunggakan pembayaran.',
        self::REASON_NOT_YET_VALID => 'Kartu belum berlaku.',
        self::REASON_EXPIRED       => 'Masa berlaku kartu telah habis.',
    ];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'kartus';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'card_number',
        'rfid_tag',
        'nama',
        'status',
        'is_blacklisted',
        'blacklist_reason',
        'valid_from',
        'valid_until',
        'grace_days',
        'keterangan',
        // Persisted access snapshot (last gate decision)
        'access_allowed',
        'access_reason',
        'access_message',
        'access_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status'         => 'integer',
        'is_blacklisted' => 'boolean',
        'grace_days'     => 'integer',
        'is_deleted'     => 'boolean',
        // Simpan tanggal + jam sehingga masa berlaku bisa habis pada jam tertentu.
        'valid_from'     => 'datetime',
        'valid_until'    => 'datetime',
        // Casts for persisted access snapshot
        'access_allowed' => 'boolean',
        'access_at'      => 'datetime',
    ];

    /**
     * Computed attributes appended to array / JSON form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'status_label',
        'access',
            'iuran',
        ];

    /**
     * The "booted" method of the model.
     *
     * Registers a global scope so cards flagged as deleted
     * (is_deleted = true) are hidden from every query by default,
     * emulating a soft delete without removing the row.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->where($builder->getModel()->getTable() . '.is_deleted', false);
        });

        // When a Kartu is created or updated, sync the corresponding Card record.
        //
        // WRITE ROUTING: Card sync selalu ditulis ke WRITE host (192.168.214.163).
        // Dengan menggunakan Card::writeQuery() kita memastikan SELECT untuk
        // "find existing card" pun pergi ke write host, menghindari replication lag
        // saat kartu baru dibuat dan langsung disync (sticky sudah handle ini di dalam
        // request, tapi writeQuery() menjamin konsistensi di luar request juga).
        $syncCard = static function ($kartu) {
            try {
                if (! $kartu->rfid_tag) {
                    return;
                }

                // Map kartus integer status to cards varchar status.
                $statusMap = [
                    self::STATUS_AKTIF     => 'Active',
                    self::STATUS_NONAKTIF  => 'Inactive',
                    self::STATUS_BLACKLIST => 'Suspended',
                ];

                $cardData = [
                    'uid'        => $kartu->rfid_tag,
                    'name'       => $kartu->user ? $kartu->user->name : null,
                    'unit'       => $kartu->nama,
                    'status'     => $statusMap[$kartu->status] ?? 'Active',
                    'expiry'     => $kartu->valid_until ? $kartu->valid_until->toDateString() : null,
                    'grace_days' => $kartu->grace_days,
                    'kartus_id'  => $kartu->id,
                ];

                $cardData = array_filter($cardData, function ($v) { return $v !== null; });

                // Force WRITE host (192.168.214.163) untuk SELECT + INSERT/UPDATE
                // sehingga sync selalu konsisten dengan data master terbaru.
                Card::writeQuery()->updateOrCreate(
                    ['kartus_id' => $kartu->id],
                    $cardData
                );
            } catch (\Throwable $e) {
                Log::error(sprintf('Failed to sync Card for Kartu id %s: %s', $kartu->id ?? 'n/a', $e->getMessage()));
            }
        };

        static::created($syncCard);
        static::updated($syncCard);
    }

    /**
     * The user who owns the access card.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tab-in / tab-out access logs for this card.
     */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(KartuAccessLog::class);
    }

    /**
     * Access card (card reader device record) linked to this kartu.
     */
    public function card(): HasMany
    {
        return $this->hasMany(Card::class, 'kartus_id');
    }

    /**
     * Scope a query to a given status.
     */
    public function scopeStatus($query, ?int $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }

    /**
     * Scope a query to only active (usable) cards.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_AKTIF)
            ->where('is_blacklisted', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Access rule helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Last moment the card is still usable including grace period.
     */
    public function graceUntil(): ?Carbon
    {
        if (! $this->valid_until) {
            return null;
        }

        return $this->valid_until->copy()->addDays((int) $this->grace_days);
    }

    /**
     * Card status is Aktif.
     */
    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_AKTIF;
    }

    /**
     * Card is blacklisted (manual flag or blacklist status).
     */
    public function isBlacklisted(): bool
    {
        return $this->is_blacklisted || (int) $this->status === self::STATUS_BLACKLIST;
    }

    /**
     * Owner has outstanding (unpaid) dues.
     */
    public function hasOutstanding(): bool
    {
        return $this->user !== null && $this->user->hasOutstanding();
    }

    /**
     * Card is not valid yet (before valid_from date & time).
     */
    public function isNotYetValid(): bool
    {
        return $this->valid_from !== null && Carbon::now()->lt($this->valid_from);
    }

    /**
     * Card is past its validity date & time (may still be within grace).
     */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && Carbon::now()->gt($this->valid_until);
    }

    /**
     * Card is past the grace period and can no longer be used.
     */
    public function isPastGrace(): bool
    {
        $graceUntil = $this->graceUntil();

        return $graceUntil !== null && Carbon::now()->gt($graceUntil);
    }

    /**
     * Card is expired but still inside the grace period.
     */
    public function isInGracePeriod(): bool
    {
        return $this->isExpired() && ! $this->isPastGrace();
    }

    /**
     * Evaluate whether the card may open the gate right now.
     *
     * Rules (first match wins):
     *  1. Blacklisted             -> deny
     *  2. Not yet valid            -> deny
     *  3. Past grace period        -> deny + kartu otomatis di-non-aktifkan
     *  4. Not active               -> deny
     *  5. Outstanding payment      -> deny (blocked because of tunggakan)
     *     If outstanding persists beyond (grace end + 1 day) the card is
     *     escalated to blacklist automatically.
     *  6. Inside grace period      -> allow
     *  7. Otherwise                -> allow
     *
     * @return array{allowed: bool, reason: string, message: string}
     */
    public function evaluateAccess(): array
    {
        if ($this->isBlacklisted()) {
            return $this->decision(false, self::REASON_BLACKLISTED);
        }

        if ($this->isNotYetValid()) {
            return $this->decision(false, self::REASON_NOT_YET_VALID);
        }

        // Masa berlaku + tenggang telah lewat: non-aktifkan kartu secara otomatis.
        if ($this->isPastGrace()) {
            $this->deactivateIfExpired();

            return $this->decision(false, self::REASON_EXPIRED);
        }

        if (! $this->isActive()) {
            return $this->decision(false, self::REASON_INACTIVE);
        }

        if ($this->hasOutstanding()) {
            // If outstanding persists beyond (grace end + 1 day), escalate to blacklist.
            if ($this->blacklistIfOutstandingPastDue()) {
                return $this->decision(false, self::REASON_BLACKLISTED);
            }

            return $this->decision(false, self::REASON_OUTSTANDING);
        }

        if ($this->isInGracePeriod()) {
            return $this->decision(true, self::REASON_GRACE_PERIOD);
        }

        return $this->decision(true, self::REASON_OK);
    }

    /**
     * Automatically switch the card to "Non Aktif" once its validity
     * (including the grace period) has fully passed. The change is persisted
     * so the card stays inactive on subsequent taps.
     *
     * Only cards that are currently Aktif are affected; blacklisted or already
     * inactive cards are left untouched.
     *
     * @return bool True when the status was flipped to Non Aktif.
     */
    public function deactivateIfExpired(): bool
    {
        if ((int) $this->status !== self::STATUS_AKTIF) {
            return false;
        }

        if (! $this->isPastGrace()) {
            return false;
        }

        $this->status = self::STATUS_NONAKTIF;

        // Persist without firing model events to avoid unintended side effects.
        if ($this->exists) {
            $this->saveQuietly();
        }

        return true;
    }

    /**
     * Blacklist a card automatically when the owner's outstanding iuran
     * persists beyond (deadline + grace_days + 1 day).
     *
     * This helper is deliberately conservative: it only acts when there is a
     * known outstanding iuran with a parsable deadline and the computed
     * blacklist threshold has already passed. The change is persisted
     * quietly to avoid triggering unrelated model events.
     *
     * @return bool True when the card was flagged as blacklisted.
     */
    public function blacklistIfOutstandingPastDue(): bool
    {
        // Check if already blacklisted BEFORE making any changes
        if ($this->isBlacklisted()) {
            return false;
        }

        if (! $this->hasOutstanding()) {
            return false;
        }

        $iuran = $this->iuran;

        if (! isset($iuran['exists']) || ! $iuran['exists'] || empty($iuran['deadline'])) {
            return false;
        }

        try {
            $deadline = Carbon::parse($iuran['deadline']);
        } catch (\Exception $e) {
            return false;
        }

        $graceDays = (int) $this->grace_days;
        $graceEnd = $deadline->copy()->addDays($graceDays);
        $blacklistAt = $graceEnd->copy()->addDay(); // +1 day after grace end

        if (Carbon::now()->lte($blacklistAt)) {
            return false;
        }

        // Set both status and flag together
        $this->status = self::STATUS_BLACKLIST;
        $this->is_blacklisted = true;
        $this->blacklist_reason = 'Blacklist otomatis: tunggakan melewati masa tenggang +1 hari.';

        if ($this->exists) {
            $this->saveQuietly();
        }

        return true;
    }

    /**
     * Build a decision payload.
     *
     * @return array{allowed: bool, reason: string, message: string}
     */
    protected function decision(bool $allowed, string $reason): array
    {
        return [
            'allowed' => $allowed,
            'reason'  => $reason,
            'message' => self::REASON_MESSAGES[$reason] ?? $reason,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Human-readable status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[(int) $this->status] ?? 'Unknown';
    }

    /**
     * Current access decision snapshot.
     *
     * @return array{allowed: bool, reason: string, message: string}
     */
    public function getAccessAttribute(): array
    {
        // If a persisted access snapshot exists (written by gate or simulator), prefer it.
        if ($this->access_at !== null) {
            $reason = $this->access_reason ?? ($this->access_allowed ? self::REASON_OK : 'unknown');
            $message = $this->access_message ?? (self::REASON_MESSAGES[$reason] ?? $reason);

            return [
                'allowed' => (bool) ($this->access_allowed ?? false),
                'reason'  => $reason,
                'message' => $message,
                'at'      => $this->access_at ? $this->access_at->toDateTimeString() : null,
            ];
        }

        return $this->evaluateAccess();
    }

    /**
     * Current outstanding iuran information for the card owner's KK (if available).
     *
     * Returns array with keys: exists (bool), periode, deadline (ISO date), status, status_label
     */
    public function getIuranAttribute(): array
    {
        if (! $this->user || empty($this->user->no_kk)) {
            return ['exists' => false];
        }

        $noKk = $this->user->no_kk;

        // Cache per-request to avoid N+1 queries when serializing many cards
        static $iuranCache = [];

        if (array_key_exists($noKk, $iuranCache)) {
            $iuran = $iuranCache[$noKk];
        } else {
            $iuran = IuranPerumahan::where('no_kk', $noKk)
                ->whereIn('status', [IuranPerumahan::STATUS_BELUM_BAYAR, IuranPerumahan::STATUS_TERLAMBAT])
                ->orderBy('deadline', 'asc')
                ->first();

            $iuranCache[$noKk] = $iuran;
        }

        if (! $iuran) {
            return ['exists' => false];
        }

        return [
            'exists' => true,
            'periode' => $iuran->periode,
            'deadline' => $iuran->deadline ? $iuran->deadline->toDateString() : null,
            'status' => $iuran->status,
            'status_label' => $iuran->status_label,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Soft delete (is_deleted flag)
    |--------------------------------------------------------------------------
    */

    /**
     * Include soft-deleted cards in the query.
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Restrict the query to only soft-deleted cards.
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')
            ->where($this->getTable() . '.is_deleted', true);
    }

    /**
     * Soft delete the card: flag it as deleted instead of removing it.
     */
    public function softDelete(): bool
    {
        $this->is_deleted = true;

        return $this->save();
    }

    /**
     * Restore a soft-deleted card.
     */
    public function restore(): bool
    {
        $this->is_deleted = false;

        return $this->save();
    }
}
