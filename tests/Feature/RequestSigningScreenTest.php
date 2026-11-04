<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Http\Controllers\DocumentRequestFileController;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
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
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
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

    public function test_mark_received_without_scan_requires_file(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'signing', ['signed_path' => null]);

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('markReceived')
            ->callAction('markReceived', ['number' => 'PKS/001'])
            ->assertHasActionErrors(['file' => 'required']);
        $this->assertSame('signing', $request->fresh()->status);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('markReceived', ['number' => 'PKS/001', 'file' => $this->pdf('final.pdf')])->assertHasNoActionErrors();
        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertNotNull($request->physical_received_at);
    }

    public function test_mark_received_with_existing_scan_does_not_require_file(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, 'handover');
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('markReceived', ['number' => 'PKS/002'])
            ->assertHasNoActionErrors();

        $this->assertSame('archived', $request->fresh()->status);
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
