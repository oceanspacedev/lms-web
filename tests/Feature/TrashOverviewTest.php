<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\TrashLogs\Pages\ListTrashLogs;
use App\Filament\Resources\TrashLogs\TrashLogResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\DocumentStatusOverview;
use App\Filament\Widgets\TrashOverview;
use App\Models\Company;
use App\Models\Document;
use App\Models\TrashLog;
use App\Models\User;
use App\Services\Trash\TrashManager;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/** Kartu "Tempat sampah" di dasbor dan halaman Riwayat Penghapusan, khusus super admin (spec §3.3 dan §3.5). */
class TrashOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(PermissionSeeder::class);
    }

    private function signInAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(config('filament-shield.super_admin.name'));
        $this->actingAs($user);

        return $user;
    }

    /** User biasa dengan izin lihat dan hapus dokumen, tetapi bukan super admin. */
    private function signInAsDeleter(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Delete:Document', 'DeleteAny:Document']);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<Stat> */
    private function stats(): array
    {
        return (new ReflectionMethod(TrashOverview::class, 'getStats'))->invoke(Livewire::test(TrashOverview::class)->instance());
    }

    public function test_trash_card_visible_only_to_super_admin_with_breakdown_and_link(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $trash = app(TrashManager::class);
        $trash->trash(Document::factory()->create(), $admin);
        $trash->trash(Document::factory()->create(), $admin);
        $trash->trash(Company::factory()->create(), $admin);
        Company::factory()->create();

        $this->assertTrue(TrashOverview::canView());
        $visible = array_values(Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());
        $this->assertContains(TrashOverview::class, $visible);
        // Kartu tampil di bagian atas: setelah peringatan dan pengajuan menunggu, sebelum ringkasan status dokumen.
        $this->assertContains(DocumentStatusOverview::class, $visible);
        $this->assertLessThan(array_search(DocumentStatusOverview::class, $visible, true), array_search(TrashOverview::class, $visible, true));

        $stats = $this->stats();
        $this->assertCount(1, $stats);
        $this->assertSame('Tempat sampah', (string) $stats[0]->getLabel());
        $this->assertSame(3, $stats[0]->getValue());
        $this->assertSame('data menunggu keputusan · 2 dokumen, 1 badan usaha', (string) $stats[0]->getDescription());
        // Menu dengan jumlah terbanyak adalah Dokumen, tersaring "hanya data dihapus" (nilai 0 pada TrashedFilter).
        $this->assertSame(DocumentResource::getUrl('index', ['filters' => ['trashed' => ['value' => '0']]]), $stats[0]->getUrl());
        Livewire::test(TrashOverview::class)->assertSee('data menunggu keputusan · 2 dokumen, 1 badan usaha');

        $this->signInAsDeleter();
        $this->assertFalse(TrashOverview::canView());
        Livewire::test(TrashOverview::class)->assertForbidden();
        $this->assertNotContains(TrashOverview::class, Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());
    }

    public function test_trash_card_hidden_when_trash_is_empty(): void
    {
        $admin = $this->signInAsSuperAdmin();
        Document::factory()->create();

        $this->assertFalse(TrashOverview::canView());
        $this->assertNotContains(TrashOverview::class, Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());

        // Poll setelah isi tempat sampah dipulihkan tidak boleh gagal 403.
        $document = Document::factory()->create();
        app(TrashManager::class)->trash($document, $admin);
        $widget = Livewire::test(TrashOverview::class)->assertSuccessful();
        app(TrashManager::class)->restore($document->fresh(), $admin);
        $widget->call('$refresh')->assertSuccessful();
    }

    public function test_card_link_shows_trashed_rows_in_the_target_list_including_users(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $leaver = User::factory()->create(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);
        $active = User::factory()->create();
        app(TrashManager::class)->trash($leaver, $admin);

        $stats = $this->stats();
        $this->assertSame(UserResource::getUrl('index', ['filters' => ['trashed' => ['value' => '0']]]), $stats[0]->getUrl());

        Livewire::withQueryParams(['filters' => ['trashed' => ['value' => '0']]])->test(ListUsers::class)
            ->assertCanSeeTableRecords([$leaver])->assertCanNotSeeTableRecords([$active]);
    }

    public function test_trash_log_keeps_the_actor_name_after_the_actor_is_force_deleted(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $leaver = User::factory()->create(['name' => 'Petugas Sudah Keluar', 'deactivated_at' => now(), 'deactivated_by' => $admin->id]);
        $trash = app(TrashManager::class);
        $trash->trash(Company::factory()->create(['name' => 'PT Jejak Petugas']), $leaver);
        $trash->trash($leaver, $admin);
        $trash->forceDelete($leaver, $admin);

        $log = TrashLog::where('subject_label', 'PT Jejak Petugas')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame('Petugas Sudah Keluar', $log->details['actor_name']);
        Livewire::test(ListTrashLogs::class)->assertTableColumnStateSet('user.name', 'Petugas Sudah Keluar', $log)
            ->assertTableColumnStateSet('user.name', $admin->name, TrashLog::where('action', 'force_deleted')->sole());
    }

    public function test_trash_log_page_lists_actions_for_super_admin_and_forbidden_for_others(): void
    {
        $admin = $this->signInAsSuperAdmin();
        $trash = app(TrashManager::class);
        $company = Company::factory()->create(['name' => 'PT Contoh Riwayat']);
        $trash->trash($company, $admin);
        $trash->restore($company->fresh(), $admin);
        $trash->trash($company->fresh(), $admin);
        $trash->forceDelete($company->fresh(), $admin);
        $trash->trash(Document::factory()->create(['title' => 'Kontrak Riwayat']), $admin);
        TrashLog::create(['subject_type' => 'document', 'subject_id' => 999, 'subject_label' => 'Dokumen Bermasalah', 'action' => 'force_deleted', 'user_id' => $admin->id,
            'details' => ['left_files' => [['disk' => 's3', 'path' => 'dokumen/sisa.pdf']]]]);

        $this->assertTrue(TrashLogResource::canViewAny());
        $this->assertFalse(TrashLogResource::canCreate());
        $this->get(TrashLogResource::getUrl())->assertOk()->assertSee('Riwayat Penghapusan');

        $logs = TrashLog::query()->orderBy('id')->get();
        $page = Livewire::test(ListTrashLogs::class)
            ->assertCanSeeTableRecords($logs)
            ->assertSee(['PT Contoh Riwayat', 'Kontrak Riwayat', 'Dihapus', 'Dipulihkan', 'Dihapus permanen', 'dokumen/sisa.pdf', $admin->name]);

        $page->searchTable('Kontrak Riwayat')->assertCanSeeTableRecords($logs->where('subject_label', 'Kontrak Riwayat'))
            ->assertCanNotSeeTableRecords($logs->where('subject_label', 'PT Contoh Riwayat'));
        $page->searchTable('')->filterTable('subject_type', 'company')
            ->assertCanSeeTableRecords($logs->where('subject_type', 'company'))->assertCanNotSeeTableRecords($logs->where('subject_type', 'document'));
        $page->removeTableFilters()->filterTable('action', 'restored')
            ->assertCanSeeTableRecords($logs->where('action', 'restored'))->assertCanNotSeeTableRecords($logs->where('action', '!=', 'restored'));

        $this->signInAsDeleter();
        $this->assertFalse(TrashLogResource::canViewAny());
        $this->get(TrashLogResource::getUrl())->assertForbidden();
        Livewire::test(ListTrashLogs::class)->assertForbidden();
    }
}
