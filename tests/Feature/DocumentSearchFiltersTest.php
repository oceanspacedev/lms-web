<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentSearchFiltersTest extends TestCase
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

    private function document(string $title, ?string $counterparty, ?string $expiry, bool $hasExpiry = true): Document
    {
        $document = Document::factory()->create([
            'title' => $title, 'counterparty' => $counterparty,
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => $hasExpiry])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => $expiry]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    public function test_counterparty_filter_matches_part_of_the_name_ignoring_case(): void
    {
        $abc = $this->document('Kontrak A', 'PT Maju Bersama', '2026-12-01');
        $xyz = $this->document('Kontrak B', 'CV Sentosa Abadi', '2026-12-01');
        $none = $this->document('Kontrak C', null, '2026-12-01');

        Livewire::test(ListDocuments::class)
            ->filterTable('counterparty', ['counterparty' => 'maju'])
            ->assertCanSeeTableRecords([$abc])->assertCanNotSeeTableRecords([$xyz, $none])
            ->filterTable('counterparty', ['counterparty' => '  SENTOSA '])
            ->assertCanSeeTableRecords([$xyz])->assertCanNotSeeTableRecords([$abc, $none])
            ->filterTable('counterparty', ['counterparty' => ''])
            ->assertCanSeeTableRecords([$abc, $xyz, $none]);
    }

    public function test_counterparty_filter_treats_wildcards_literally(): void
    {
        $percent = $this->document('Diskon', 'Mitra 50% Grosir', '2026-12-01');
        $plain = $this->document('Biasa', 'Mitra 5000 Grosir', '2026-12-01');
        $underscore = $this->document('Garis', 'Mitra_Khusus', '2026-12-01');
        $letter = $this->document('Huruf', 'MitraXKhusus', '2026-12-01');

        Livewire::test(ListDocuments::class)
            ->filterTable('counterparty', ['counterparty' => '50%'])
            ->assertCanSeeTableRecords([$percent])->assertCanNotSeeTableRecords([$plain])
            ->filterTable('counterparty', ['counterparty' => 'mitra_khusus'])
            ->assertCanSeeTableRecords([$underscore])->assertCanNotSeeTableRecords([$letter]);
    }

    public function test_expiry_range_filter_is_inclusive_and_uses_the_current_version(): void
    {
        $before = $this->document('Sebelum', 'A', '2026-10-31');
        $first = $this->document('Awal', 'A', '2026-11-01');
        $middle = $this->document('Tengah', 'A', '2026-11-15');
        $last = $this->document('Akhir', 'A', '2026-11-30');
        $after = $this->document('Sesudah', 'A', '2026-12-01');
        $noExpiry = $this->document('Tanpa Batas', 'A', null, hasExpiry: false);
        DocumentVersion::factory()->create(['document_id' => $middle->id, 'version_number' => 2, 'is_current' => false, 'expiry_date' => '2027-06-01']);

        Livewire::test(ListDocuments::class)
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-01', 'expiry_until' => '2026-11-30'])
            ->assertCanSeeTableRecords([$first, $middle, $last])->assertCanNotSeeTableRecords([$before, $after, $noExpiry])
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-30', 'expiry_until' => null])
            ->assertCanSeeTableRecords([$last, $after])->assertCanNotSeeTableRecords([$before, $first, $middle, $noExpiry])
            ->filterTable('expiry_range', ['expiry_from' => null, 'expiry_until' => '2026-11-01'])
            ->assertCanSeeTableRecords([$before, $first])->assertCanNotSeeTableRecords([$middle, $last, $after, $noExpiry])
            ->filterTable('expiry_range', ['expiry_from' => null, 'expiry_until' => null])
            ->assertCanSeeTableRecords([$before, $first, $middle, $last, $after, $noExpiry]);
    }

    public function test_reversed_expiry_range_matches_nothing_and_shows_the_empty_state(): void
    {
        $document = $this->document('Apa Saja', 'A', '2026-11-10');

        Livewire::test(ListDocuments::class)
            ->filterTable('expiry_range', ['expiry_from' => '2026-12-01', 'expiry_until' => '2026-11-01'])
            ->assertCanNotSeeTableRecords([$document])->assertSee('Dokumen tidak ditemukan')
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-01', 'expiry_until' => '2026-12-01'])
            ->assertCanSeeTableRecords([$document]);
    }

    public function test_search_finds_documents_by_counterparty_and_filters_combine(): void
    {
        $match = $this->document('Kerja Sama Logistik', 'PT Nusantara Cargo', '2026-11-10');
        $otherDate = $this->document('Sewa Gudang', 'PT Nusantara Cargo', '2027-05-10');
        $otherParty = $this->document('Kontrak Lain', 'CV Lain', '2026-11-12');

        Livewire::test(ListDocuments::class)
            ->searchTable('nusantara')
            ->assertCanSeeTableRecords([$match, $otherDate])->assertCanNotSeeTableRecords([$otherParty])
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-01', 'expiry_until' => '2026-11-30'])
            ->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$otherDate, $otherParty]);
    }

    public function test_active_filters_show_readable_indicators_and_grid_view_uses_the_same_filters(): void
    {
        $match = $this->document('Cocok', 'PT Maju', '2026-11-10');
        $other = $this->document('Tidak Cocok', 'CV Lain', '2026-11-10');

        $component = Livewire::test(ListDocuments::class, ['documentView' => 'grid'])
            ->filterTable('counterparty', ['counterparty' => 'maju'])
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-01', 'expiry_until' => null])
            ->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other]);

        $labels = collect($component->instance()->getTable()->getFilterIndicators())->map(fn ($indicator): string => $indicator->getLabel())->all();
        $this->assertContains('Pihak lawan: maju', $labels);
        $this->assertContains('Berakhir dari 01 Nov 2026', $labels);
    }
}
