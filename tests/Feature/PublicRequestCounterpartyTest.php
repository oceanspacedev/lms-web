<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pihak lawan dokumen yang diarsipkan dari pengajuan publik berasal dari isian "Pihak lawan / mitra",
 * tidak pernah dari nama badan usaha pengaju.
 */
class PublicRequestCounterpartyTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    private Company $company;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Storage::fake('local');
        Storage::fake('s3');
        Http::preventStrayRequests();
        $this->seed(DummyAccountSeeder::class);
        $reviewer = $this->dummy('dummy-pemeriksa', '081200000001');
        $this->approver = $this->dummy('dummy-penyetuju', '081200000002');
        $this->type = DocumentType::factory()->withoutExpiry()->create([
            'name' => 'Perjanjian Kerja Sama', 'request_pic_id' => $reviewer->id, 'request_reviewer_id' => $reviewer->id, 'request_approver_id' => $this->approver->id,
        ]);
        $this->company = Company::factory()->create(['name' => 'PT Alur Lengkap', 'is_active' => true]);
    }

    private function dummy(string $slug, string $phone): User
    {
        $user = User::where('email', $slug.'@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $user->update(['phone' => $phone]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = []): DocumentRequest
    {
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi', 'document_type_id' => $this->type->id,
            'company_id' => $this->company->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
            ...$overrides,
        ])->assertRedirect();

        return DocumentRequest::latest('id')->firstOrFail();
    }

    /** Menyetujui pengajuan lalu mengarsipkannya seperti yang dilakukan penyetuju. */
    private function archive(DocumentRequest $request, ?int $companyId = null): Document
    {
        $request->forceFill(['status' => 'approved'])->save();
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        return $request->fresh()->archiveSigned(UploadedFile::fake()->createWithContent('final-ttd.pdf', $pdf), 'PKS/2026/001', '2026-10-09', null, $this->approver, $companyId);
    }

    public function test_form_shows_an_optional_counterparty_field_in_the_document_section(): void
    {
        $html = $this->get(route('requests.public.create'))->assertOk()->assertSee('Pihak lawan / mitra')->getContent();

        $this->assertMatchesRegularExpression('/<input id="field-partner_name" name="partner_name" type="text" value=""(?:(?!>).)*maxlength="255"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="field-partner_name"[^>]*\srequired/', $html);
        $this->assertLessThan(strpos($html, 'name="partner_name"'), strpos($html, 'name="title"'));
        $this->assertLessThan(strpos($html, 'name="request_reason"'), strpos($html, 'name="partner_name"'));
    }

    public function test_counterparty_entered_by_the_requester_is_stored_and_archived(): void
    {
        $request = $this->submit(['partner_name' => 'PT Mitra Sejahtera']);

        $this->assertSame('PT Mitra Sejahtera', $request->partner_name);
        $document = $this->archive($request);
        $this->assertSame('PT Mitra Sejahtera', $document->counterparty);
        $this->assertSame('PT Alur Lengkap', $document->company->name);
    }

    public function test_blank_counterparty_is_stored_and_archived_as_null_not_as_the_company_name(): void
    {
        $withoutField = $this->submit();
        $emptyField = $this->submit(['partner_name' => '', 'requester_phone' => '081355550001']);
        $spacesOnly = $this->submit(['partner_name' => '   ', 'requester_phone' => '081355550002']);

        foreach ([$withoutField, $emptyField, $spacesOnly] as $request) {
            $this->assertNull($request->partner_name);
            $this->assertSame($this->company->id, $request->company_id);
        }
        $document = $this->archive($withoutField);
        $this->assertNull($document->counterparty);
        $this->assertSame('PT Alur Lengkap', $document->company->name);
    }

    public function test_unregistered_business_is_not_copied_into_counterparty(): void
    {
        $request = $this->submit(['company_id' => 'other', 'other_business_name' => 'CV Usaha Sendiri']);

        $this->assertNull($request->company_id);
        $this->assertSame('CV Usaha Sendiri', $request->other_business_name);
        $this->assertNull($request->partner_name);
        $this->assertNull($this->archive($request, $this->company->id)->counterparty);
    }

    public function test_unregistered_business_and_counterparty_are_kept_apart(): void
    {
        $request = $this->submit(['company_id' => 'other', 'other_business_name' => 'CV Usaha Sendiri', 'partner_name' => 'PT Mitra Sejahtera']);

        $this->assertSame('CV Usaha Sendiri', $request->other_business_name);
        $this->assertSame('PT Mitra Sejahtera', $request->partner_name);
        $this->assertSame('PT Mitra Sejahtera', $this->archive($request, $this->company->id)->counterparty);
    }

    public function test_counterparty_longer_than_255_characters_is_rejected_under_its_field(): void
    {
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama', 'title' => 'Kerja Sama', 'document_type_id' => $this->type->id, 'company_id' => $this->company->id,
            'partner_name' => str_repeat('a', 256),
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
        ])->assertSessionHasErrors('partner_name');

        $this->assertSame(0, DocumentRequest::count());
    }

    public function test_revision_page_prefills_the_counterparty_and_lets_it_be_changed_or_cleared(): void
    {
        $request = $this->submit(['partner_name' => 'PT Mitra Sejahtera']);
        $request->forceFill(['status' => 'revision'])->save();
        $status = route('requests.public.status', ['token' => $request->public_token]);
        $payload = [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi',
        ];

        $this->get($status)->assertOk()->assertSee('id="field-partner_name" name="partner_name" type="text" value="PT Mitra Sejahtera"', false);

        $this->post(route('requests.public.revise', ['token' => $request->public_token]), [...$payload, 'partner_name' => 'CV Mitra Baru'])->assertRedirect();
        $this->assertSame('CV Mitra Baru', $request->fresh()->partner_name);

        $request->fresh()->forceFill(['status' => 'revision'])->save();
        $this->post(route('requests.public.revise', ['token' => $request->public_token]), [...$payload, 'partner_name' => ''])->assertRedirect();
        $this->assertNull($request->fresh()->partner_name);
    }

    public function test_revision_without_the_counterparty_field_keeps_the_stored_value(): void
    {
        $request = $this->submit(['partner_name' => 'PT Mitra Sejahtera']);
        $request->forceFill(['status' => 'revision'])->save();

        $this->post(route('requests.public.revise', ['token' => $request->public_token]), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi',
        ])->assertRedirect();

        $this->assertSame('PT Mitra Sejahtera', $request->fresh()->partner_name);
    }

    public function test_legacy_public_request_with_its_own_company_as_partner_is_not_prefilled_nor_archived_as_counterparty(): void
    {
        $registered = $this->submit();
        $registered->forceFill(['partner_name' => 'pt alur lengkap', 'status' => 'revision'])->save();
        $unregistered = $this->submit(['company_id' => 'other', 'other_business_name' => 'CV Usaha Sendiri', 'requester_phone' => '081355550001']);
        $unregistered->forceFill(['partner_name' => 'CV Usaha Sendiri'])->save();

        $this->get(route('requests.public.status', ['token' => $registered->public_token]))->assertOk()
            ->assertSee('id="field-partner_name" name="partner_name" type="text" value=""', false);
        $this->assertNull($registered->fresh()->counterpartyName());
        $this->assertNull($this->archive($unregistered, $this->company->id)->counterparty);
    }

    public function test_internal_request_keeps_the_partner_typed_by_staff_even_if_it_matches_the_company(): void
    {
        $request = DocumentRequest::factory()->create(['company_id' => $this->company->id, 'partner_name' => 'PT Alur Lengkap']);

        $this->assertNull($request->public_token);
        $this->assertSame('PT Alur Lengkap', $request->counterpartyName());
    }
}
