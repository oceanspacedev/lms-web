<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DocumentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--resource' => 'DocumentResource',
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);
    }
}
