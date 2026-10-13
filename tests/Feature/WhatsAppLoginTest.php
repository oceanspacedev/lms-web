<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\WhatsAppLogin;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\WhatsAppLoginService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WhatsAppLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => Http::response(['status' => 'accepted'], (int) config('testing.waghub_status', 202))]);
    }

    private function panelUser(string $phone = '6281234567890'): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Document', 'web'));

        return $user;
    }

    private function sentCode(): string
    {
        $request = Http::recorded()->last()[0];
        preg_match('/\b(\d{6})\b/', $request['message']['text'], $match);

        return $match[1];
    }

    private function requestCode(string $phone = '0812-3456-7890')
    {
        return Livewire::test(WhatsAppLogin::class)->fillForm(['phone' => $phone])->call('submit');
    }

    public function test_login_page_links_to_whatsapp_login_and_page_is_open_for_guests(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertSee('Masuk dengan kode WhatsApp')->assertSee(route('filament.admin.auth.whatsapp-login'), false);
        $this->get(route('filament.admin.auth.whatsapp-login'))->assertOk()->assertSee('Masuk dengan WhatsApp')->assertSee('Nomor WhatsApp');
    }

    public function test_code_is_sent_to_normalized_number_and_only_its_hash_is_stored(): void
    {
        $user = $this->panelUser();

        $this->requestCode('+62 812-3456-7890')->assertHasNoFormErrors()->assertSet('step', 'code')->assertSee('0812');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://waghub.test/api/v1/messages'
            && $request['recipient']['value'] === '6281234567890' && $request['purpose'] === 'test-otp'
            && preg_match('/Kode masuk LMS Legal: \d{6}\. Berlaku 5 menit\./', $request['message']['text']) === 1
            && $request->header('Idempotency-Key')[0] === 'lms-login-'.LoginCode::first()->id);
        $code = $this->sentCode();
        $loginCode = LoginCode::sole();
        $this->assertSame($user->id, $loginCode->user_id);
        $this->assertSame('sent', $loginCode->status);
        $this->assertSame(LoginCode::hashCode($code), $loginCode->code_hash);
        $this->assertStringNotContainsString($code, json_encode($loginCode->getAttributes()));
        $this->assertTrue($loginCode->expires_at->equalTo(now()->addMinutes(5)->startOfSecond()) || $loginCode->expires_at->diffInSeconds(now()->addMinutes(5)) < 2);
    }

    public function test_correct_code_logs_in_once_and_redirects_to_panel(): void
    {
        $user = $this->panelUser();
        $component = $this->requestCode();
        $code = $this->sentCode();

        $component->fillForm(['code' => $code])->call('submit')->assertHasNoFormErrors()->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($user);
        $this->assertSame('used', LoginCode::sole()->status);
        $this->assertNotNull(LoginCode::sole()->consumed_at);

        try {
            app(WhatsAppLoginService::class)->verifyCode('081234567890', $code);
            $this->fail('Kode yang sudah dipakai tidak boleh berlaku lagi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
    }

    public function test_step_and_phone_cannot_be_tampered_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(WhatsAppLogin::class)->set('step', 'code');
    }

    public function test_unknown_shared_or_unauthorized_numbers_get_the_same_response_without_sending(): void
    {
        User::factory()->create(['phone' => '6281111111111']);
        $this->panelUser('6282222222222');
        $this->panelUser('6282222222222');

        foreach (['081299999999', '081111111111', '082222222222'] as $phone) {
            $this->requestCode($phone)->assertHasNoFormErrors()->assertSet('step', 'code')->assertSee('kode 6 digit sudah dikirim');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('login_codes', 0);
    }

    public function test_phone_fields_only_accept_digits_in_the_browser(): void
    {
        Livewire::test(WhatsAppLogin::class)->assertSeeHtml('x-mask:dynamic')->assertSeeHtml('+99999999999999')->assertSeeHtml('inputmode="tel"');

        $admin = $this->panelUser('6289999999999');
        $admin->givePermissionTo([Permission::findOrCreate('ViewAny:User', 'web'), Permission::findOrCreate('Create:User', 'web')]);
        $this->actingAs($admin);
        Livewire::test(EditProfile::class)->assertSeeHtml('+99999999999999');
        Livewire::test(CreateUser::class)->assertSeeHtml('+99999999999999');
    }

    public function test_invalid_number_is_rejected(): void
    {
        $this->requestCode('12345')->assertHasFormErrors(['phone'])->assertSet('step', 'phone');
        Http::assertNothingSent();
    }

    public function test_wrong_codes_count_attempts_and_fifth_attempt_burns_the_code(): void
    {
        $this->panelUser();
        $component = $this->requestCode();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 4; $i++) {
            $component->fillForm(['code' => $wrong])->call('submit')->assertHasFormErrors(['code']);
            $this->assertSame($i, LoginCode::sole()->attempts);
        }
        $component->fillForm(['code' => $wrong])->call('submit')->assertHasFormErrors(['code']);
        $this->assertSame('expired', LoginCode::sole()->status);

        $component->fillForm(['code' => $code])->call('submit')->assertHasFormErrors(['code']);
        $this->assertGuest();
    }

    public function test_code_field_accepts_only_six_digits(): void
    {
        $this->panelUser();
        $component = $this->requestCode()->assertSeeHtml('inputmode="numeric"')->assertSeeHtml('x-mask');

        $component->fillForm(['code' => '12ab56'])->call('submit')->assertHasFormErrors(['code' => 'regex']);
        $component->fillForm(['code' => '123'])->call('submit')->assertHasFormErrors(['code']);

        $this->assertSame(0, LoginCode::sole()->attempts);
        $this->assertGuest();
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->panelUser();
        $component = $this->requestCode();
        $code = $this->sentCode();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $component->fillForm(['code' => $code])->call('submit')->assertHasFormErrors(['code']);
        $this->assertGuest();
    }

    public function test_resend_waits_sixty_seconds_and_replaces_the_previous_code(): void
    {
        $this->panelUser();
        $component = $this->requestCode();
        $first = $this->sentCode();

        $component->callAction('resendCode');
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $component->callAction('resendCode');
        Http::assertSentCount(2);
        $second = $this->sentCode();

        $this->assertSame(['expired', 'sent'], LoginCode::orderBy('id')->pluck('status')->all());
        if ($first !== $second) {
            $component->fillForm(['code' => $first])->call('submit')->assertHasFormErrors(['code']);
        }
        $component->fillForm(['code' => $second])->call('submit')->assertHasNoFormErrors();
        $this->assertAuthenticated();
    }

    public function test_number_is_limited_to_five_codes_per_hour(): void
    {
        $this->panelUser();

        for ($i = 0; $i < 5; $i++) {
            app(WhatsAppLoginService::class)->requestCode('081234567890', '10.0.0.'.$i);
            $this->travel(61)->seconds();
        }

        $this->requestCode('081234567890')->assertHasFormErrors(['phone']);
        Http::assertSentCount(5);
    }

    public function test_ip_is_limited_to_ten_requests_per_ten_minutes(): void
    {
        for ($i = 0; $i < 10; $i++) {
            app(WhatsAppLoginService::class)->requestCode('0812000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), '10.1.1.1');
        }

        $this->expectException(ValidationException::class);
        app(WhatsAppLoginService::class)->requestCode('081200000099', '10.1.1.1');
    }

    public function test_waghub_failure_is_recorded_and_shown_and_allows_retry(): void
    {
        $this->panelUser();
        config(['testing.waghub_status' => 500]);

        $this->requestCode()->assertHasFormErrors(['phone'])->assertSet('step', 'phone');

        $loginCode = LoginCode::sole();
        $this->assertSame('failed', $loginCode->status);
        $this->assertStringContainsString('HTTP 500', $loginCode->error_message);

        config(['testing.waghub_status' => 202]);
        $this->requestCode()->assertHasNoFormErrors()->assertSet('step', 'code');
    }

    public function test_remember_me_sets_the_remember_cookie(): void
    {
        $user = $this->panelUser();
        $component = $this->requestCode();

        $component->fillForm(['code' => $this->sentCode(), 'remember' => true])->call('submit')->assertHasNoFormErrors();

        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_change_number_returns_to_the_first_step(): void
    {
        $this->panelUser();

        $this->requestCode()->callAction('changeNumber')->assertSet('step', 'phone')->assertSet('phone', null);
    }

    public function test_authenticated_user_is_redirected_away(): void
    {
        $this->actingAs($this->panelUser());

        Livewire::test(WhatsAppLogin::class)->assertRedirect(Filament::getUrl());
    }

    public function test_phone_must_be_unique_in_profile_and_user_form(): void
    {
        $this->panelUser('6281234567890');
        $admin = $this->panelUser('6289999999999');
        $admin->givePermissionTo([Permission::findOrCreate('ViewAny:User', 'web'), Permission::findOrCreate('Create:User', 'web')]);
        $this->actingAs($admin);

        Livewire::test(EditProfile::class)->fillForm(['phone' => '0812 3456 7890'])->call('save')->assertHasFormErrors(['phone']);
        Livewire::test(EditProfile::class)->fillForm(['phone' => '0899 9999 9999'])->call('save')->assertHasNoFormErrors();
        Livewire::test(CreateUser::class)->fillForm(['name' => 'Baru', 'email' => 'baru@example.test', 'phone' => '+6281234567890', 'password' => 'rahasia-panjang-123'])
            ->call('create')->assertHasFormErrors(['phone']);
    }

    public function test_old_codes_are_pruned(): void
    {
        $old = LoginCode::factory()->create(['created_at' => now()->subDays(8)]);
        $recent = LoginCode::factory()->create();

        $this->artisan('model:prune', ['--model' => [LoginCode::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
