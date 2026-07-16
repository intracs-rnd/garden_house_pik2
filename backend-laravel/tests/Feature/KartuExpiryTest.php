<?php

namespace Tests\Feature;

use App\Models\Kartu;
use App\Models\User;
use App\Services\KartuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class KartuExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A card that is still valid later today (expires at a future hour)
     * must remain active and grant access.
     */
    public function test_card_valid_until_later_today_is_still_active(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 7, 10, 0, 0));

        $user  = User::factory()->create(['outstanding_balance' => 0]);
        $kartu = Kartu::factory()->for($user)->create([
            'status'      => Kartu::STATUS_AKTIF,
            'valid_from'  => '2026-07-07 00:00:00',
            'valid_until' => '2026-07-08 17:00:00',
            'grace_days'  => 0,
        ]);

        $decision = $kartu->evaluateAccess();

        $this->assertTrue($decision['allowed']);
        $this->assertSame(Kartu::STATUS_AKTIF, $kartu->fresh()->status);
    }

    /**
     * Once the valid_until date & time (plus grace) has passed, the card must
     * be denied AND automatically flipped to Non Aktif.
     */
    public function test_card_auto_deactivates_after_expiry_hour(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 17, 0, 1));

        $user  = User::factory()->create(['outstanding_balance' => 0]);
        $kartu = Kartu::factory()->for($user)->create([
            'status'      => Kartu::STATUS_AKTIF,
            'valid_from'  => '2026-07-07 00:00:00',
            'valid_until' => '2026-07-08 17:00:00',
            'grace_days'  => 0,
        ]);

        $decision = $kartu->evaluateAccess();

        $this->assertFalse($decision['allowed']);
        $this->assertSame(Kartu::REASON_EXPIRED, $decision['reason']);
        $this->assertSame(Kartu::STATUS_NONAKTIF, $kartu->fresh()->status);
    }

    /**
     * One minute before the expiry hour the card is still valid.
     */
    public function test_card_still_valid_one_minute_before_expiry(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 8, 16, 59, 0));

        $user  = User::factory()->create(['outstanding_balance' => 0]);
        $kartu = Kartu::factory()->for($user)->create([
            'status'      => Kartu::STATUS_AKTIF,
            'valid_from'  => '2026-07-07 00:00:00',
            'valid_until' => '2026-07-08 17:00:00',
            'grace_days'  => 0,
        ]);

        $this->assertTrue($kartu->evaluateAccess()['allowed']);
        $this->assertSame(Kartu::STATUS_AKTIF, $kartu->fresh()->status);
    }

    /**
     * The scheduled bulk job deactivates every expired active card.
     */
    public function test_deactivate_expired_service_bulk_updates_cards(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 9, 8, 0, 0));

        $user = User::factory()->create(['outstanding_balance' => 0]);

        $expired = Kartu::factory()->for($user)->create([
            'status'      => Kartu::STATUS_AKTIF,
            'valid_from'  => '2026-07-01 00:00:00',
            'valid_until' => '2026-07-08 17:00:00',
            'grace_days'  => 0,
        ]);

        $stillValid = Kartu::factory()->for($user)->create([
            'status'      => Kartu::STATUS_AKTIF,
            'valid_from'  => '2026-07-01 00:00:00',
            'valid_until' => '2026-07-31 17:00:00',
            'grace_days'  => 0,
        ]);

        $count = app(KartuService::class)->deactivateExpired();

        $this->assertSame(1, $count);
        $this->assertSame(Kartu::STATUS_NONAKTIF, $expired->fresh()->status);
        $this->assertSame(Kartu::STATUS_AKTIF, $stillValid->fresh()->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
