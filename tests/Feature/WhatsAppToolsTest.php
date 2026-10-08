<?php

namespace Tests\Feature;

use App\Filament\Resources\ReminderTemplates\Pages\ManageReminderTemplates;
use App\Filament\Resources\ReminderTemplates\Pages\WhatsAppDeliveryHistory;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\WhatsAppTemplateTester;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
    }

    private function authorizeSettings(array $permissions = ['ViewAny:ReminderTemplate', 'Create:ReminderTemplate', 'Update:ReminderTemplate']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['phone' => '081234567890']);
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
        RateLimiter::clear('whatsapp-template-test:'.$user->id);

        return $user;
    }

    public static function templateEvents(): array
    {
        return array_map(fn (string $event): array => [$event], ['reminder', ...array_keys(ReminderTemplate::REQUEST_TEMPLATE_LABELS)]);
    }

    #[DataProvider('templateEvents')]
    public function test_test_message_uses_saved_template_and_records_provider_acceptance(string $event): void
    {
        $user = $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => false, 'body' => 'Uji {dokumen}', 'request_templates' => [$event === 'reminder' ? 'revision' : $event => 'Uji {judul} {status}']]);
        $result = app(WhatsAppTemplateTester::class)->send($user, $event, '+62 812-3456-7890');
        $this->assertSame('accepted', $result['status']);
        $log = DB::table('whatsapp_test_messages')->sole();
        $this->assertSame('accepted', $log->status);
        $this->assertNotNull($log->accepted_at);
        $this->assertSame('6281234567890', $log->recipient_phone);
        $this->assertSame($event, $log->template_event);
        $this->assertStringStartsWith('[PESAN UJI]', $log->message);
        $this->assertStringNotContainsString('{', $log->message);
        Http::assertSent(fn ($request): bool => $request['message']['text'] === $log->message
            && $request['recipient']['value'] === '6281234567890'
            && $request->header('Idempotency-Key')[0] === 'lms-template-test-'.$log->event_key);
        $this->assertFalse($setting->fresh()->is_active);
        $this->assertSame($setting->body, $setting->fresh()->body);
        $this->assertDatabaseCount('reminder_logs', 0);
        $this->assertDatabaseCount('document_request_notifications', 0);
    }

    public function test_test_action_validates_number_and_sends_only_after_submission(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create();
        $action = TestAction::make('test_reminder')->schemaComponent('expiry_settings.template_reminder', 'content');
        $page = Livewire::test(ManageReminderTemplates::class)->mountAction($action)
            ->assertActionDataSet(['phone' => '081234567890']);
        Http::assertNothingSent();
        $page->setActionData(['phone' => 'not-a-number'])->callMountedAction()->assertHasActionErrors(['phone']);
        Http::assertNothingSent();
        $page->setActionData(['phone' => '081234567890'])->callMountedAction()->assertHasNoActionErrors();
        Http::assertSentCount(1);
    }

    public function test_provider_rejection_and_connection_failure_are_recorded_without_claiming_delivery(): void
    {
        $user = $this->authorizeSettings();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['token' => 'must-not-leak'], 503)]);
        $result = app(WhatsAppTemplateTester::class)->send($user, 'revision', $user->phone);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('HTTP 503', $result['error']);
        $this->assertStringNotContainsString('must-not-leak', $result['error']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => throw new ConnectionException('private transport details')]);
        $result = app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
        $this->assertSame('failed', $result['status']);
        $this->assertStringNotContainsString('private', $result['error']);
        $this->assertSame(2, DB::table('whatsapp_test_messages')->where('status', 'failed')->whereNull('accepted_at')->count());
    }

    public function test_missing_provider_configuration_is_recorded_safely(): void
    {
        $user = $this->authorizeSettings();
        config(['services.waghub.token' => null]);
        $result = app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
        $this->assertSame('failed', $result['status']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_test_messages', ['status' => 'failed', 'accepted_at' => null]);
    }

    public function test_test_messages_are_limited_to_five_per_minute(): void
    {
        $user = $this->authorizeSettings();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
        }
        try {
            app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
            $this->fail('Sixth test should be limited.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('phone', $exception->errors());
        }
        Http::assertSentCount(5);
        $this->assertDatabaseCount('whatsapp_test_messages', 5);
    }

    public function test_read_only_user_cannot_send_or_restore_templates(): void
    {
        $user = $this->authorizeSettings(['ViewAny:ReminderTemplate']);
        ReminderTemplate::factory()->create(['body' => 'Custom {dokumen}']);
        Livewire::test(ManageReminderTemplates::class)->assertDontSee('Kirim pesan uji')->assertDontSee('Kembalikan bawaan');
        $this->expectException(HttpException::class);
        app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
    }

    #[DataProvider('templateEvents')]
    public function test_restoring_template_changes_only_the_selected_message(string $event): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => true, 'schedule_mode' => 'specific_days', 'scheduled_days' => [7, 1, 0],
            'body' => 'Custom {dokumen}', 'request_templates' => array_fill_keys(array_keys(ReminderTemplate::REQUEST_TEMPLATE_LABELS), 'Custom {judul}')]);
        $component = $event === 'reminder' ? 'expiry_settings.template_reminder' : 'notification_settings.template_navigation.'.$event.'.template_'.$event;
        Livewire::test(ManageReminderTemplates::class)->callAction(TestAction::make('reset_'.$event)->schemaComponent($component, 'content'))->assertHasNoActionErrors();
        $fresh = $setting->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame([7, 1, 0], $fresh->scheduled_days);
        $this->assertSame('specific_days', $fresh->schedule_mode);
        $this->assertSame($event === 'reminder' ? ReminderTemplate::DEFAULT_BODY : $setting->body, $fresh->body);
        foreach (ReminderTemplate::REQUEST_TEMPLATE_LABELS as $other => $label) {
            $this->assertSame($event === $other ? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$other] : 'Custom {judul}', $fresh->request_templates[$other]);
        }
    }

    public function test_schedule_summary_has_quick_choices_without_timeline(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => false, 'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7]);
        Livewire::test(ManageReminderTemplates::class)->assertSee('Setiap hari')->assertSee('3 hari sekali')->assertSee('7 hari sekali')
            ->assertSee('Nonaktif')->assertDontSee('Alur pengingat')->assertDontSee('wa-timeline')->assertDontSee('jadwal berikutnya');
        $setting->update(['is_active' => true, 'schedule_mode' => 'specific_days', 'scheduled_days' => [7, 1, 0]]);
        Livewire::test(ManageReminderTemplates::class)->assertSee('Tanggal berakhir')->assertDontSee('wa-timeline');
        $setting->update(['schedule_mode' => 'inherit']);
        Livewire::test(ManageReminderTemplates::class)->assertSee('Jadwal belum diatur');
    }

    public function test_history_hides_other_users_tests_and_unauthorized_document_logs(): void
    {
        $user = $this->authorizeSettings();
        app(WhatsAppTemplateTester::class)->send($user, 'reminder', $user->phone);
        $other = User::factory()->create();
        DB::table('whatsapp_test_messages')->insert(['event_key' => fake()->uuid(), 'user_id' => $other->id, 'template_event' => 'reminder', 'recipient_phone' => '6289999999999', 'message' => 'PRIVATE TEST', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        ReminderLog::factory()->create(['request_payload' => ['message' => ['text' => 'PRIVATE REMINDER']]]);
        DocumentRequestNotification::factory()->create(['payload' => ['message' => ['text' => 'PRIVATE NOTIFICATION']]]);
        Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('Pesan uji')->assertSee('Diterima WagHub')
            ->assertDontSee('PRIVATE TEST')->assertDontSee('PRIVATE REMINDER')->assertDontSee('PRIVATE NOTIFICATION')
            ->set('source', 'reminder')->assertSee('Belum ada pengiriman');
    }

    public function test_history_scopes_request_logs_filters_status_and_paginates(): void
    {
        $user = $this->authorizeSettings(['ViewAny:ReminderTemplate', 'ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAny:ReminderLog', 'View:ReminderLog']);
        $request = DocumentRequest::factory()->create(['requester_id' => $user->id]);
        DocumentRequestNotification::factory()->create(['document_request_id' => $request->id, 'status' => 'failed', 'error_message' => 'Nomor tidak valid', 'payload' => ['message' => ['text' => 'VISIBLE REQUEST']]]);
        DocumentRequestNotification::factory()->create(['payload' => ['message' => ['text' => 'PRIVATE REQUEST']]]);
        ReminderLog::factory()->create(['status' => 'sent', 'request_payload' => ['message' => ['text' => 'VISIBLE REMINDER']]]);
        $page = Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('VISIBLE REQUEST')->assertSee('VISIBLE REMINDER')->assertDontSee('PRIVATE REQUEST')
            ->set('deliveryStatus', 'failed')->assertSee('Nomor tidak valid')->assertDontSee('VISIBLE REMINDER')
            ->set('deliveryStatus', 'all')->set('source', 'reminder')->assertSee('VISIBLE REMINDER')->assertDontSee('VISIBLE REQUEST');
        $this->assertSame(1, $page->instance()->deliveries()->total());
        ReminderLog::factory()->count(16)->create();
        $page->call('$refresh');
        $this->assertSame(15, $page->instance()->deliveries()->count());
        $page->call('nextPage');
        $this->assertSame(2, $page->instance()->deliveries()->currentPage());
        $page->set('deliveryStatus', 'failed');
        $this->assertSame(1, $page->instance()->deliveries()->currentPage());
    }

    public function test_history_page_requires_settings_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(WhatsAppDeliveryHistory::class)->assertForbidden();
    }
}
