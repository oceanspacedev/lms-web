<?php

namespace Tests\Feature;

use App\Filament\Pages\Panduan;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
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

        $this->assertCount(8, $guides);
        foreach ($guides as $guide) {
            $page->assertSee($guide['title'])
                ->assertSeeHtml('id="panduan-'.$guide['id'].'"')
                ->assertSeeHtml('src="'.$guide['video'].'"')
                ->assertSee($guide['steps'][0]['text'])
                ->assertSee($guide['steps'][0]['description'])
                ->assertSee($guide['impacts'][0]['area']);
            $this->assertNotEmpty($guide['impacts']);
        }
    }

    public function test_features_the_user_can_use_come_first_and_the_rest_are_grouped(): void
    {
        $this->signIn(['ViewAny:DocumentRequest']);

        $page = Livewire::test(Panduan::class)->assertSee('Butuh akses lain');
        $guides = $page->instance()->guides();

        $this->assertSame(['ajukan-dokumen', 'proses-pengajuan'], array_column(array_slice($guides, 0, 2), 'id'));
        $this->assertSame([true, true, false, false, false, false, false], array_column($guides, 'canUse'));

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
        $this->assertCount(7, $guides);
        $this->assertNotContains('nonaktifkan-user', array_column($guides, 'id'));

        $this->signInAsSuperAdmin();
        $guide = collect(Livewire::test(Panduan::class)->assertSeeHtml('id="panduan-nonaktifkan-user"')->instance()->guides())->firstWhere('id', 'nonaktifkan-user');
        $this->assertSame('Super admin', $guide['audience']);
    }

    public function test_video_and_poster_files_exist_for_every_guide(): void
    {
        $this->signInAsSuperAdmin();

        foreach (Livewire::test(Panduan::class)->instance()->guides() as $guide) {
            foreach ([$guide['video'], $guide['poster']] as $url) {
                $this->assertFileExists(resource_path(PanduanVideoController::DIRECTORY.'/'.basename((string) parse_url($url, PHP_URL_PATH))), "File panduan {$guide['id']} tidak ada.");
                $this->get($url)->assertOk();
            }
        }
    }

    public function test_video_supports_range_requests_so_clicking_a_step_can_jump_to_its_second(): void
    {
        $this->signIn(['ViewAny:Document']);
        $url = route('panduan.video', ['file' => '01-tambah-dokumen.mp4']);

        $response = $this->get($url, ['Range' => 'bytes=100-199']);

        $response->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Type', 'video/mp4');
        $this->assertStringStartsWith('bytes 100-199/', (string) $response->headers->get('Content-Range'));
        $this->assertSame('100', $response->headers->get('Content-Length'));
    }

    public function test_videos_are_only_for_signed_in_users_and_unknown_files_are_not_found(): void
    {
        $this->get(route('panduan.video', ['file' => '01-tambah-dokumen.mp4']))->assertRedirect();

        $this->signIn(['ViewAny:Document']);
        $this->get(route('panduan.video', ['file' => '99-tidak-ada.mp4']))->assertNotFound();
        $this->get('/admin/panduan/video/..%2F..%2F.env')->assertNotFound();
    }

    public function test_step_times_start_at_zero_and_increase_so_the_list_follows_the_video(): void
    {
        $this->signInAsSuperAdmin();

        foreach (Livewire::test(Panduan::class)->instance()->guides() as $guide) {
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
