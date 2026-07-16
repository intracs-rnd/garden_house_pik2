<?php

namespace Database\Seeders;

use App\Models\Kartu;
use App\Models\User;
use Illuminate\Database\Seeder;

class KartuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (! class_exists(\Database\Factories\KartuFactory::class)) {
            return;
        }

        // Give each existing user one active card, and sprinkle in a few
        // expired / grace-period / blacklisted cards for testing the gate rules.
        User::all()->each(function (User $user) {
            Kartu::factory()->for($user)->create();
        });

        $sample = User::query()->inRandomOrder()->take(3)->get();

        if ($sample->count() >= 1) {
            Kartu::factory()->for($sample[0])->expired()->create();
        }

        if ($sample->count() >= 2) {
            Kartu::factory()->for($sample[1])->inGracePeriod()->create();
        }

        if ($sample->count() >= 3) {
            // User with unpaid dues -> card should be blocked at the gate.
            $sample[2]->update(['outstanding_balance' => 250000]);
            Kartu::factory()->for($sample[2])->blacklisted()->create();
        }
    }
}
