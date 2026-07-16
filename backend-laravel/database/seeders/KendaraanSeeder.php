<?php

namespace Database\Seeders;

use App\Models\Kendaraan;
use App\Models\User;
use Illuminate\Database\Seeder;

class KendaraanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (! class_exists(\Database\Factories\KendaraanFactory::class)) {
            return;
        }

        $userIds = User::query()->pluck('id');

        Kendaraan::factory()
            ->count(15)
            ->create([
                'user_id' => fn () => $userIds->isNotEmpty() ? $userIds->random() : null,
            ]);
    }
}
