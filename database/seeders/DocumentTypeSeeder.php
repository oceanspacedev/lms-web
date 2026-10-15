<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--resource' => 'DocumentTypeResource',
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);

        foreach ([
            ['name' => 'Perjanjian Kerja Sama', 'has_expiry' => true, 'reminder_days' => [90, 60, 30]],
            ['name' => 'Izin Usaha/NIB', 'has_expiry' => true, 'reminder_days' => [60, 30, 7]],
            ['name' => 'Akta Pendirian', 'has_expiry' => false, 'reminder_days' => []],
        ] as $documentType) {
            DocumentType::firstOrCreate(['name' => $documentType['name']], [
                'has_expiry' => $documentType['has_expiry'],
                'reminder_days' => $documentType['reminder_days'],
                'is_active' => true,
            ]);
        }
    }
}
