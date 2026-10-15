<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\CreateDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentRequestFormScreenTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_MARK = '<sup class="fi-fo-field-label-required-mark">*</sup>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'], string $name = 'Rina Pemohon'): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function draft(User $owner, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'requester_id' => $owner->id, 'status' => 'draft', 'start_date' => '2026-11-01',
            'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => false],
            'history' => [['status' => 'draft', 'user' => $owner->name, 'user_id' => $owner->id, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    /** True bila label field ditandai wajib (tanda bintang) di HTML halaman. */
    private function labelHasRequiredMark(string $html, string $label): bool
    {
        return (bool) preg_match('/'.preg_quote($label, '/').'\s*'.preg_quote(self::REQUIRED_MARK, '/').'/', $html);
    }

    /** @return list<string> Judul notifikasi yang sedang menunggu ditampilkan di panel. */
    private function notificationTitles(): array
    {
        $notifications = new Notifications;
        $notifications->mount();

        return $notifications->notifications->map(fn (Notification $notification): string => (string) $notification->getTitle())->values()->all();
    }

    public function test_create_form_marks_the_submission_requirements_and_follows_the_selected_document_type(): void
    {
        $this->signIn();
        $withExpiry = DocumentType::factory()->create(['has_expiry' => true]);
        $withoutExpiry = DocumentType::factory()->withoutExpiry()->create();

        $page = Livewire::test(CreateDocumentRequest::class)
            ->assertFormFieldExists('start_date', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('expiry_date', fn (Field $field): bool => ! $field->isMarkedAsRequired())
            ->assertFormFieldExists('purpose', fn (Field $field): bool => $field->getLabel() === 'Tujuan Pengajuan');
        $this->assertTrue($this->labelHasRequiredMark($page->html(), 'Mulai'));
        $this->assertFalse($this->labelHasRequiredMark($page->html(), 'Berakhir'));
        $page->assertSee('Tujuan Pengajuan');

        $page->fillForm(['document_type_id' => $withExpiry->id])
            ->assertFormFieldExists('expiry_date', fn (Field $field): bool => $field->isMarkedAsRequired());
        $this->assertTrue($this->labelHasRequiredMark($page->html(), 'Berakhir'));

        $page->fillForm(['document_type_id' => $withoutExpiry->id])
            ->assertFormFieldExists('expiry_date', fn (Field $field): bool => ! $field->isMarkedAsRequired());
        $this->assertFalse($this->labelHasRequiredMark($page->html(), 'Berakhir'));
    }

    public function test_cost_fields_are_hidden_until_the_toggle_is_on_and_then_marked_required(): void
    {
        $this->signIn();

        $page = Livewire::test(CreateDocumentRequest::class)->assertFormFieldDoesNotExist('amount')->assertFormFieldDoesNotExist('payment_terms');
        $this->assertFalse($this->labelHasRequiredMark($page->html(), 'Nilai (Rp)'));

        $page->fillForm(['has_cost' => true])
            ->assertFormFieldExists('amount', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('payment_terms', fn (Field $field): bool => $field->isMarkedAsRequired());
        $this->assertTrue($this->labelHasRequiredMark($page->html(), 'Nilai (Rp)'));
        $this->assertTrue($this->labelHasRequiredMark($page->html(), 'Pembayaran'));
        $page->assertSee('Wajib diisi saat mengajukan.');
    }

    public function test_required_markers_do_not_block_saving_a_draft_without_the_submission_fields(): void
    {
        $user = $this->signIn();
        $type = DocumentType::factory()->create(['has_expiry' => true]);
        $company = Company::factory()->create(['is_active' => true]);

        Livewire::test(CreateDocumentRequest::class)
            ->fillForm(['company_id' => $company->id, 'document_type_id' => $type->id, 'title' => 'Draf Tanpa Tanggal', 'partner_name' => 'PT Mitra', 'has_cost' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $saved = DocumentRequest::where('title', 'Draf Tanpa Tanggal')->sole();
        $this->assertSame('draft', $saved->status);
        $this->assertSame($user->id, $saved->requester_id);
        $this->assertNull($saved->start_date);
        $this->assertNull($saved->expiry_date);
        $this->assertTrue($saved->has_cost);
        $this->assertNull($saved->amount);
    }

    public function test_counterparty_is_optional_on_the_internal_form_like_on_the_public_form(): void
    {
        $this->signIn();
        $type = DocumentType::factory()->withoutExpiry()->create(['name' => 'Akta Pendirian']);
        $company = Company::factory()->create(['is_active' => true]);

        $page = Livewire::test(CreateDocumentRequest::class)
            ->assertFormFieldExists('partner_name', fn (Field $field): bool => $field->getLabel() === 'Pihak lawan / mitra' && ! $field->isRequired());
        $this->assertFalse($this->labelHasRequiredMark($page->html(), 'Pihak lawan / mitra'));
        $page->assertSee('Kosongkan bila dokumen tidak punya pihak lawan');

        $page->fillForm(['company_id' => $company->id, 'document_type_id' => $type->id, 'title' => 'Akta Tanpa Mitra', 'partner_name' => ''])
            ->call('create')->assertHasNoFormErrors();

        $this->assertNull(DocumentRequest::where('title', 'Akta Tanpa Mitra')->sole()->counterpartyName());
    }

    public function test_edit_form_marks_expiry_from_the_stored_requirements_of_the_request(): void
    {
        $user = $this->signIn();
        $needsExpiry = $this->draft($user, ['has_cost' => true, 'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => true]]);
        $noExpiry = $this->draft($user);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $needsExpiry->getRouteKey()])
            ->assertFormFieldExists('start_date', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('expiry_date', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('amount', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('payment_terms', fn (Field $field): bool => $field->isMarkedAsRequired());
        foreach (['Mulai', 'Berakhir', 'Nilai (Rp)', 'Pembayaran'] as $label) {
            $this->assertTrue($this->labelHasRequiredMark($page->html(), $label), "{$label} harus bertanda wajib");
        }
        $page->assertSee('Tujuan Pengajuan');

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $noExpiry->getRouteKey()])
            ->assertFormFieldExists('start_date', fn (Field $field): bool => $field->isMarkedAsRequired())
            ->assertFormFieldExists('expiry_date', fn (Field $field): bool => ! $field->isMarkedAsRequired())
            ->assertFormFieldDoesNotExist('amount');
        $this->assertTrue($this->labelHasRequiredMark($page->html(), 'Mulai'));
        $this->assertFalse($this->labelHasRequiredMark($page->html(), 'Berakhir'));
    }

    public function test_submit_sends_only_the_submitted_notification(): void
    {
        $user = $this->signIn();
        $request = $this->draft($user);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('submit')
            ->assertHasNoActionErrors();

        $this->assertSame('submitted', $request->fresh()->status);
        $this->assertSame(['Pengajuan dikirim'], $this->notificationTitles());
    }

    public function test_saving_a_draft_still_sends_the_saved_notification(): void
    {
        $user = $this->signIn();
        $request = $this->draft($user);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->call('save')->assertHasNoFormErrors();

        $this->assertSame([__('filament-panels::resources/pages/edit-record.notifications.saved.title')], $this->notificationTitles());
    }

    public function test_submit_reports_missing_submission_fields_on_the_form_instead_of_a_notification(): void
    {
        $user = $this->signIn();
        $request = $this->draft($user, ['start_date' => null, 'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => true]]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('submit')
            ->assertHasFormErrors(['start_date', 'expiry_date']);

        $this->assertSame('draft', $request->fresh()->status);
        $this->assertNotContains('Pengajuan dikirim', $this->notificationTitles());
    }

    public function test_read_only_summary_shows_the_partner_or_a_placeholder(): void
    {
        $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest']);
        $withPartner = $this->draft(User::factory()->create(), ['status' => 'submitted', 'partner_name' => 'PT Mitra Sejati']);
        $withoutPartner = DocumentRequest::factory()->create([
            'status' => 'submitted', 'requester_id' => null, 'requester_name' => 'Budi Publik', 'requester_phone' => '081234567890', 'partner_name' => null, 'public_token' => str_repeat('a', 64),
            'history' => [['status' => 'submitted', 'user' => 'Budi Publik', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]],
        ]);

        Livewire::test(EditDocumentRequest::class, ['record' => $withPartner->getRouteKey()])
            ->assertSee('Pihak lawan / mitra')->assertSee('PT Mitra Sejati')->assertDontSee('—');
        Livewire::test(EditDocumentRequest::class, ['record' => $withoutPartner->getRouteKey()])
            ->assertSee('Pihak lawan / mitra')->assertDontSee('PT Mitra Sejati')->assertSee('—');
    }

    public function test_history_section_lists_names_jakarta_time_and_notes_newest_first_in_read_only_mode(): void
    {
        $reviewer = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest'], 'Rina Pemeriksa');
        $request = DocumentRequest::factory()->create([
            'status' => 'revision', 'requester_id' => User::factory()->create(['name' => 'Budi Pengaju'])->id, 'reviewer_id' => $reviewer->id,
            'history' => [
                ['status' => 'draft', 'user' => 'Budi Pengaju', 'user_id' => 1, 'at' => '2026-10-01T01:30:00+00:00', 'note' => null],
                ['status' => 'submitted', 'user' => 'Budi Pengaju', 'user_id' => 1, 'at' => '2026-10-02T02:45:00+00:00', 'note' => null],
                ['status' => 'revision', 'user' => 'Rina Pemeriksa', 'user_id' => $reviewer->id, 'at' => '2026-10-03T16:05:00+00:00', 'note' => "Lengkapi NIB.\nBaris kedua <b>tebal</b>"],
            ],
        ]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Riwayat')
            ->assertSeeInOrder(['Perlu revisi', 'Pengajuan diterima', 'Draf dibuat'])
            ->assertSee('Rina Pemeriksa')->assertSee('Budi Pengaju')
            // 16:05 UTC = 23:05 WIB, 02:45 UTC = 09:45 WIB, 01:30 UTC = 08:30 WIB.
            ->assertSeeInOrder(['03 Okt 2026 23:05 WIB', '02 Okt 2026 09:45 WIB', '01 Okt 2026 08:30 WIB'])
            ->assertSee('Lengkapi NIB.')
            ->assertSeeHtml('&lt;b&gt;tebal&lt;/b&gt;')
            ->assertDontSeeHtml('<b>tebal</b>');
        $this->assertStringContainsString('white-space: pre-line', $page->html());
        $this->assertStringContainsString("Lengkapi NIB.\nBaris kedua", $page->html());
    }

    public function test_history_section_labels_a_submission_after_revision_as_revisi_dikirim(): void
    {
        $reviewer = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest'], 'Rina Pemeriksa');
        $request = DocumentRequest::factory()->create([
            'status' => 'submitted', 'requester_id' => User::factory()->create(['name' => 'Budi Pengaju'])->id, 'reviewer_id' => $reviewer->id,
            'history' => [
                ['status' => 'submitted', 'user' => 'Budi Pengaju', 'user_id' => 1, 'at' => '2026-10-02T02:45:00+00:00', 'note' => null],
                ['status' => 'revision', 'user' => 'Rina Pemeriksa', 'user_id' => $reviewer->id, 'at' => '2026-10-03T16:05:00+00:00', 'note' => 'Lengkapi NIB.'],
                ['status' => 'submitted', 'user' => 'Budi Pengaju', 'user_id' => 1, 'at' => '2026-10-04T03:00:00+00:00', 'note' => null],
            ],
        ]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSeeInOrder(['Revisi dikirim', 'Perlu revisi', 'Pengajuan diterima']);
    }

    public function test_history_section_is_also_shown_when_the_request_is_editable(): void
    {
        $user = $this->signIn(name: 'Rina Pemohon');
        $request = $this->draft($user);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('Riwayat')->assertSee('Draf dibuat')->assertSee('Rina Pemohon')->assertSee('09 Okt 2026 15:00 WIB');
    }

    public function test_read_only_page_has_no_cancel_action_and_calls_the_breadcrumb_detail(): void
    {
        $reviewer = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest']);
        $someoneElses = $this->draft(User::factory()->create(), ['status' => 'submitted', 'reviewer_id' => $reviewer->id]);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $someoneElses->getRouteKey()])->assertDontSee('Simpan Draf');
        $this->assertSame('Detail', last($page->instance()->getBreadcrumbs()));
        $this->assertStringNotContainsString('Batal', strip_tags($page->html()));
        $this->assertStringContainsString('Detail', strip_tags($page->html()));
    }

    public function test_editable_page_keeps_the_cancel_action_and_the_edit_breadcrumb(): void
    {
        $user = $this->signIn();
        $request = $this->draft($user);

        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertSee('Simpan Draf')->assertSee('Batal');
        $this->assertSame(__('filament-panels::resources/pages/edit-record.breadcrumb'), last($page->instance()->getBreadcrumbs()));
    }
}
