<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\TestCase;

class IndonesianMessagesAndLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
    }

    public function test_validation_messages_are_in_indonesian(): void
    {
        $this->assertSame('id', app()->getLocale());

        $errors = Validator::make(
            ['judul' => '', 'email' => 'bukan-email', 'jumlah' => 'abc'],
            ['judul' => 'required', 'email' => 'email', 'jumlah' => 'numeric'],
            [],
            ['judul' => 'Judul', 'email' => 'Email', 'jumlah' => 'Jumlah'],
        )->errors();

        $this->assertSame('Judul wajib diisi.', $errors->first('judul'));
        $this->assertSame('Email harus berupa alamat email yang valid.', $errors->first('email'));
        $this->assertSame('Jumlah harus berupa angka.', $errors->first('jumlah'));
    }

    public function test_filament_form_shows_the_required_message_in_indonesian(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'Create:Document']);
        $this->actingAs($user);

        $page = Livewire::test(CreateDocument::class)
            ->fillForm(['title' => ''])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required']);

        $this->assertSame('Judul wajib diisi.', $page->errors()->first('data.title'));
    }

    public function test_upload_time_is_shown_in_wib_on_the_detail_page_and_version_history(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 14)->setTime(3, 36));
        $document = Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Kontrak Waktu',
        ], UploadedFile::fake()->create('kontrak.pdf', 1, 'application/pdf'), User::factory()->create());

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($viewer);

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSee('14/10/2026 10:36')
            ->assertDontSee('14/10/2026 03:36');

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
            ->assertSee('14/10/2026 10:36')
            ->assertDontSee('14/10/2026 03:36');
    }
}
