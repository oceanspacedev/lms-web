<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Http\Controllers\DocumentRequestFileController;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Aksi tanda tangan dan dokumen fisik di halaman pengajuan admin.
 */
class RequestSigningScreenTest extends TestCase
{
    use RefreshDatabase;

    private const LEGAL_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document'];

    private const REQUESTER_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions, string $name): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function legal(): User
    {
        return $this->user(self::LEGAL_PERMISSIONS, 'Lina Legal');
    }

    private function requester(): User
    {
        return $this->user(self::REQUESTER_PERMISSIONS, 'Rina Pemohon');
    }

    /** @param array<string, mixed> $attributes */
    private function request(User $requester, User $reviewer, string $state, array $attributes = []): DocumentRequest
    {
        $factory = DocumentRequest::factory();
        $factory = match ($state) {
            'signing' => $factory->signing(1),
            'handover' => $factory->handover($attributes['signedDaysAgo'] ?? 1),
            default => $factory,
        };
        unset($attributes['signedDaysAgo']);

        return $factory->create([
            'requester_id' => $requester->id, 'reviewer_id' => $reviewer->id,
            'status' => $state === 'submitted' ? 'submitted' : $state,
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'history' => [['status' => 'submitted', 'user' => $requester->name, 'user_id' => $requester->id, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function pdf(string $name = 'berkas.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    public function test_legal_sees_issue_draft_on_submitted_and_requester_does_not(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal, 'submitted');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('issueDraft')
            ->assertActionVisible('revise')
            ->assertActionHidden('uploadSigned')
            ->assertActionHidden('markReceived')
            ->assertActionHidden('cancel');

        $this->actingAs($requester);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionHidden('issueDraft')
            ->assertActionHidden('cancel');
    }

    public function test_issue_draft_action_uploads_and_changes_status(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'submitted');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('issueDraft', ['file' => $this->pdf('draft.pdf')])
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame('signing', $request->status);
        $this->assertNotNull($request->draft_path);
        Storage::disk('local')->assertExists($request->draft_path);

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('issueDraft')
            ->assertActionExists('issueDraft', fn ($action): bool => $action->getLabel() === 'Ganti Draft');
    }

    public function test_requester_uploads_scan_and_sees_deadline(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal, 'signing');

        $this->actingAs($requester);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('uploadSigned')
            ->assertActionHidden('issueDraft')
            ->callAction('uploadSigned', ['file' => $this->pdf('scan.pdf')])
            ->assertHasNoActionErrors()
            ->assertSee('Tanda tangan &amp; dokumen fisik', false)
            ->assertSee('16 Okt 2026')
            ->assertSee('Sisa 7 hari');

        $request->refresh();
        $this->assertSame('handover', $request->status);
        Storage::disk('local')->assertExists($request->signed_path);
    }

    public function test_late_requester_sees_reason_action_and_reason_list(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal, 'handover', ['signedDaysAgo' => 10]);

        $this->actingAs($requester);
        $page = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('addLateReason')
            ->assertSee('Terlambat 3 hari');
        $page->callAction('addLateReason', ['reason' => 'Kurir tertahan di luar kota.'])
            ->assertHasNoActionErrors()
            ->assertSee('Kurir tertahan di luar kota.');

        $this->assertSame(1, $request->lateReasons()->count());

        $onTime = $this->request($requester, $legal, 'handover', ['signedDaysAgo' => 2]);
        Livewire::test(EditDocumentRequest::class, ['record' => $onTime->getRouteKey()])->assertActionHidden('addLateReason');
    }

    public function test_mark_received_without_scan_asks_for_it_in_the_same_modal(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'signing', ['signed_path' => null]);

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionEnabled('markReceived')
            ->assertActionVisible('uploadSigned')
            ->mountAction('markReceived')
            ->assertMountedActionModalSee('Scan dokumen bertanda tangan')
            ->callMountedAction()
            ->assertHasActionErrors(['signed_scan' => 'required'])
            ->fillForm(['signed_scan' => $this->pdf('scan.pdf')])
            ->callMountedAction()

            ->assertHasNoActionErrors()
            ->assertNotified('Fisik diterima');

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertSame('scan.pdf', $request->signed_name);
        $this->assertSame('scan.pdf', Document::findOrFail($request->document_id)->currentVersion->file_name);
    }

    public function test_mark_received_with_scan_does_not_ask_for_it_again(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover');
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->mountAction('markReceived')
            ->assertMountedActionModalDontSee('Scan dokumen bertanda tangan');
    }

    public function test_mark_received_is_one_click_without_fields(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover', ['start_date' => '2026-10-01', 'expiry_date' => null]);
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionEnabled('markReceived')
            ->mountAction('markReceived')
            ->assertMountedActionModalSee('Tandai fisik diterima?')
            ->assertMountedActionModalDontSee(['Nomor Dokumen', 'Badan Usaha arsip', 'Tanggal berakhir'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertNotNull($request->physical_received_at);
        $document = Document::findOrFail($request->document_id);
        $this->assertNull($document->document_number);
        $this->assertSame('2026-10-01', $document->currentVersion->issued_date->toDateString());
        $this->assertSame('%PDF-1.4 scan', $document->currentVersion->storage()->get($document->currentVersion->file_path));
    }

    public function test_mark_received_asks_only_for_data_the_request_does_not_have(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover', [
            'company_id' => null, 'other_business_name' => 'PT Usaha Lain',
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id, 'expiry_date' => null,
        ]);
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');
        $company = Company::factory()->create();

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->mountAction('markReceived')
            ->assertMountedActionModalSee(['Badan Usaha arsip', 'Tanggal berakhir'])
            ->callMountedAction()
            ->assertHasActionErrors(['company_id' => 'required', 'expiry_date' => 'required'])
            ->fillForm(['company_id' => $company->id, 'expiry_date' => '2027-12-31'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $archived = Document::findOrFail($request->document_id);
        $this->assertSame($company->id, $archived->company_id);
        $this->assertSame('2027-12-31', $archived->currentVersion->expiry_date->toDateString());
    }

    public function test_request_list_and_summary_show_what_is_still_missing(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $signing = $this->request($requester, $legal, 'signing', ['signed_path' => null]);
        $handover = $this->request($requester, $legal, 'handover');
        $archived = $this->request($requester, $legal, 'handover', ['status' => 'archived', 'physical_received_at' => now()]);

        $this->actingAs($legal);
        Livewire::test(ListDocumentRequests::class)
            ->assertTableColumnStateSet('status', ['Menunggu Tanda Tangan', 'Belum scan'], $signing)
            ->assertTableColumnStateSet('status', ['Menunggu Dokumen Fisik', 'Fisik belum diterima'], $handover)
            ->assertTableColumnStateSet('status', ['Selesai'], $archived);

        Livewire::test(EditDocumentRequest::class, ['record' => $signing->getRouteKey()])->assertSee('Belum scan');
        Livewire::test(EditDocumentRequest::class, ['record' => $handover->getRouteKey()])->assertSee('Fisik belum diterima');
    }

    public function test_list_offers_mark_received_while_waiting_for_scan_or_physical_document(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $submitted = $this->request($requester, $legal, 'submitted');
        $signing = $this->request($requester, $legal, 'signing', ['signed_path' => null]);
        $handover = $this->request($requester, $legal, 'handover');
        $archived = $this->request($requester, $legal, 'handover', ['status' => 'archived', 'physical_received_at' => now()]);

        $this->actingAs($legal);
        Livewire::test(ListDocumentRequests::class)
            ->assertActionVisible(TestAction::make('markReceived')->table($handover))
            ->assertActionVisible(TestAction::make('markReceived')->table($signing))
            ->assertActionHidden(TestAction::make('markReceived')->table($submitted))
            ->assertActionHidden(TestAction::make('markReceived')->table($archived));

        $this->actingAs($requester);
        Livewire::test(ListDocumentRequests::class)->assertActionHidden(TestAction::make('markReceived')->table($handover));
    }

    public function test_mark_received_from_the_list_takes_the_scan_when_missing(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'signing', ['signed_path' => null]);

        $this->actingAs($legal);
        Livewire::test(ListDocumentRequests::class)
            ->callAction(TestAction::make('markReceived')->table($request), ['signed_scan' => $this->pdf('scan.pdf')])
            ->assertHasNoActionErrors()
            ->assertNotified('Fisik diterima');

        $this->assertSame('archived', $request->refresh()->status);
    }

    public function test_mark_received_from_the_list_archives_the_request(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover');
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $this->actingAs($legal);
        Livewire::test(ListDocumentRequests::class)
            ->callAction(TestAction::make('markReceived')->table($request))
            ->assertHasNoActionErrors()
            ->assertNotified('Fisik diterima');

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertNotNull(Document::find($request->document_id));
    }

    public function test_mark_received_from_the_list_asks_for_missing_data(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover', [
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id, 'expiry_date' => null,
        ]);
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $this->actingAs($legal);
        Livewire::test(ListDocumentRequests::class)
            ->mountAction(TestAction::make('markReceived')->table($request))
            ->assertMountedActionModalSee('Tanggal berakhir')
            ->callMountedAction()
            ->assertHasActionErrors(['expiry_date' => 'required'])
            ->fillForm(['expiry_date' => '2027-12-31'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('archived', $request->refresh()->status);
    }

    public function test_cancel_action_requires_note(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('cancel')
            ->callAction('cancel', ['note' => ''])
            ->assertHasActionErrors(['note' => 'required']);
        $this->assertSame('handover', $request->fresh()->status);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('cancel', ['note' => 'Pengajuan dobel.'])->assertHasNoActionErrors();
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_file_route_requires_view_permission_and_returns_404_when_missing(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal, 'handover');
        Storage::disk('local')->put($request->draft_path, '%PDF-1.4 draft');
        $url = route('requests.file', ['documentRequest' => $request, 'kind' => 'draft']);

        $this->get($url)->assertRedirect();

        $stranger = $this->user(['ViewAny:DocumentRequest', 'View:DocumentRequest'], 'Orang Lain');
        $this->actingAs($stranger)->get($url)->assertForbidden();

        $response = $this->actingAs($requester)->get($url)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->actingAs($requester)->get(route('requests.file', ['documentRequest' => $request, 'kind' => 'signed']))->assertNotFound();
        $this->actingAs($requester)->get(route('requests.file', ['documentRequest' => $request, 'kind' => 'other']))->assertNotFound();
    }

    public function test_public_download_serves_files_by_token_and_404s_otherwise(): void
    {
        $legal = $this->legal();
        $token = str_repeat('a', 64);
        $request = $this->request($this->requester(), $legal, 'handover', ['public_token' => $token]);
        Storage::disk('local')->put($request->draft_path, '%PDF-1.4 draft');
        $controller = new DocumentRequestFileController;

        $this->assertSame(200, $controller->publicDownload($token, 'draft')->getStatusCode());

        foreach ([[$token, 'signed'], [$token, 'other'], [str_repeat('b', 64), 'draft']] as [$givenToken, $kind]) {
            try {
                $controller->publicDownload($givenToken, $kind);
                $this->fail('Harus 404.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
