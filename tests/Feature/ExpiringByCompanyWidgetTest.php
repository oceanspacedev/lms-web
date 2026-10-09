<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Widgets\ExpiringByCompany;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExpiringByCompanyWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(9, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);
    }

    private function document(Company $company, int $daysFromToday, bool $hasExpiry = true): Document
    {
        $document = Document::factory()->create([
            'company_id' => $company->id, 'document_type_id' => DocumentType::factory()->create(['has_expiry' => $hasExpiry])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays($daysFromToday)]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    private function company(string $name): Company
    {
        return Company::factory()->create(['name' => $name]);
    }

    public function test_documents_are_counted_in_the_right_bucket_including_both_edges(): void
    {
        $company = $this->company('PT Batas');
        foreach ([0, 30, 31, 60, 61, 90, 91, 400, -1, -30] as $offset) {
            $this->document($company, $offset);
        }

        Livewire::test(ExpiringByCompany::class)->assertCanSeeTableRecords([$company])
            ->assertTableColumnStateSet('due_30', 2, $company)
            ->assertTableColumnStateSet('due_60', 2, $company)
            ->assertTableColumnStateSet('due_90', 2, $company)
            ->assertTableColumnStateSet('overdue', 2, $company);
    }

    public function test_only_current_active_expiring_documents_are_counted(): void
    {
        $company = $this->company('PT Hitung');
        $counted = $this->document($company, 10);
        $this->document($company, 11, hasExpiry: false);
        $this->document($company, 12)->delete();
        $replaced = $this->document($company, 13);
        $replaced->currentVersion->update(['is_current' => false]);
        $newVersion = DocumentVersion::factory()->create(['document_id' => $replaced->id, 'version_number' => 2, 'expiry_date' => today('Asia/Jakarta')->addDays(200)]);
        $replaced->current_version_id = $newVersion->id;
        $replaced->save();

        Livewire::test(ExpiringByCompany::class)
            ->assertTableColumnStateSet('due_30', 1, $company)->assertTableColumnStateSet('due_60', 0, $company)
            ->assertTableColumnStateSet('due_90', 0, $company)->assertTableColumnStateSet('overdue', 0, $company);
        $this->assertNotNull($counted);
    }

    public function test_companies_without_relevant_documents_are_hidden_and_rows_are_ordered_by_urgency(): void
    {
        $quiet = $this->company('PT Tenang');
        $this->document($quiet, 300);
        $this->document($quiet, 5, hasExpiry: false);
        $empty = $this->company('PT Kosong');
        $busy = $this->company('PT Sibuk');
        $this->document($busy, 3);
        $this->document($busy, 4);
        $normal = $this->company('PT Biasa');
        $this->document($normal, 7);
        $overdueOnly = $this->company('PT Terlambat');
        $this->document($overdueOnly, -5);
        $later = $this->company('PT Nanti');
        $this->document($later, 80);

        Livewire::test(ExpiringByCompany::class)
            ->assertCanSeeTableRecords([$busy, $normal, $overdueOnly, $later], inOrder: true)
            ->assertCanNotSeeTableRecords([$quiet, $empty]);
    }

    public function test_counts_link_to_the_document_list_with_matching_filters(): void
    {
        $company = $this->company('PT Tautan');
        $inFirst = $this->document($company, 20);
        $inSecond = $this->document($company, 45);
        $overdue = $this->document($company, -2);
        $other = $this->document($this->company('PT Lain'), 20);

        $component = Livewire::test(ExpiringByCompany::class);
        $table = $component->instance()->getTable();
        $row = $component->instance()->getTableRecords()->firstWhere('id', $company->id);

        $first = $table->getColumn('due_30')->record($row)->getUrl();
        $this->assertStringContainsString('tableFilters', $first);
        $this->assertStringContainsString('2026-10-09', rawurldecode($first));
        $this->assertStringContainsString('2026-11-08', rawurldecode($first));
        $this->assertStringContainsString((string) $company->id, rawurldecode($first));
        $this->assertNull($table->getColumn('due_90')->record($row)->getUrl());

        Livewire::test(ListDocuments::class)
            ->filterTable('company', $company->id)->filterTable('expiry_range', ['expiry_from' => '2026-10-09', 'expiry_until' => '2026-11-08'])
            ->assertCanSeeTableRecords([$inFirst])->assertCanNotSeeTableRecords([$inSecond, $overdue, $other]);
        Livewire::test(ListDocuments::class)
            ->filterTable('company', $company->id)->filterTable('expiry_range', ['expiry_from' => '2026-11-09', 'expiry_until' => '2026-12-08'])
            ->assertCanSeeTableRecords([$inSecond])->assertCanNotSeeTableRecords([$inFirst, $overdue]);
        $this->assertStringContainsString('expired', rawurldecode($table->getColumn('overdue')->record($row)->getUrl()));
        Livewire::test(ListDocuments::class)
            ->filterTable('company', $company->id)->filterTable('expiry_status', 'expired')
            ->assertCanSeeTableRecords([$overdue])->assertCanNotSeeTableRecords([$inFirst, $inSecond]);
    }

    public function test_widget_is_on_the_dashboard_for_document_viewers_and_hidden_otherwise(): void
    {
        $this->assertTrue(ExpiringByCompany::canView());
        $this->assertContains(ExpiringByCompany::class, Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(ExpiringByCompany::canView());
        Livewire::test(ExpiringByCompany::class)->assertForbidden();
    }

    public function test_empty_state_is_shown_without_expiring_documents(): void
    {
        Livewire::test(ExpiringByCompany::class)->assertSee('Tidak ada dokumen yang akan berakhir dalam 90 hari');
    }

    public function test_existing_thirty_day_scope_is_unchanged_by_the_range_scope(): void
    {
        $company = $this->company('PT Cakupan');
        $today = $this->document($company, 0);
        $last = $this->document($company, 30);
        $outside = $this->document($company, 31);
        $past = $this->document($company, -1);

        $ids = Document::query()->expiringWithin(30)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$today->id, $last->id], $ids);
        $this->assertNotContains($outside->id, $ids);
        $this->assertNotContains($past->id, $ids);
    }
}
