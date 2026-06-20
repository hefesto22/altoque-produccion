<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            RestauranteAccessSeeder::class,
            ServiciosSeeder::class,
            TierSeeder::class,
            MenuSeeder::class,
            CaiSeeder::class,
            BrandingSettingSeeder::class,
        ]);
    }
}
