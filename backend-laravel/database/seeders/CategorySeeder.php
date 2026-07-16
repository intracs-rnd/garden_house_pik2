<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $categories = [
            'Mobil Penumpang',
            'Truk',
            'Bus',
            'Motor Operasional',
            'Alat Berat',
        ];

        foreach ($categories as $name) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name'        => $name,
                    'description' => "Kategori kendaraan {$name}.",
                    'is_active'   => true,
                ]
            );
        }
    }
}
