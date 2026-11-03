<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\DocumentTypes\Pages\EditDocumentType;
use App\Filament\Resources\ReminderLogs\Pages\ViewReminderLog;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\ReminderLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ResourcePageNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    }

    public function test_creating_a_record_returns_to_the_list(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Complete Selular', 'legal_form' => 'CV', 'is_active' => true])
            ->call('create')->assertHasNoFormErrors()
            ->assertRedirect(CompanyResource::getUrl('index'));

        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Sertifikat Garansi', 'has_expiry' => false, 'is_active' => true])
            ->call('create')->assertHasNoFormErrors()
            ->assertRedirect(DocumentTypeResource::getUrl('index'));
    }

    public function test_saving_a_record_returns_to_the_list(): void
    {
        $company = Company::factory()->create();

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm(['name' => 'Top Selular'])
            ->call('save')->assertHasNoFormErrors()
            ->assertRedirect(CompanyResource::getUrl('index'));
    }

    public function test_submitting_a_request_stays_on_the_request_page(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest']);
        $this->actingAs($user);
        Http::fake();
        $request = DocumentRequest::factory()->create([
            'requester_id' => $user->id, 'status' => 'draft', 'start_date' => '2026-11-01',
            'requirements' => ['fields' => [], 'attachments' => [], 'has_expiry' => false],
            'history' => [['status' => 'draft', 'user' => $user->name, 'user_id' => $user->id, 'at' => now()->toIso8601String(), 'note' => null]],
        ]);

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('submit')->assertHasNoActionErrors()
            ->assertNoRedirect();

        $this->assertSame('submitted', $request->fresh()->status);
    }

    public function test_detail_and_edit_pages_have_a_back_button_to_the_list(): void
    {
        $document = Document::factory()->create();

        $pages = [
            [EditCompany::class, Company::factory()->create(), CompanyResource::getUrl('index')],
            [EditDocumentType::class, DocumentType::factory()->create(), DocumentTypeResource::getUrl('index')],
            [EditUser::class, User::factory()->create(), UserResource::getUrl('index')],
            [ViewDocument::class, $document, DocumentResource::getUrl('index')],
            [EditDocument::class, $document, DocumentResource::getUrl('index')],
            [ViewReminderLog::class, ReminderLog::factory()->create(), ReminderLogResource::getUrl('index')],
            [EditDocumentRequest::class, DocumentRequest::factory()->create(), DocumentRequestResource::getUrl('index')],
        ];

        foreach ($pages as [$page, $record, $listUrl]) {
            Livewire::test($page, ['record' => $record->getRouteKey()])
                ->assertActionVisible(TestAction::make('back'))
                ->assertActionHasUrl(TestAction::make('back'), $listUrl)
                ->assertActionHasLabel(TestAction::make('back'), 'Kembali');
        }
    }
}
