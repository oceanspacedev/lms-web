<?php

namespace Tests\Feature;

use App\Filament\Auth\WhatsAppLogin;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\WhatsAppLoginService;
use DOMDocument;
use DOMElement;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WhatsAppLoginOtpUxTest extends TestCase
{
    use RefreshDatabase;

    private const HINT = 'Tidak menerima kode? Pastikan nomor sama dengan nomor di profil Anda, tunggu beberapa saat, lalu kirim ulang atau masuk dengan email dan password.';

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    private function panelUser(string $phone = '6281234567890'): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->givePermissionTo(Permission::findOrCreate('ViewAny:Document', 'web'));

        return $user;
    }

    private function requestCode(string $phone = '0812-3456-7890'): Testable
    {
        return Livewire::test(WhatsAppLogin::class)->fillForm(['phone' => $phone])->call('submit');
    }

    private function wrongCode(Testable $component): Testable
    {
        return $component->fillForm(['code' => '000000'])->call('submit');
    }

    private function resendButton(Testable $component): ?DOMElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('button') as $button) {
            if (str_contains($button->getAttribute('wire:click'), "mountAction('resendCode'")) {
                return $button;
            }
        }

        return null;
    }

    private function resendLabel(DOMElement $button): string
    {
        return preg_replace('/\s+/', ' ', trim($button->textContent));
    }

    public function test_code_step_seeds_the_resend_countdown_from_the_server_and_starts_disabled(): void
    {
        $this->freezeTime();
        $this->panelUser();

        $button = $this->resendButton($this->requestCode()->assertSet('step', 'code'));

        $this->assertNotNull($button);
        $this->assertSame('Kirim ulang kode (60)', $this->resendLabel($button));
        $this->assertStringContainsString('endsAt: Date.now() + '.WhatsAppLoginService::RESEND_SECONDS.' * 1000', $button->getAttribute('x-data'));
        $this->assertSame('secondsLeft > 0', $button->getAttribute('x-bind:disabled'));
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertSame('button', $button->getAttribute('type'));
        $this->assertStringContainsString('$event.detail.seconds', $button->getAttribute('x-on:whatsapp-resend-countdown.window'));
    }

    public function test_countdown_is_the_same_for_unknown_shared_and_unauthorized_numbers(): void
    {
        $this->freezeTime();
        User::factory()->create(['phone' => '6281111111111']);
        $this->panelUser('6282222222222');
        $this->panelUser('6282222222222');
        $this->panelUser('6283333333333');

        foreach (['081299999999', '081111111111', '082222222222', '083333333333'] as $phone) {
            $component = $this->requestCode($phone)->assertSet('step', 'code')->assertSee(self::HINT);
            $button = $this->resendButton($component);

            $this->assertSame('Kirim ulang kode (60)', $this->resendLabel($button), $phone);
            $this->assertTrue($button->hasAttribute('disabled'), $phone);
        }
    }

    public function test_rerender_during_the_countdown_reseeds_with_the_remaining_seconds(): void
    {
        $this->freezeTime();
        $this->panelUser();
        $component = $this->requestCode();

        $this->travel(20)->seconds();
        $button = $this->resendButton($this->wrongCode($component)->assertHasFormErrors(['code']));

        $this->assertSame('Kirim ulang kode (40)', $this->resendLabel($button));
        $this->assertStringContainsString('endsAt: Date.now() + 40 * 1000', $button->getAttribute('x-data'));
        $this->assertTrue($button->hasAttribute('disabled'));
    }

    public function test_resend_button_is_enabled_once_the_countdown_is_over(): void
    {
        $this->freezeTime();
        $this->panelUser();
        $component = $this->requestCode();

        $this->travel(WhatsAppLoginService::RESEND_SECONDS)->seconds();
        $button = $this->resendButton($this->wrongCode($component));

        $this->assertSame('Kirim ulang kode (0)', $this->resendLabel($button));
        $this->assertFalse($button->hasAttribute('disabled'));
        $this->assertStringContainsString('endsAt: Date.now() + 0 * 1000', $button->getAttribute('x-data'));
        $this->assertStringContainsString('display: none', $button->getElementsByTagName('span')->item(1)->getAttribute('style'));
    }

    public function test_server_still_rejects_an_early_resend_and_tells_the_browser_the_remaining_seconds(): void
    {
        $this->freezeTime();
        $this->panelUser();
        $component = $this->requestCode();

        $this->travel(10)->seconds();
        $component->callAction('resendCode')
            ->assertNotified('Tunggu 50 detik sebelum meminta kode baru.')
            ->assertDispatched(WhatsAppLogin::RESEND_COUNTDOWN_EVENT, seconds: 50)
            ->assertNoJs();

        Http::assertSentCount(1);
        $this->assertCount(1, LoginCode::all());
    }

    public function test_successful_resend_restarts_the_countdown_and_focuses_the_code_input(): void
    {
        $this->freezeTime();
        $this->panelUser();
        $component = $this->requestCode();

        $this->travel(WhatsAppLoginService::RESEND_SECONDS + 1)->seconds();
        $component->callAction('resendCode')
            ->assertNotified('Kode baru dikirim')
            ->assertDispatched(WhatsAppLogin::RESEND_COUNTDOWN_EVENT, seconds: WhatsAppLoginService::RESEND_SECONDS)
            ->assertJs("document.getElementById('whatsapp-login-code')?.focus()");

        Http::assertSentCount(2);
        $button = $this->resendButton($component);
        $this->assertSame('Kirim ulang kode (60)', $this->resendLabel($button));
        $this->assertTrue($button->hasAttribute('disabled'));
    }

    public function test_hint_is_shown_only_in_the_code_step(): void
    {
        $this->panelUser();

        $component = Livewire::test(WhatsAppLogin::class)->assertDontSee(self::HINT);
        $this->assertNull($this->resendButton($component));

        $component->fillForm(['phone' => '0812-3456-7890'])->call('submit')->assertSee(self::HINT);

        $component->callAction('changeNumber')->assertDontSee(self::HINT);
        $this->assertNull($this->resendButton($component));
    }

    public function test_code_input_is_focused_when_the_code_step_opens(): void
    {
        $this->panelUser();
        $focusCode = "document.getElementById('whatsapp-login-code')?.focus()";

        $component = $this->requestCode()->assertSet('step', 'code')->assertJs($focusCode)->assertSeeHtml('id="whatsapp-login-code"');
        $this->assertStringContainsString('id="'.WhatsAppLogin::CODE_FIELD_ID.'"', $component->html());
    }

    public function test_phone_input_is_focused_after_changing_the_number(): void
    {
        $this->panelUser();

        $this->requestCode()->callAction('changeNumber')
            ->assertSet('step', 'phone')
            ->assertJs("document.getElementById('whatsapp-login-phone')?.focus()")
            ->assertSeeHtml('id="whatsapp-login-phone"');
    }

    public function test_no_focus_script_runs_when_the_number_is_rejected_or_the_page_first_opens(): void
    {
        Livewire::test(WhatsAppLogin::class)->assertNoJs()->assertSeeHtml('id="whatsapp-login-phone"');

        $this->requestCode('12345')->assertHasFormErrors(['phone'])->assertSet('step', 'phone')->assertNoJs();
    }
}
