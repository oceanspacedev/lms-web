<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--resource' => 'CompanyResource',
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);
    }
}
