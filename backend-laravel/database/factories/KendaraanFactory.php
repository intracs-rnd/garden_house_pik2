<?php

namespace Database\Factories;

use App\Models\Kendaraan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Kendaraan>
 */
class KendaraanFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\App\Models\Kendaraan>
     */
    protected $model = Kendaraan::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition()
    {
        $merk = $this->faker->randomElement(['Toyota', 'Honda', 'Mitsubishi', 'Suzuki', 'Isuzu', 'Daihatsu']);

        return [
            'user_id'      => null,
            'nama'         => $merk . ' ' . $this->faker->randomElement(['Avanza', 'Xenia', 'Innova', 'Pajero', 'L300', 'Ranger']),
            'nomor_plat'   => 'B ' . $this->faker->numberBetween(1000, 9999) . ' ' . strtoupper($this->faker->lexify('???')),
            'merk'         => $merk,
            'model'        => Str::upper($this->faker->bothify('??-###')),
            'tahun'        => $this->faker->numberBetween(2010, (int) date('Y')),
        ];
    }
}
