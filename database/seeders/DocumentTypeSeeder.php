<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

/**
 * Jenis dokumen awal. Jenis yang sudah ada (termasuk yang sudah diubah user) tidak disentuh.
 */
class DocumentTypeSeeder extends Seeder
{
    /**
     * @var list<array{name: string, has_expiry: bool, reminder_days: list<int>}>
     */
    public const DOCUMENT_TYPES = [
        ['name' => 'Perjanjian Kerja Sama', 'has_expiry' => true, 'reminder_days' => [90, 60, 30]],
        ['name' => 'Izin Usaha/NIB', 'has_expiry' => true, 'reminder_days' => [60, 30, 7]],
        ['name' => 'Akta Pendirian', 'has_expiry' => false, 'reminder_days' => []],
    ];

    public function run(): void
    {
        foreach (self::DOCUMENT_TYPES as $documentType) {
            DocumentType::firstOrCreate(['name' => $documentType['name']], [
                'has_expiry' => $documentType['has_expiry'],
                'reminder_days' => $documentType['reminder_days'],
                'is_active' => true,
            ]);
        }
    }
}
