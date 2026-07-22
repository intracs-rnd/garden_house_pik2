<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\RoleFeaturePermission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessControlSeeder extends Seeder
{
    /**
     * Seed the feature catalogue and the default permission matrix.
     *
     * Defaults preserve the previous behaviour:
     * - superadmin: full access to everything (implicit, no rows needed).
     * - admin: read-only (view) on the operational features.
     * - user: dashboard only.
     */
    public function run(): void
    {
        $features = [
            ['key' => 'dashboard',      'label' => 'Dashboard',              'sort_order' => 1],
            ['key' => 'kartu',          'label' => 'Kartu Akses',            'sort_order' => 2],
            ['key' => 'kartu_gate',     'label' => 'Simulasi Gate',          'sort_order' => 3],
            ['key' => 'users',          'label' => 'Data Warga',             'sort_order' => 4],
            ['key' => 'iuran',          'label' => 'Iuran Perumahan',       'sort_order' => 5],
            ['key' => 'kendaraan',      'label' => 'Kendaraan',              'sort_order' => 6],
            ['key' => 'reports',        'label' => 'Laporan',                'sort_order' => 7],
            ['key' => 'access_control', 'label' => 'Pengaturan Hak Akses',   'sort_order' => 8],
            ['key' => 'cameras',        'label' => 'Pengaturan Kamera',      'sort_order' => 9],
        ];

        DB::statement("SELECT setval(pg_get_serial_sequence('features','id'), (SELECT COALESCE(MAX(id), 1) FROM features))");

        foreach ($features as $feature) {
            Feature::updateOrCreate(['key' => $feature['key']], $feature);
        }

        $featureIds = Feature::query()->pluck('id', 'key');

        // Default permissions per manageable role.
        $defaults = [
            'admin' => [
                'dashboard' => RoleFeaturePermission::ACCESS_VIEW,
                'kartu'     => RoleFeaturePermission::ACCESS_VIEW,
                'users'     => RoleFeaturePermission::ACCESS_VIEW,
                'iuran'     => RoleFeaturePermission::ACCESS_VIEW,
                'kendaraan' => RoleFeaturePermission::ACCESS_VIEW,
                'reports'   => RoleFeaturePermission::ACCESS_VIEW,
            ],
            'user' => [
                'dashboard' => RoleFeaturePermission::ACCESS_VIEW,
                'kartu'     => RoleFeaturePermission::ACCESS_VIEW,
                'iuran'     => RoleFeaturePermission::ACCESS_VIEW,
            ],
        ];

        foreach ($defaults as $role => $map) {
            foreach ($map as $key => $access) {
                if (! isset($featureIds[$key])) {
                    continue;
                }

                RoleFeaturePermission::updateOrCreate(
                    ['role' => $role, 'feature_id' => $featureIds[$key]],
                    ['access' => $access]
                );
            }
        }
    }
}
