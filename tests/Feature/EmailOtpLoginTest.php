<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\EmailLogin;
use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\EmailOtpService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.waghub.url' => null,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'noreply@example.test',
        ]);

        Mail::fake();
    }

    public function test_login_page_offers_email_when_the_mailer_sends_real_email(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSeeInOrder(['Atau masuk dengan', 'Email'])
            ->assertSee(route('email-login'), false)
            ->assertDontSee(route('phone-login'), false);
    }

    public function test_login_page_shows_whatsapp_and_email_side_by_side(): void
    {
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSeeInOrder(['Atau masuk dengan', 'WhatsApp', 'Email'])
            ->assertSee(route('phone-login'), false)
            ->assertSee(route('email-login'), false);
    }

    public function test_email_is_hidden_and_its_pages_redirect_when_the_mailer_only_logs_or_stores_messages(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->get(route('filament.admin.auth.login'))
                ->assertOk()
                ->assertDontSee('Atau masuk dengan')
                ->assertDontSee(route('email-login'), false);
            $this->get(route('email-login'))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
            $this->get(route('email-login.link', ['token' => Str::random(64)]))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        }
    }

    public function test_email_login_routes_are_open_for_guests_on_the_web_middleware(): void
    {
        foreach (['email-login', 'email-login.link'] as $name) {
            $this->assertContains('web', app('router')->getRoutes()->getByName($name)->middleware());
        }

        $this->get(route('email-login'))
            ->assertOk()
            ->assertSee('Masuk dengan Email')
            ->assertSee('Kirim Kode ke Email');
    }

    public function test_user_can_request_a_code_by_email_and_login_with_it(): void
    {
        $user = $this->panelUser('Staf.Legal@Example.test');

        $component = Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf.legal@example.TEST'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.email', 'staf.legal@example.test')
            ->assertSee('Verifikasi dan Masuk');

        $mail = $this->sentLoginMail('staf.legal@example.test');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $mail->otp);
        $this->assertSame($user->name, $mail->name);
        $this->assertSame(10, $mail->expiresInMinutes);

        $otp = EmailOtp::sole();
        $this->assertSame(EmailOtp::PURPOSE_LOGIN, $otp->purpose);
        $this->assertNotSame($mail->otp, $otp->otp_hash);
        $this->assertNotSame($this->tokenFrom($mail), $otp->token_hash);

        $component
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_magic_link_logs_in_without_typing_the_code(): void
    {
        $user = $this->panelUser();
        $mail = $this->requestCode();
        $token = $this->tokenFrom($mail);

        $this->assertSame(route('email-login.link', ['token' => $token]), $mail->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->assertSee('Memproses link masuk')
            ->assertSeeHtml('wire:init="consumeMagicLink"')
            ->call('consumeMagicLink')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        $this->assertSame($user->id, Auth::id());
    }

    public function test_opening_the_magic_link_url_does_not_consume_it(): void
    {
        $this->panelUser();
        $token = $this->tokenFrom($this->requestCode());

        // Pemindai link di email hanya melakukan GET; token baru dipakai saat browser menjalankan wire:init.
        $this->get(route('email-login.link', ['token' => $token]))->assertOk();

        $this->assertGuest();
        $this->assertNull(EmailOtp::sole()->verified_at);
    }

    public function test_magic_link_and_code_share_one_use(): void
    {
        $this->panelUser();
        $mail = $this->requestCode();

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertRedirect(Filament::getPanel('admin')->getUrl());

        Auth::logout();

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertSet('magicToken', null)
            ->assertNoRedirect();

        $this->verifyCode($mail->otp)->assertHasErrors(['data.otp'])->assertNoRedirect();
        $this->assertGuest();
    }

    public function test_expired_or_unknown_magic_link_is_rejected(): void
    {
        $this->panelUser();
        $token = $this->tokenFrom($this->requestCode());

        EmailOtp::query()->update(['expires_at' => now()->subSecond()]);

        foreach ([$token, Str::random(64)] as $candidate) {
            Livewire::test(EmailLogin::class, ['token' => $candidate])
                ->call('consumeMagicLink')
                ->assertHasErrors(['data.email'])
                ->assertNoRedirect();
        }

        $this->assertGuest();
    }

    public function test_magic_link_of_a_deactivated_user_is_rejected(): void
    {
        $user = $this->panelUser();
        $token = $this->tokenFrom($this->requestCode());

        $user->forceFill(['deactivated_at' => now()])->save();

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_wrong_code_is_rejected_and_exhausts_after_max_attempts(): void
    {
        $this->panelUser();
        $mail = $this->requestCode();
        $wrongCode = $mail->otp === '000000' ? '111111' : '000000';

        $component = Livewire::test(EmailLogin::class)
            ->set('awaitingOtp', true)
            ->set('data.email', 'staf@example.test');

        for ($attempt = 1; $attempt <= EmailOtpService::MAX_ATTEMPTS; $attempt++) {
            $component->set('data.otp', $wrongCode)->call('verify')->assertHasErrors(['data.otp']);
        }

        $component->set('data.otp', $mail->otp)->call('verify')->assertHasErrors(['data.otp']);

        // Kode yang habis percobaannya juga mematikan magic link pada record yang sama.
        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_requesting_a_new_code_invalidates_the_previous_code_and_link(): void
    {
        $this->panelUser();
        $first = $this->requestCode();
        $second = $this->requestCode();

        $this->assertNotSame($first->loginUrl, $second->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($first)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($second)])
            ->call('consumeMagicLink')
            ->assertRedirect(Filament::getPanel('admin')->getUrl());
    }

    public function test_changing_the_email_expires_pending_codes(): void
    {
        $user = $this->panelUser();
        $mail = $this->requestCode();

        $user->update(['email' => 'alamat.baru@example.test']);

        $this->assertTrue(EmailOtp::sole()->expires_at->lte(now()));

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_unknown_unauthorized_deactivated_or_invalid_email_cannot_request_a_code(): void
    {
        User::factory()->create(['email' => 'tanpa-akses@example.test']);
        $this->panelUser('nonaktif@example.test')->forceFill(['deactivated_at' => now()])->save();

        foreach (['tidak-dikenal@example.test', 'tanpa-akses@example.test', 'nonaktif@example.test'] as $email) {
            Livewire::test(EmailLogin::class)
                ->fillForm(['email' => $email])
                ->call('send')
                ->assertHasErrors(['data.email'])
                ->assertSee('Email belum terdaftar untuk login.')
                ->assertSet('awaitingOtp', false);
        }

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'bukan-email'])
            ->call('send')
            ->assertHasErrors(['data.email']);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('email_otps', 0);
    }

    public function test_send_requests_are_limited_per_email(): void
    {
        $this->panelUser();

        for ($request = 1; $request <= 3; $request++) {
            $this->requestCode();
        }

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasErrors(['data.email'])
            ->assertSet('awaitingOtp', false);

        Mail::assertSentCount(3);
    }

    public function test_failed_delivery_expires_the_code_and_shows_a_generic_error(): void
    {
        $this->panelUser();

        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'staf@example.test'])
            ->call('send')
            ->assertHasErrors(['data.email'])
            ->assertSee('Email belum bisa dikirim')
            ->assertSet('awaitingOtp', false);

        $this->assertTrue(EmailOtp::sole()->expires_at->lte(now()));
    }

    public function test_login_mail_contains_the_code_and_the_magic_link(): void
    {
        $loginUrl = 'http://lms.test/email-login/link/'.str_repeat('a', 64);
        $html = (new EmailLoginMail(name: 'Staf Legal', otp: '482913', loginUrl: $loginUrl, expiresInMinutes: 10))->render();

        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString($loginUrl, $html);
        $this->assertStringContainsString('10 menit', $html);

        // Tampilan seperti kartu halaman login Mekaya: ikon, judul, kotak kode, tombol primary, footer berbahasa Indonesia.
        $this->assertStringContainsString(asset('images/mail/envelope.png'), $html);
        $this->assertStringContainsString('Masuk ke LMS Legal', $html);
        $this->assertStringContainsString('class="code-box"', $html);
        $this->assertStringContainsString('background-color: #155dfc', $html);
        $this->assertStringContainsString('Hak cipta dilindungi.', $html);
        $this->assertFileExists(public_path('images/mail/envelope.png'));
    }

    public function test_old_codes_are_pruned(): void
    {
        $old = EmailOtp::factory()->create(['created_at' => now()->subDays(8)]);
        $recent = EmailOtp::factory()->create();

        $this->artisan('model:prune', ['--model' => [EmailOtp::class]])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    private function panelUser(string $email = 'staf@example.test'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Document', 'web'));

        return $user;
    }

    private function requestCode(string $email = 'staf@example.test'): EmailLoginMail
    {
        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => $email])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);

        return $this->sentLoginMail($email);
    }

    private function verifyCode(string $code): mixed
    {
        return Livewire::test(EmailLogin::class)
            ->set('awaitingOtp', true)
            ->set('data.email', 'staf@example.test')
            ->set('data.otp', $code)
            ->call('verify');
    }

    private function sentLoginMail(string $email): EmailLoginMail
    {
        $sent = Mail::sent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo($email));

        $this->assertNotEmpty($sent, "Tidak ada email login ke {$email}.");

        return $sent->last();
    }

    private function tokenFrom(EmailLoginMail $mail): string
    {
        return Str::afterLast($mail->loginUrl, '/');
    }
}
