<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Filament\Resources\TrashLogs\TrashLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Controllers\PanduanVideoController;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Halaman Panduan: terbuka untuk semua user panel, memuat video tiap fitur, dan hanya menautkan halaman yang boleh dibuka user.
 */
class PanduanPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param  list<string>  $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'), $permissions));
        $this->actingAs($user);

        return $user;
    }

    private function signInAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $this->actingAs($user);

        return $user;
    }

    public function test_any_panel_user_can_open_the_guide_from_the_navigation(): void
    {
        $this->signIn(['ViewAny:Document']);

        $this->get(Panduan::getUrl())
            ->assertOk()
            ->assertSee('Panduan Fitur')
            ->assertSee('Pilih fitur');
        $this->get(DocumentResource::getUrl())->assertOk()->assertSee(Panduan::getUrl(), false);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get(Panduan::getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    public function test_every_guide_shows_its_video_steps_and_impacts(): void
    {
        $this->signInAsSuperAdmin();

        $page = Livewire::test(Panduan::class);
        $guides = $page->instance()->guides();

        $this->assertCount(15, $guides);
        foreach ($guides as $guide) {
            $page->assertSee($guide['title'])
                ->assertSeeHtml('id="panduan-'.$guide['id'].'"')
                ->assertSee($guide['steps'][0]['text'])
                ->assertSee($guide['steps'][0]['description'])
                ->assertSee($guide['impacts'][0]['area']);
            $this->assertNotEmpty($guide['impacts']);
            if ($guide['video'] !== null) {
                $page->assertSeeHtml('src="'.$guide['video'].'"');
            }
        }
    }

    public function test_trash_guide_has_a_video_and_is_for_super_admins_and_delete_permission_holders(): void
    {
        $this->signIn(['ViewAny:Document', 'ViewAny:User', 'Update:User']);
        $page = Livewire::test(Panduan::class)->assertDontSeeHtml('id="panduan-hapus-tempat-sampah"');
        $this->assertNotContains('hapus-tempat-sampah', array_column($page->instance()->guides(), 'id'));

        $this->signIn(['ViewAny:Document', 'Delete:Document']);
        $page = Livewire::test(Panduan::class)->assertSeeHtml('id="panduan-hapus-tempat-sampah"');
        $this->assertTrue(collect($page->instance()->guides())->firstWhere('id', 'hapus-tempat-sampah')['canUse']);

        $this->signInAsSuperAdmin();
        $page = Livewire::test(Panduan::class);
        $guide = collect($page->instance()->guides())->firstWhere('id', 'hapus-tempat-sampah');
        $this->assertSame('Hapus & tempat sampah', $guide['title']);
        $this->assertSame('Super admin dan pemegang izin hapus', $guide['audience']);
        $this->assertNotNull($guide['video']);
        $this->assertNotNull($guide['poster']);
        $this->assertSame(TrashLogResource::getUrl(), $guide['action']['url']);
        $page->assertSeeHtml('id="panduan-hapus-tempat-sampah"')
            ->assertSee('Klik ikon Hapus di baris dokumen')
            ->assertSee('Cek Riwayat Penghapusan')
            ->assertDontSee('file-nya');
        $steps = collect($guide['steps'])->pluck('description', 'text');
        $this->assertSame([
            'Klik ikon Hapus di baris dokumen', 'Konfirmasi, data masuk tempat sampah', 'Super admin buka filter Data dihapus',
            'Pulihkan atau Hapus permanen', 'Pulihkan mengembalikan data seperti semula', 'Cek Riwayat Penghapusan',
        ], $steps->keys()->all());
        $this->assertStringStartsWith('Klik ikon Hapus di baris tabel, atau tombol Hapus di halaman detail.', $steps['Klik ikon Hapus di baris dokumen']);
        $this->assertStringContainsString('Hapus terpilih', $steps['Klik ikon Hapus di baris dokumen']);
        $this->assertStringContainsString('pakai Nonaktifkan', $steps['Klik ikon Hapus di baris dokumen']);
        $this->assertStringContainsString('user yang punya tugas dilewati', $steps['Klik ikon Hapus di baris dokumen']);
        // Pengecualian User: pulih dalam keadaan nonaktif, dan hapus permanennya ditolak selama namanya masih tercatat.
        $this->assertStringContainsString('User yang dipulihkan kembali nonaktif; klik tombol Aktifkan kembali di halaman user.', $steps['Pulihkan mengembalikan data seperti semula']);
        $this->assertStringContainsString('ikut menghapus semua versi beserta filenya', $steps['Pulihkan atau Hapus permanen']);
        $this->assertStringContainsString('hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi.', $steps['Pulihkan atau Hapus permanen']);
        $this->assertStringContainsString('Kecuali user: hapus permanen user ditolak selama namanya masih tercatat di data mana pun, termasuk yang di tempat sampah.', $steps['Pulihkan atau Hapus permanen']);
    }

    public function test_features_the_user_can_use_come_first_and_the_rest_are_grouped(): void
    {
        $this->signIn(['ViewAny:DocumentRequest']);

        $page = Livewire::test(Panduan::class)->assertSee('Butuh akses lain');
        $guides = $page->instance()->guides();

        // Urutan mengikuti perjalanan pengguna: masuk dan dasbor dulu, lalu alur pengajuan.
        $this->assertSame(['masuk-aplikasi', 'kenali-dasbor', 'alur-pengajuan', 'ajukan-dokumen', 'proses-pengajuan', 'serahkan-dokumen'], array_column(array_slice($guides, 0, 6), 'id'));
        $this->assertSame([true, true, true, true, true, true, false, false, false, false, false], array_column($guides, 'canUse'));

        $this->signIn(['ViewAny:Document', 'Create:Document', 'ViewAny:DocumentRequest', 'ViewAny:ReminderTemplate']);
        Livewire::test(Panduan::class)->assertDontSee('Butuh akses lain');
    }

    public function test_deactivate_user_guide_is_only_for_super_admins(): void
    {
        $this->signIn(['ViewAny:Document', 'ViewAny:User', 'Update:User']);

        $guides = Livewire::test(Panduan::class)
            ->assertDontSee('Nonaktifkan user')
            ->assertDontSeeHtml('id="panduan-nonaktifkan-user"')
            ->instance()->guides();
        $this->assertCount(11, $guides);
        $this->assertNotContains('nonaktifkan-user', array_column($guides, 'id'));

        $this->signInAsSuperAdmin();
        $guide = collect(Livewire::test(Panduan::class)->assertSeeHtml('id="panduan-nonaktifkan-user"')->instance()->guides())->firstWhere('id', 'nonaktifkan-user');
        $this->assertSame('Super admin', $guide['audience']);
    }

    public function test_document_type_guide_is_only_for_those_who_manage_document_types(): void
    {
        $this->signIn(['ViewAny:DocumentType', 'View:DocumentType', 'ViewAny:DocumentRequest']);
        $page = Livewire::test(Panduan::class)->assertDontSeeHtml('id="panduan-atur-jenis-dokumen"');
        $this->assertNotContains('atur-jenis-dokumen', array_column($page->instance()->guides(), 'id'));

        $this->signIn(['ViewAny:DocumentType', 'Update:DocumentType']);
        $page = Livewire::test(Panduan::class)
            ->assertSeeHtml('id="panduan-atur-jenis-dokumen"')
            ->assertSee('Pilih PIC pengajuan')
            ->assertSee('Tambah isian tambahan');
        $guide = collect($page->instance()->guides())->firstWhere('id', 'atur-jenis-dokumen');
        $this->assertTrue($guide['canUse']);
        $this->assertSame(DocumentTypeResource::getUrl(), $guide['action']['url']);
        $this->assertNotNull($guide['video']);
        $this->assertCount(8, $guide['steps']);
    }

    public function test_company_and_user_guide_is_only_for_admins_who_manage_them(): void
    {
        $this->signIn(['ViewAny:Document', 'ViewAny:User']);
        $this->assertNotContains('kelola-badan-usaha-user', array_column(Livewire::test(Panduan::class)->instance()->guides(), 'id'));

        $this->signIn(['ViewAny:Company', 'Create:Company']);
        $guide = collect(Livewire::test(Panduan::class)->assertSeeHtml('id="panduan-kelola-badan-usaha-user"')->instance()->guides())->firstWhere('id', 'kelola-badan-usaha-user');
        $this->assertTrue($guide['canUse']);
        $this->assertNotNull($guide['video']);
    }

    public function test_video_and_poster_files_exist_for_every_guide(): void
    {
        $this->signInAsSuperAdmin();

        foreach (Livewire::test(Panduan::class)->instance()->guides() as $guide) {
            if ($guide['video'] === null) {
                continue;
            }
            foreach ([$guide['video'], $guide['poster']] as $url) {
                $this->assertFileExists(resource_path(PanduanVideoController::DIRECTORY.'/'.basename((string) parse_url($url, PHP_URL_PATH))), "File panduan {$guide['id']} tidak ada.");
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_video_supports_range_requests_so_clicking_a_step_can_jump_to_its_second(): void
    {
        $this->signIn(['ViewAny:Document']);
        $url = route('panduan.video', ['file' => '01-masuk-aplikasi.mp4']);

        $response = $this->get($url, ['Range' => 'bytes=100-199']);

        $response->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Type', 'video/mp4');
        $this->assertStringStartsWith('bytes 100-199/', (string) $response->headers->get('Content-Range'));
        $this->assertSame('100', $response->headers->get('Content-Length'));
    }

    public function test_videos_are_only_for_signed_in_users_and_unknown_files_are_not_found(): void
    {
        $this->get(route('panduan.video', ['file' => '01-masuk-aplikasi.mp4']))->assertRedirect();

        $this->signIn(['ViewAny:Document']);
        $this->get(route('panduan.video', ['file' => '99-tidak-ada.mp4']))->assertNotFound();
        $this->get('/admin/panduan/video/..%2F..%2F.env')->assertNotFound();
    }

    public function test_step_times_start_at_zero_and_increase_so_the_list_follows_the_video(): void
    {
        $this->signInAsSuperAdmin();

        foreach (Livewire::test(Panduan::class)->instance()->guides() as $guide) {
            if ($guide['video'] === null) {
                continue;
            }
            $times = array_column($guide['steps'], 'at');
            $this->assertSame(0.0, $times[0], "Langkah pertama {$guide['id']} harus mulai di detik 0.");
            $sorted = $times;
            sort($sorted);
            $this->assertSame($sorted, $times, "Detik langkah {$guide['id']} harus urut.");
            $this->assertSame(count($times), count(array_unique($times)), "Detik langkah {$guide['id']} tidak boleh ganda.");
            foreach ($guide['steps'] as $step) {
                $this->assertNotSame('', trim($step['description']), "Langkah \"{$step['text']}\" belum punya deskripsi.");
            }
        }
    }

    public function test_links_to_pages_the_user_cannot_open_are_hidden(): void
    {
        $this->signIn(['ViewAny:Document']);

        Livewire::test(Panduan::class)
            ->assertSeeHtml('href="'.DocumentResource::getUrl().'"')
            ->assertDontSeeHtml('href="'.ReminderTemplateResource::getUrl().'"')
            ->assertDontSeeHtml('href="'.UserResource::getUrl().'"')
            ->assertSee('Butuh hak akses tambahan');
    }

    public function test_links_appear_once_the_user_has_access(): void
    {
        $this->signIn(['ViewAny:Document', 'Create:Document', 'ViewAny:ReminderTemplate']);

        Livewire::test(Panduan::class)
            ->assertSeeHtml('href="'.ReminderTemplateResource::getUrl().'"')
            ->assertSeeHtml('href="'.DocumentResource::getUrl('create').'"')
            ->assertSeeHtml('href="'.DocumentResource::getUrl('import').'"');
    }

    public function test_public_request_form_link_opens_in_a_new_tab_for_everyone(): void
    {
        $this->signIn(['ViewAny:Document']);

        Livewire::test(Panduan::class)
            ->assertSeeHtml('href="'.route('requests.public.create').'"')
            ->assertSeeHtml('target="_blank"');
    }
}
