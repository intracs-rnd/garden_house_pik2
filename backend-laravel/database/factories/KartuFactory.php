<?php

namespace Database\Factories;

use App\Models\Kartu;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Kartu>
 */
class KartuFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Kartu>
     */
    protected $model = Kartu::class;

    /**
     * Define the model's default state (active, currently valid card).
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $validFrom = $this->faker->dateTimeBetween('-6 months', '-1 month');

        return [
            'user_id'          => User::factory(),
            'card_number'      => strtoupper($this->faker->unique()->bothify('KRT-########')),
            'rfid_tag'         => strtoupper(bin2hex(random_bytes(5))),
            'nama'             => 'Kartu ' . $this->faker->firstName(),
            'status'           => Kartu::STATUS_AKTIF,
            'is_blacklisted'   => false,
            'blacklist_reason' => null,
            'valid_from'       => $validFrom->format('Y-m-d H:i:s'),
            'valid_until'      => now()->addMonths($this->faker->numberBetween(1, 12))->format('Y-m-d H:i:s'),
            'grace_days'       => $this->faker->randomElement([0, 7, 14, 30]),
            'keterangan'       => $this->faker->optional()->sentence(),
        ];
    }

    /**
     * Card whose validity (and grace period) has fully expired.
     */
    public function expired()
    {
        return $this->state(fn (array $attributes) => [
            'valid_from'  => now()->subYears(2)->format('Y-m-d H:i:s'),
            'valid_until' => now()->subMonths(2)->format('Y-m-d H:i:s'),
            'grace_days'  => 0,
        ]);
    }

    /**
     * Card that is expired but still inside the grace period.
     */
    public function inGracePeriod()
    {
        return $this->state(fn (array $attributes) => [
            'valid_from'  => now()->subYear()->format('Y-m-d H:i:s'),
            'valid_until' => now()->subDays(3)->format('Y-m-d H:i:s'),
            'grace_days'  => 30,
        ]);
    }

    /**
     * Card that expires later today at a specific hour (for testing the
     * hour-based, auto-deactivation rule).
     */
    public function expiringToday(int $hour = 17, int $minute = 0)
    {
        return $this->state(fn (array $attributes) => [
            'valid_from'  => now()->startOfDay()->format('Y-m-d H:i:s'),
            'valid_until' => now()->setTime($hour, $minute)->format('Y-m-d H:i:s'),
            'grace_days'  => 0,
        ]);
    }

    /**
     * Blacklisted card.
     */
    public function blacklisted()
    {
        return $this->state(fn (array $attributes) => [
            'status'           => Kartu::STATUS_BLACKLIST,
            'is_blacklisted'   => true,
            'blacklist_reason' => 'Tunggakan pembayaran belum diselesaikan.',
        ]);
    }
}
