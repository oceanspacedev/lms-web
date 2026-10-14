<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DummyAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nomor WhatsApp yang ditempel dari aplikasi WhatsApp ("+62 812-3456-7890") tidak boleh terpotong oleh batas panjang isian
 * sebelum spasi dan tanda hubungnya dibersihkan.
 */
class PublicRequestPhoneInputTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->seed(DummyAccountSeeder::class);
        $reviewer = User::where('email', 'dummy-pemeriksa@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $reviewer->update(['phone' => '081200000001']);
        $this->type = DocumentType::factory()->withoutExpiry()->create(['request_pic_id' => $reviewer->id, 'request_reviewer_id' => $reviewer->id]);
        $this->company = Company::factory()->create(['is_active' => true]);
    }

    private function submitWithPhone(string $phone): TestResponse
    {
        return $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => $phone, 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi', 'document_type_id' => $this->type->id,
            'company_id' => $this->company->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
        ]);
    }

    public function test_phone_field_is_long_enough_for_a_number_pasted_in_whatsapp_format(): void
    {
        $html = $this->get(route('requests.public.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="field-requester_phone"[^>]*type="tel"[^>]*maxlength="25"/', $html);
        $this->assertGreaterThan(strlen('+62 812-3456-7890'), 25);
    }

    public function test_formatted_number_is_stored_with_every_digit(): void
    {
        $this->submitWithPhone('+62 812-3456-7890')->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('6281234567890', DocumentRequest::latest('id')->firstOrFail()->requester_phone);
    }

    public function test_number_that_is_still_too_long_after_cleaning_is_rejected(): void
    {
        $this->submitWithPhone('+62 812-3456-7890-1234')->assertSessionHasErrors('requester_phone');
        $this->submitWithPhone('0812 abcd 7890')->assertSessionHasErrors('requester_phone');

        $this->assertSame(0, DocumentRequest::count());
    }
}
