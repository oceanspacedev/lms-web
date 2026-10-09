<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Akun dummy untuk pengujian manual, satu akun per role.
 *
 * Jalankan: php artisan db:seed --class=DummyAccountSeeder
 * Masuk dengan email {nama-role}@dummy.test dan kata sandi PASSWORD.
 * Nomor WhatsApp dikosongkan agar pesan tidak sampai ke nomor sungguhan. Isi LMS_DUMMY_PHONE di .env
 * dengan nomor milik sendiri bila ingin menguji pengiriman.
 * Seeder ini menolak berjalan di production dan tidak dipanggil oleh DatabaseSeeder.
 */
class DummyAccountSeeder extends Seeder
{
    public const PASSWORD = 'dummy-password';

    public const EMAIL_DOMAIN = 'dummy.test';

    /**
     * Role dasar untuk menguji alur dokumen dan pengajuan. Berawalan dummy_ agar tidak menimpa role buatan sendiri.
     *
     * @var array<string, list<string>>
     */
    public const BASELINE_ROLES = [
        'dummy_legal' => [
            'ViewAny:Document', 'View:Document', 'Create:Document', 'Update:Document', 'Delete:Document',
            'ViewAny:Company', 'View:Company', 'ViewAny:DocumentType', 'View:DocumentType',
            'ViewAny:ReminderLog', 'View:ReminderLog', 'ViewAny:ReminderTemplate', 'View:ReminderTemplate',
            'ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest', 'Review:DocumentRequest', 'receive_reminder',
        ],
        'dummy_viewer' => ['ViewAny:Document', 'View:Document'],
        'dummy_pemeriksa' => ['ViewAny:Document', 'View:Document', 'ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest'],
        'dummy_penyetuju' => ['ViewAny:Document', 'View:Document', 'Create:Document', 'ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest', 'Approve:DocumentRequest'],
        'dummy_pengaju' => ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->line('<error>Seeder akun dummy tidak boleh dijalankan di production.</error>');

            return;
        }

        Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $this->call([
            MonitoringPermissionSeeder::class, UserPermissionSeeder::class, DocumentMasterPermissionSeeder::class,
            DocumentRequestPermissionSeeder::class, ReminderLogSeeder::class,
        ]);
        foreach (self::BASELINE_ROLES as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions(array_map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::where('guard_name', 'web')->orderBy('name')->get()->each(function (Role $role): void {
            $user = User::updateOrCreate(['email' => Str::slug($role->name).'@'.self::EMAIL_DOMAIN], [
                'name' => 'Dummy '.Str::headline(Str::replaceStart('dummy_', '', $role->name)), 'password' => Hash::make(self::PASSWORD), 'phone' => env('LMS_DUMMY_PHONE'),
            ]);
            $user->syncRoles([$role]);
            $this->command?->line($user->email.' → '.$role->name);
        });
    }
}
