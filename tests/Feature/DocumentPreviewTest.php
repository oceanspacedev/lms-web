<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
    }

    private function signInAsViewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);

        return $user;
    }

    private function document(string $filename, string $mime): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Kontrak Pratinjau',
        ], UploadedFile::fake()->create($filename, 1, $mime), User::factory()->create());
    }

    private function previewUrl(Document $document): string
    {
        return route('documents.download', ['document' => $document, 'preview' => 1]);
    }

    public function test_detail_page_does_not_load_the_file_until_pratinjau_is_opened(): void
    {
        $document = $this->document('kontrak.pdf', 'application/pdf');
        $this->signInAsViewer();
        $url = $this->previewUrl($document);

        $page = Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('preview')->assertSee('Pratinjau')
            ->assertDontSeeHtml($url)->assertDontSeeHtml('<iframe');

        $this->assertDatabaseMissing('document_activities', ['event' => 'previewed']);

        $page->mountAction('preview')->assertActionMounted('preview')
            ->assertMountedActionModalSeeHtml([$url, '<iframe'])
            ->assertMountedActionModalSee([$document->currentVersion->stored_name, 'Pratinjau Dokumen', 'Tutup']);
    }

    public function test_pratinjau_renders_an_image_for_image_files(): void
    {
        $document = $this->document('foto.png', 'image/png');
        $this->signInAsViewer();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertDontSeeHtml($this->previewUrl($document))
            ->mountAction('preview')
            ->assertMountedActionModalSeeHtml([$this->previewUrl($document), '<img'])
            ->assertMountedActionModalDontSeeHtml('<iframe');
    }

    public static function previewableTypes(): array
    {
        return [
            'pdf' => ['kontrak.pdf', 'application/pdf'],
            'jpeg' => ['foto.jpg', 'image/jpeg'],
            'png' => ['foto.png', 'image/png'],
        ];
    }

    #[DataProvider('previewableTypes')]
    public function test_pratinjau_is_offered_for_pdf_jpeg_and_png(string $filename, string $mime): void
    {
        $document = $this->document($filename, $mime);
        $this->signInAsViewer();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertActionVisible('preview');
    }

    public function test_pratinjau_is_hidden_for_word_files_and_cannot_be_mounted(): void
    {
        $document = $this->document('kontrak.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->signInAsViewer();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()->assertActionHidden('preview')->assertActionVisible('download')
            ->assertDontSeeHtml($this->previewUrl($document))
            ->mountAction('preview')->assertActionNotMounted('preview');
    }

    public function test_viewing_the_detail_page_logs_no_preview_but_requesting_the_preview_url_does(): void
    {
        $document = $this->document('kontrak.pdf', 'application/pdf');
        $user = $this->signInAsViewer();
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (): string => 'https://storage.test/signed');

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertSuccessful();
        $this->assertDatabaseMissing('document_activities', ['event' => 'previewed']);

        $this->get($this->previewUrl($document))->assertRedirect('https://storage.test/signed');
        $this->assertDatabaseHas('document_activities', ['event' => 'previewed', 'user_id' => $user->id, 'document_id' => $document->id]);
    }
}
