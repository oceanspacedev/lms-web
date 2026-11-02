<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsAppOtpService;
use Filament\Facades\Filament;
use Filament\Support\Colors\ColorManager;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhoneOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp']);
        config(['mail.default' => 'log']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => Http::response(['status' => 'accepted'], (int) config('testing.waghub_status', 202))]);
    }

    public function test_login_page_offers_whatsapp_under_the_password_form(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSeeInOrder(['Atau masuk dengan', 'WhatsApp'])
            ->assertSee(route('phone-login'), false);
    }

    public function test_whatsapp_is_hidden_and_its_page_redirects_when_waghub_is_not_configured(): void
    {
        config(['services.waghub.token' => null]);

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertDontSee('Atau masuk dengan')
            ->assertDontSee(route('phone-login'), false);
        $this->get(route('phone-login'))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    public function test_phone_login_page_is_open_for_guests_on_the_web_middleware(): void
    {
        $this->assertContains('web', app('router')->getRoutes()->getByName('phone-login')->middleware());

        $this->get(route('phone-login'))
            ->assertOk()
            ->assertSee('Masuk dengan WhatsApp')
            ->assertSee('Nomor WhatsApp')
            ->assertSee('Kirim OTP WhatsApp');
    }

    public function test_user_can_request_an_otp_and_login_with_it(): void
    {
        $user = $this->panelUser();

        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '0812-3456-7890'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.whatsapp_number', '6281234567890')
            ->assertSee('Verifikasi dan Masuk')
            ->assertDontSee('Kirim OTP WhatsApp');

        Http::assertSent(fn ($request): bool => $request['recipient']['value'] === '6281234567890'
            && $request['purpose'] === 'test-otp'
            && str_contains($request['message']['text'], 'Kode OTP LMS Legal Anda: ')
            && str_contains($request['message']['text'], 'Berlaku 5 menit')
            && $request->header('Idempotency-Key')[0] === 'lms-login-'.WhatsappOtp::sole()->id);

        $otp = WhatsappOtp::sole();
        $this->assertSame($user->id, $otp->user_id);
        $this->assertSame(WhatsappOtp::PURPOSE_LOGIN, $otp->purpose);
        $this->assertNotSame($this->sentCode(), $otp->otp_hash);
        $this->assertTrue(Hash::check($this->sentCode(), $otp->otp_hash));

        $component
            ->set('data.otp', $this->sentCode())
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_livewire_updates_keep_the_admin_panel_and_its_button_colours(): void
    {
        $this->panelUser();

        $component = Livewire::test(PhoneLogin::class)
            ->assertSee('Kirim OTP WhatsApp')
            ->assertSeeHtml('fi-color-primary');

        // Request Livewire (/livewire/update) berada di luar route panel: tanpa panel aktif, tombol memakai warna bawaan.
        $this->simulateRequestWithoutPanel();

        $component
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSee('Verifikasi dan Masuk')
            ->assertSeeHtml('fi-color-primary');

        $this->assertSame('admin', Filament::getCurrentPanel()?->getId());
    }

    public function test_wrong_otp_counts_an_attempt_and_the_correct_otp_still_logs_in(): void
    {
        $user = $this->panelUser();
        $component = $this->requestOtp();

        $component->set('data.otp', $this->wrongCode())->call('verify')->assertHasFormErrors(['otp']);

        $this->assertGuest();
        $this->assertSame(1, WhatsappOtp::sole()->attempt_count);

        $component->set('data.otp', $this->sentCode())->call('verify')->assertHasNoFormErrors();

        $this->assertSame($user->id, Auth::id());
    }

    public function test_expired_or_exhausted_otp_is_rejected(): void
    {
        $user = $this->panelUser();
        $otp = WhatsappOtp::factory()->for($user)->create(['otp_hash' => Hash::make('123456'), 'expires_at' => now()->subSecond()]);

        $this->verifyOtp('123456')->assertHasFormErrors(['otp']);

        $otp->update(['expires_at' => now()->addMinute(), 'attempt_count' => WhatsAppOtpService::MAX_ATTEMPTS]);

        $this->verifyOtp('123456')->assertHasFormErrors(['otp']);
        $this->assertGuest();
    }

    public function test_fifth_wrong_attempt_burns_the_otp(): void
    {
        $this->panelUser();
        $component = $this->requestOtp();

        for ($attempt = 1; $attempt <= WhatsAppOtpService::MAX_ATTEMPTS; $attempt++) {
            $component->set('data.otp', $this->wrongCode())->call('verify')->assertHasFormErrors(['otp']);
        }

        $component->set('data.otp', $this->sentCode())->call('verify')->assertHasFormErrors(['otp']);
        $this->assertTrue(WhatsappOtp::sole()->expires_at->lte(now()));
        $this->assertGuest();
    }

    public function test_a_verified_otp_can_only_be_used_once(): void
    {
        $user = $this->panelUser();
        WhatsappOtp::factory()->for($user)->create(['otp_hash' => Hash::make('123456')]);
        $service = app(WhatsAppOtpService::class);

        $this->assertNotNull($service->verify($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN, '123456'));
        $this->assertNull($service->verify($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN, '123456'));
    }

    public function test_requesting_a_new_otp_expires_the_previous_one(): void
    {
        $user = $this->panelUser();
        $service = app(WhatsAppOtpService::class);

        $first = $service->issue($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN);
        $second = $service->issue($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN);

        $this->assertTrue($first['otp_record']->fresh()->expires_at->lte(now()));
        $this->assertTrue($second['otp_record']->fresh()->expires_at->isFuture());
    }

    public function test_changing_the_phone_number_expires_pending_otps(): void
    {
        $user = $this->panelUser();
        $otp = WhatsappOtp::factory()->for($user)->create();

        $user->update(['phone' => '6281398765432']);

        $this->assertTrue($otp->fresh()->expires_at->lte(now()));
    }

    public function test_unknown_shared_unauthorized_or_deactivated_numbers_cannot_request_an_otp(): void
    {
        User::factory()->create(['phone' => '6281111111111']);
        $this->panelUser('6282222222222');
        $this->panelUser('6282222222222');
        User::factory()->deactivated()->create(['phone' => '6283333333333'])->givePermissionTo(Permission::findOrCreate('ViewAny:Document', 'web'));

        foreach (['081299999999', '081111111111', '082222222222', '083333333333'] as $number) {
            Livewire::test(PhoneLogin::class)
                ->fillForm(['whatsapp_number' => $number])
                ->call('send')
                ->assertHasFormErrors(['whatsapp_number'])
                ->assertSee('Nomor WhatsApp belum terdaftar untuk login.')
                ->assertSet('awaitingOtp', false);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_otps', 0);
    }

    public function test_invalid_number_is_rejected(): void
    {
        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '12345'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number'])
            ->assertSee('Nomor WhatsApp tidak valid.');

        Http::assertNothingSent();
    }

    public function test_phone_input_only_accepts_digits_in_the_browser(): void
    {
        Livewire::test(PhoneLogin::class)
            ->assertSeeHtml('x-mask:dynamic')
            ->assertSeeHtml('+99999999999999')
            ->assertSeeHtml('inputmode="tel"');
    }

    public function test_send_requests_are_limited_per_number(): void
    {
        $this->panelUser();

        for ($request = 1; $request <= 3; $request++) {
            $this->requestOtp();
        }

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number'])
            ->assertSee('Terlalu banyak permintaan OTP');

        $this->assertDatabaseCount('whatsapp_otps', 3);
    }

    public function test_waghub_failure_expires_the_otp_and_shows_a_generic_error(): void
    {
        $this->panelUser();
        config(['testing.waghub_status' => 500]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number'])
            ->assertSee('OTP belum bisa dikirim ke WhatsApp')
            ->assertSet('awaitingOtp', false);

        $this->assertTrue(WhatsappOtp::sole()->expires_at->lte(now()));

        config(['testing.waghub_status' => 202]);
        $this->requestOtp();
    }

    public function test_change_phone_returns_to_the_first_step(): void
    {
        $this->panelUser();

        $this->requestOtp()
            ->call('changePhone')
            ->assertSet('awaitingOtp', false)
            ->assertSet('data.whatsapp_number', null)
            ->assertSee('Kirim OTP WhatsApp');
    }

    public function test_authenticated_user_is_redirected_away(): void
    {
        $this->actingAs($this->panelUser());

        Livewire::test(PhoneLogin::class)->assertRedirect(Filament::getPanel('admin')->getUrl());
    }

    public function test_phone_must_be_unique_in_profile_and_user_form(): void
    {
        $this->panelUser('6281234567890');
        $admin = $this->panelUser('6289999999999');
        $admin->givePermissionTo([Permission::findOrCreate('ViewAny:User', 'web'), Permission::findOrCreate('Create:User', 'web')]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditProfile::class)->fillForm(['phone' => '0812 3456 7890'])->call('save')->assertHasFormErrors(['phone']);
        Livewire::test(EditProfile::class)->fillForm(['phone' => '0899 9999 9999'])->call('save')->assertHasNoFormErrors();
        Livewire::test(CreateUser::class)->fillForm(['name' => 'Baru', 'email' => 'baru@example.test', 'phone' => '+6281234567890', 'password' => 'rahasia-panjang-123'])
            ->call('create')->assertHasFormErrors(['phone']);
    }

    public function test_old_otps_are_pruned(): void
    {
        $old = WhatsappOtp::factory()->create(['created_at' => now()->subDays(8)]);
        $recent = WhatsappOtp::factory()->create();

        $this->artisan('model:prune', ['--model' => [WhatsappOtp::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    private function panelUser(string $phone = '6281234567890'): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Document', 'web'));

        return $user;
    }

    private function requestOtp(string $number = '081234567890'): mixed
    {
        return Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => $number])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);
    }

    private function verifyOtp(string $code): mixed
    {
        return Livewire::test(PhoneLogin::class)
            ->set('awaitingOtp', true)
            ->set('data.whatsapp_number', '081234567890')
            ->set('data.otp', $code)
            ->call('verify');
    }

    private function sentCode(): string
    {
        preg_match('/\b(\d{6})\b/', Http::recorded()->last()[0]['message']['text'], $match);

        return $match[1];
    }

    private function wrongCode(): string
    {
        return $this->sentCode() === '000000' ? '111111' : '000000';
    }

    /**
     * Kembalikan Filament ke keadaan request baru yang tidak melewati middleware panel.
     */
    private function simulateRequestWithoutPanel(): void
    {
        Filament::setCurrentPanel(null);
        (function (): void {
            $this->isCurrentPanelBooted = false;
        })->call(Filament::getFacadeRoot());

        $this->app->instance(ColorManager::class, new ColorManager);
        FilamentColor::clearResolvedInstance(ColorManager::class);
    }
}
