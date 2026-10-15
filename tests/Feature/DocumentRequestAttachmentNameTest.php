<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentRequestAttachmentNameTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:00', 'UTC'));
        Storage::fake('local');
        $this->seed(DummyAccountSeeder::class);
        $reviewer = User::where('email', 'dummy-pemeriksa@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $reviewer->update(['phone' => '081200000001']);
        $this->type = DocumentType::factory()->create(['name' => 'Perjanjian Kerja Sama', 'request_pic_id' => $reviewer->id]);
    }

    /** @param array<string, mixed> $overrides */
    private function submit(array $overrides = []): DocumentRequest
    {
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi', 'document_type_id' => $this->type->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('scan acak 123.pdf', 10, 'application/pdf')],
            ...$overrides,
        ])->assertRedirect();

        return DocumentRequest::latest('id')->firstOrFail();
    }

    public function test_attachment_name_contains_company_type_attachment_and_upload_time(): void
    {
        $company = Company::factory()->create(['name' => 'Alur Lengkap', 'legal_form' => 'PT', 'is_active' => true]);

        $request = $this->submit(['company_id' => $company->id]);

        $path = $request->attachments['supporting-document'];
        $this->assertSame('lms/requests/perjanjian-kerja-sama/pt-alur-lengkap_perjanjian-kerja-sama_dokumen-pendukung_20261009-150000.pdf', $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_other_business_name_is_used_when_company_is_not_registered(): void
    {
        $request = $this->submit(['company_id' => 'other', 'other_business_name' => 'CV Mitra Baru']);

        $this->assertSame('lms/requests/perjanjian-kerja-sama/cv-mitra-baru_perjanjian-kerja-sama_dokumen-pendukung_20261009-150000.pdf', $request->attachments['supporting-document']);
    }

    public function test_same_name_in_the_same_second_gets_a_numbered_suffix(): void
    {
        $company = Company::factory()->create(['name' => 'Alur Lengkap', 'legal_form' => 'PT', 'is_active' => true]);

        $first = $this->submit(['company_id' => $company->id]);
        $second = $this->submit(['company_id' => $company->id, 'requester_phone' => '081355550001']);

        $this->assertSame('lms/requests/perjanjian-kerja-sama/pt-alur-lengkap_perjanjian-kerja-sama_dokumen-pendukung_20261009-150000-2.pdf', $second->attachments['supporting-document']);
        $this->assertCount(2, Storage::disk('local')->allFiles('lms/requests/perjanjian-kerja-sama'));
        $this->assertNotSame($first->attachments['supporting-document'], $second->attachments['supporting-document']);
    }
}
