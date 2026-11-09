<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sidebar dikelompokkan per kebutuhan: menu kerja harian di atas, lalu data master, pengguna, WhatsApp, sistem, dan bantuan.
 */
class NavigationGroupsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_groups_follow_daily_work_then_settings_then_help(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $user->givePermissionTo(array_map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'), [
            'ViewAny:Document', 'ViewAny:DocumentRequest', 'ViewAny:Company', 'ViewAny:DocumentType', 'ViewAny:User', 'ViewAny:Role',
            'ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog',
        ]));
        $this->actingAs($user);

        $navigation = collect(Filament::getNavigation())->mapWithKeys(fn ($group): array => [
            (string) $group->getLabel() => $group->getItems()->map(fn ($item): string => (string) $item->getLabel())->values()->all(),
        ]);

        $this->assertSame(['', 'Data Master', 'Pengguna & Akses', 'WhatsApp', 'Sistem', 'Bantuan'], $navigation->keys()->all());
        $this->assertSame(['Dasbor', 'Dokumen', 'Pengajuan Dokumen'], $navigation['']);
        $this->assertSame(['Badan Usaha', 'Jenis Dokumen'], $navigation['Data Master']);
        $this->assertSame(['User', 'Hak Akses'], $navigation['Pengguna & Akses']);
        $this->assertSame(['Pengaturan WhatsApp', 'Log Pengingat'], $navigation['WhatsApp']);
        $this->assertSame('Riwayat Penghapusan', $navigation['Sistem'][0]);
        $this->assertSame(['Panduan'], $navigation['Bantuan']);
    }
}
