<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Widgets\WaitingRequestsOverview;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Policies\DocumentRequestPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentRequestListScreenTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['ViewAny:DocumentRequest', 'View:DocumentRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions = [], string $name = 'Saya Sendiri'): User
    {
        $permissions = [...self::BASE, ...$permissions];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function request(string $status, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'title' => 'Kontrak Umum', 'partner_name' => 'PT Netral',
            'history' => [['status' => $status, 'user' => 'Seseorang', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    /** @return list<int> */
    private function actionableIds(): array
    {
        $ids = DocumentRequestResource::scopeToActionable(DocumentRequestResource::getEloquentQuery())->pluck('id')->all();
        sort($ids);

        return $ids;
    }

    /** @param list<DocumentRequest> $requests @return list<int> */
    private function ids(array $requests): array
    {
        $ids = array_map(fn (DocumentRequest $request): int => $request->id, $requests);
        sort($ids);

        return $ids;
    }

    public function test_number_column_is_first_shows_the_id_and_is_sortable(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $first = $this->request('draft');
        $second = $this->request('draft');

        $page = Livewire::test(ListDocumentRequests::class)
            ->assertTableColumnExists('id')
            ->assertTableColumnStateSet('id', $first->id, $first)
            ->assertTableColumnFormattedStateSet('id', '#'.$first->id, $first)
            ->sortTable('id', 'desc')->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->sortTable('id', 'asc')->assertCanSeeTableRecords([$first, $second], inOrder: true);
        $this->assertSame('id', array_key_first($page->instance()->getTable()->getColumns()));
        $this->assertSame('No.', $page->instance()->getTable()->getColumns()['id']->getLabel());
    }

    public function test_partner_column_does_not_query_the_company_of_every_public_row(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $requests = [];
        foreach (range(1, 6) as $number) {
            $company = Company::factory()->create(['name' => "PT Pengaju {$number}"]);
            $requests[] = $this->request('submitted', ['company_id' => $company->id, 'requester_id' => null, 'requester_name' => 'Budi', 'public_token' => str_repeat((string) $number, 64), 'partner_name' => "PT Mitra {$number}"]);
        }

        DB::enableQueryLog();
        Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords($requests)->assertSee('PT Mitra 6');
        $companyQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "companies"'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $companyQueries, 'Perusahaan harus dimuat sekali untuk seluruh baris, bukan satu kali per baris.');
    }

    public function test_search_finds_a_request_by_its_number_with_or_without_hash(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $target = $this->request('draft', ['title' => 'Target Pencarian']);
        $others = [$this->request('draft', ['title' => 'Lain A']), $this->request('draft', ['title' => 'Lain B'])];

        Livewire::test(ListDocumentRequests::class)
            ->searchTable('#'.$target->id)->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords($others)
            ->searchTable((string) $target->id)->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords($others)
            ->searchTable(' #'.$target->id.' ')->assertCanSeeTableRecords([$target])
            ->searchTable('#'.($target->id + 1000))->assertCanNotSeeTableRecords([$target, ...$others]);
    }

    public function test_search_finds_requests_by_applicant_name_for_public_and_internal_requests(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $internalUser = User::factory()->create(['name' => 'Citra Lestari']);
        $public = $this->request('submitted', ['requester_id' => null, 'requester_name' => 'Budi Santoso', 'requester_phone' => '081234567890']);
        $internal = $this->request('draft', ['requester_id' => $internalUser->id]);
        $other = $this->request('draft', ['requester_id' => User::factory()->create(['name' => 'Dewi Anggraini'])->id]);

        Livewire::test(ListDocumentRequests::class)
            ->searchTable('budi')->assertCanSeeTableRecords([$public])->assertCanNotSeeTableRecords([$internal, $other])
            ->searchTable('SANTOSO')->assertCanSeeTableRecords([$public])
            ->searchTable('citra')->assertCanSeeTableRecords([$internal])->assertCanNotSeeTableRecords([$public, $other])
            ->searchTable('Citra Lestari')->assertCanSeeTableRecords([$internal])->assertCanNotSeeTableRecords([$public, $other])
            ->searchTable('tidak ada')->assertCanNotSeeTableRecords([$public, $internal, $other]);
    }

    public function test_applicant_search_treats_like_wildcards_as_plain_text(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $public = $this->request('submitted', ['requester_id' => null, 'requester_name' => 'Budi Santoso', 'requester_phone' => '081234567890']);
        $internal = $this->request('draft', ['requester_id' => User::factory()->create(['name' => 'Citra Lestari'])->id]);
        $literal = $this->request('submitted', ['requester_id' => null, 'requester_name' => 'Tono_100%', 'requester_phone' => '081234567891']);

        Livewire::test(ListDocumentRequests::class)
            ->searchTable('Bud_')->assertCanNotSeeTableRecords([$public, $internal, $literal])
            ->searchTable('Bud%')->assertCanNotSeeTableRecords([$public, $internal, $literal])
            ->searchTable('Cit_a')->assertCanNotSeeTableRecords([$internal])
            ->searchTable('Tono_100%')->assertCanSeeTableRecords([$literal])->assertCanNotSeeTableRecords([$public, $internal]);
    }

    public function test_search_still_finds_by_title_and_partner_and_shows_a_placeholder_without_a_partner(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $byTitle = $this->request('draft', ['title' => 'Perjanjian Sewa Gudang']);
        $byPartner = $this->request('draft', ['partner_name' => 'CV Mitra Abadi']);
        $noPartner = $this->request('submitted', ['requester_id' => null, 'requester_name' => 'Budi Santoso', 'requester_phone' => '081234567890', 'partner_name' => null, 'public_token' => str_repeat('b', 64)]);

        $page = Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords([$byTitle, $byPartner, $noPartner])
            ->assertTableColumnStateSet('partner_name', null, $noPartner)
            ->assertTableColumnStateSet('partner_name', 'CV Mitra Abadi', $byPartner);
        $this->assertStringContainsString('—', $page->html());
        $page->searchTable('Sewa Gudang')->assertCanSeeTableRecords([$byTitle])->assertCanNotSeeTableRecords([$byPartner, $noPartner])
            ->searchTable('Mitra Abadi')->assertCanSeeTableRecords([$byPartner])->assertCanNotSeeTableRecords([$byTitle, $noPartner]);
    }

    public function test_tabs_are_all_and_actionable_with_all_as_default_and_the_tab_lives_in_the_query_string(): void
    {
        $user = $this->signIn(['Review:DocumentRequest']);
        $mine = $this->request('submitted', ['requester_id' => User::factory()->create()->id, 'reviewer_id' => $user->id]);
        $notMine = $this->request('review', ['requester_id' => User::factory()->create()->id]);

        $page = Livewire::test(ListDocumentRequests::class)->assertSet('activeTab', 'all')
            ->assertSee('Semua')->assertSee('Perlu tindakan saya')
            ->assertCanSeeTableRecords([$mine, $notMine]);
        $this->assertSame(['Semua', 'Perlu tindakan saya'], array_values(array_map(fn ($tab): string => $tab->getLabel(), $page->instance()->getCachedTabs())));

        Livewire::withQueryParams(['tab' => 'actionable'])->test(ListDocumentRequests::class)
            ->assertSet('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$notMine]);
        $urlAttribute = (new \ReflectionProperty(ListDocumentRequests::class, 'activeTab'))->getAttributes(Url::class)[0]->newInstance();
        $this->assertSame('tab', $urlAttribute->as);

        $page->set('activeTab', 'actionable')->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$notMine])
            ->set('activeTab', 'all')->assertCanSeeTableRecords([$mine, $notMine]);
    }

    public function test_reviewer_sees_submitted_requests_assigned_to_them_or_to_nobody_but_never_their_own(): void
    {
        $user = $this->signIn(['Review:DocumentRequest']);
        $other = User::factory()->create();
        $unassigned = $this->request('submitted', ['requester_id' => $other->id, 'reviewer_id' => null]);
        $assigned = $this->request('submitted', ['requester_id' => $other->id, 'reviewer_id' => $user->id]);
        $public = $this->request('submitted', ['requester_id' => null, 'requester_name' => 'Budi Publik', 'requester_phone' => '081234567890', 'reviewer_id' => $user->id]);
        $ownSubmission = $this->request('submitted', ['requester_id' => $user->id, 'reviewer_id' => null]);
        $assignedElsewhere = $this->request('submitted', ['requester_id' => $other->id, 'reviewer_id' => User::factory()->create()->id]);
        $inApproval = $this->request('review', ['requester_id' => $other->id, 'reviewer_id' => $user->id]);
        $approvedByOthers = $this->request('approved', ['requester_id' => $other->id]);

        $page = Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$unassigned, $assigned, $public])
            ->assertCanNotSeeTableRecords([$ownSubmission, $assignedElsewhere, $inApproval, $approvedByOthers]);
        $this->assertSame('3', $page->instance()->getCachedTabs()['actionable']->getBadge());
        $this->assertMatchesRegularExpression('/Perlu tindakan saya\s*<\/span>\s*<span\s+class="fi-badge[^>]*>\s*<span class="fi-badge-label-ctn">\s*<span class="fi-badge-label">\s*3\s*<\/span>/', $page->html());
    }

    public function test_approver_sees_requests_waiting_for_approval_that_are_theirs_or_unassigned(): void
    {
        $user = $this->signIn(['Approve:DocumentRequest']);
        $other = User::factory()->create();
        $unassigned = $this->request('review', ['requester_id' => $other->id, 'approver_id' => null]);
        $assigned = $this->request('review', ['requester_id' => null, 'requester_name' => 'Budi Publik', 'requester_phone' => '081234567890', 'approver_id' => $user->id]);
        $own = $this->request('review', ['requester_id' => $user->id, 'approver_id' => null]);
        $elsewhere = $this->request('review', ['requester_id' => $other->id, 'approver_id' => User::factory()->create()->id]);
        $stillChecking = $this->request('submitted', ['requester_id' => $other->id, 'reviewer_id' => null]);

        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$unassigned, $assigned])
            ->assertCanNotSeeTableRecords([$own, $elsewhere, $stillChecking]);
        $this->assertSame('2', Livewire::test(ListDocumentRequests::class)->instance()->getCachedTabs()['actionable']->getBadge());
    }

    public function test_requester_sees_their_own_drafts_and_revisions_only(): void
    {
        $user = $this->signIn(['Update:DocumentRequest']);
        $other = User::factory()->create();
        $draft = $this->request('draft', ['requester_id' => $user->id]);
        $revision = $this->request('revision', ['requester_id' => $user->id]);
        $submitted = $this->request('submitted', ['requester_id' => $user->id]);
        $archived = $this->request('archived', ['requester_id' => $user->id]);
        $othersDraft = $this->request('draft', ['requester_id' => $other->id]);

        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$draft, $revision])
            ->assertCanNotSeeTableRecords([$submitted, $archived, $othersDraft]);
        $this->assertSame('2', Livewire::test(ListDocumentRequests::class)->instance()->getCachedTabs()['actionable']->getBadge());
    }

    public function test_requester_without_update_permission_has_nothing_to_act_on(): void
    {
        $user = $this->signIn();
        $draft = $this->request('draft', ['requester_id' => $user->id]);

        Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords([$draft])
            ->set('activeTab', 'actionable')->assertCanNotSeeTableRecords([$draft]);
        $this->assertSame('0', Livewire::test(ListDocumentRequests::class)->instance()->getCachedTabs()['actionable']->getBadge());
    }

    public function test_archiver_sees_approved_requests_only_when_the_archive_rule_allows_it(): void
    {
        $other = User::factory()->create();

        $requesterArchiver = $this->signIn(['Create:Document']);
        $ownApproved = $this->request('approved', ['requester_id' => $requesterArchiver->id]);
        $othersApproved = $this->request('approved', ['requester_id' => $other->id, 'pic_user_id' => $requesterArchiver->id]);
        $ownStillOpen = $this->request('review', ['requester_id' => $requesterArchiver->id]);
        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$ownApproved])->assertCanNotSeeTableRecords([$othersApproved, $ownStillOpen]);
        $this->assertSame('1', Livewire::test(ListDocumentRequests::class)->instance()->getCachedTabs()['actionable']->getBadge());

        $approverArchiver = $this->signIn(['Create:Document', 'Approve:DocumentRequest']);
        $unassignedApproved = $this->request('approved', ['requester_id' => $other->id, 'approver_id' => null]);
        $assignedApproved = $this->request('approved', ['requester_id' => $other->id, 'approver_id' => $approverArchiver->id]);
        $elsewhereApproved = $this->request('approved', ['requester_id' => $other->id, 'approver_id' => User::factory()->create()->id]);
        $alreadyArchived = $this->request('archived', ['requester_id' => $other->id]);
        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$unassignedApproved, $assignedApproved, $ownApproved, $othersApproved])
            ->assertCanNotSeeTableRecords([$elsewhereApproved, $alreadyArchived]);

        $this->signIn(['Approve:DocumentRequest']);
        $approverWithoutArchive = $this->request('approved', ['requester_id' => $other->id, 'approver_id' => null]);
        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')->assertCanNotSeeTableRecords([$approverWithoutArchive]);
    }

    public function test_actionable_tab_follows_the_policy_and_not_the_wider_view_all_visibility(): void
    {
        $user = $this->signIn(['ViewAll:DocumentRequest', 'Review:DocumentRequest']);
        $assignedElsewhere = $this->request('submitted', ['requester_id' => User::factory()->create()->id, 'reviewer_id' => User::factory()->create()->id]);
        $actionable = $this->request('submitted', ['requester_id' => User::factory()->create()->id, 'reviewer_id' => $user->id]);

        Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords([$assignedElsewhere, $actionable])
            ->set('activeTab', 'actionable')->assertCanSeeTableRecords([$actionable])->assertCanNotSeeTableRecords([$assignedElsewhere]);
    }

    public function test_actionable_set_matches_the_policy_for_every_role_and_request_combination(): void
    {
        $roles = [
            'none' => [], 'reviewer' => ['Review:DocumentRequest'], 'approver' => ['Approve:DocumentRequest'], 'requester' => ['Update:DocumentRequest'],
            'archiver' => ['Create:Document'], 'approver-archiver' => ['Approve:DocumentRequest', 'Create:Document'],
            'everything' => ['Review:DocumentRequest', 'Approve:DocumentRequest', 'Update:DocumentRequest', 'Create:Document'],
            'viewer-all' => ['ViewAll:DocumentRequest', 'Review:DocumentRequest', 'Approve:DocumentRequest', 'Create:Document', 'Update:DocumentRequest'],
        ];
        $users = [];
        foreach ($roles as $role => $permissions) {
            $users[$role] = $this->signIn($permissions, $role);
        }
        $other = User::factory()->create();
        $shared = ['company_id' => Company::factory()->create()->id, 'document_type_id' => DocumentType::factory()->create()->id, 'pic_user_id' => $other->id];
        $requesters = [null, $other->id, $users['everything']->id, $users['requester']->id, $users['archiver']->id];
        $assignments = [[null, null], [$other->id, $other->id], [$users['reviewer']->id, $users['approver']->id], [$users['everything']->id, $users['everything']->id], [$users['everything']->id, $other->id], [$other->id, $users['everything']->id]];
        $requests = [];
        foreach (array_keys(DocumentRequest::STATUSES) as $status) {
            foreach ($requesters as $requester) {
                foreach ($assignments as [$reviewer, $approver]) {
                    $requests[] = $this->request($status, [...$shared, 'requester_id' => $requester, 'requester_name' => $requester ? null : 'Pengaju Publik', 'requester_phone' => $requester ? null : '081234567890', 'reviewer_id' => $reviewer, 'approver_id' => $approver]);
                }
            }
        }

        $policy = new DocumentRequestPolicy;
        $sizes = [];
        foreach ($users as $role => $user) {
            $this->actingAs($user);
            $visible = DocumentRequestResource::getEloquentQuery()->pluck('id')->all();
            $expected = collect($requests)->filter(fn (DocumentRequest $request): bool => in_array($request->id, $visible, true)
                && ($policy->review($user, $request) || $policy->approve($user, $request) || $policy->archive($user, $request) || $policy->update($user, $request)))
                ->map(fn (DocumentRequest $request): int => $request->id)->sort()->values()->all();

            $this->assertSame($expected, $this->actionableIds(), "Peran {$role}: tab 'Perlu tindakan saya' harus sama dengan kebijakan");
            $this->assertSame((string) count($expected), Livewire::test(ListDocumentRequests::class)->instance()->getCachedTabs()['actionable']->getBadge(), "Peran {$role}: badge");
            $sizes[$role] = count($expected);
        }
        $this->assertSame(0, $sizes['none']);
        foreach (['reviewer', 'approver', 'requester', 'archiver', 'approver-archiver', 'everything', 'viewer-all'] as $role) {
            $this->assertGreaterThan(0, $sizes[$role], "Peran {$role} seharusnya punya pengajuan yang bisa ditindaklanjuti");
        }
    }

    public function test_empty_states_explain_why_the_list_is_empty(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);

        Livewire::test(ListDocumentRequests::class)->assertSee('Belum ada pengajuan');

        $this->request('archived', ['requester_id' => User::factory()->create()->id]);
        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertSee('Tidak ada pengajuan yang perlu tindakan Anda')->assertSee('Buka tab Semua untuk melihat seluruh pengajuan.')
            ->assertDontSee('Belum ada pengajuan');
        Livewire::test(ListDocumentRequests::class)->searchTable('tidak-ada-yang-cocok')
            ->assertSee('Pengajuan tidak ditemukan')->assertDontSee('Belum ada pengajuan');
        Livewire::test(ListDocumentRequests::class)->filterTable('status', 'rejected')
            ->assertSee('Pengajuan tidak ditemukan');
    }

    public function test_actionable_tab_combines_with_the_status_filter_and_search(): void
    {
        $user = $this->signIn(['Update:DocumentRequest', 'Review:DocumentRequest']);
        $draft = $this->request('draft', ['requester_id' => $user->id, 'title' => 'Draf Saya']);
        $revision = $this->request('revision', ['requester_id' => $user->id, 'title' => 'Revisi Saya']);
        $toReview = $this->request('submitted', ['requester_id' => User::factory()->create()->id, 'title' => 'Periksa Ini']);

        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')->assertCanSeeTableRecords([$draft, $revision, $toReview])
            ->filterTable('status', 'draft')->assertCanSeeTableRecords([$draft])->assertCanNotSeeTableRecords([$revision, $toReview])
            ->removeTableFilter('status')->searchTable('Periksa')->assertCanSeeTableRecords([$toReview])->assertCanNotSeeTableRecords([$draft, $revision]);
    }

    public function test_widget_links_and_filters_in_the_query_string_open_the_all_tab_with_the_filters_applied(): void
    {
        $this->signIn(['ViewAll:DocumentRequest']);
        $submitted = $this->request('submitted', ['title' => 'Menunggu Periksa Lama', 'status_changed_at' => now()->subDays(5)]);
        $review = $this->request('review', ['title' => 'Menunggu Setuju Lama', 'status_changed_at' => now()->subDays(5)]);
        $fresh = $this->request('submitted', ['title' => 'Menunggu Periksa Baru', 'status_changed_at' => now()]);

        Livewire::withQueryParams(['filters' => ['status' => ['value' => 'submitted'], 'waiting_long' => ['isActive' => true]]])->test(ListDocumentRequests::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords([$submitted])->assertCanNotSeeTableRecords([$review, $fresh]);

        $stats = (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());
        $this->assertStringContainsString('filters', $stats[0]->getUrl());
        $this->assertStringNotContainsString('tableFilters', $stats[0]->getUrl());
        $this->get($stats[0]->getUrl())->assertOk()
            ->assertSee('Menunggu Periksa Lama')->assertDontSee('Menunggu Periksa Baru')->assertDontSee('Menunggu Setuju Lama');
        $this->get($stats[1]->getUrl())->assertOk()
            ->assertSee('Menunggu Setuju Lama')->assertDontSee('Menunggu Periksa Lama')->assertDontSee('Menunggu Periksa Baru');

        Livewire::withQueryParams(['tableFilters' => ['status' => ['value' => 'review']]])->test(ListDocumentRequests::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords([$review])->assertCanNotSeeTableRecords([$submitted, $fresh]);
    }
}
