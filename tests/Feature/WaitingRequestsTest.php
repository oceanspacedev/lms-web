<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Widgets\WaitingRequestsOverview;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WaitingRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function request(string $status, int $waitingDays, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'status_changed_at' => now()->subDays($waitingDays),
            'history' => [['status' => $status, 'at' => now()->subDays($waitingDays)->toIso8601String(), 'note' => null]], ...$attributes,
        ]);
    }

    public function test_scope_selects_only_waiting_statuses_older_than_the_threshold_inclusively(): void
    {
        $exactly = $this->request('submitted', 2);
        $older = $this->request('review', 9);
        $almost = $this->request('submitted', 2, ['status_changed_at' => now()->subDays(2)->addMinute()]);
        $fresh = $this->request('review', 0);
        $others = collect(['draft', 'revision', 'approved', 'rejected', 'archived'])->map(fn (string $status): DocumentRequest => $this->request($status, 30));

        $ids = DocumentRequest::query()->waitingLongerThan(2)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$exactly->id, $older->id], $ids);
        $this->assertNotContains($almost->id, $ids);
        $this->assertNotContains($fresh->id, $ids);
        $this->assertSame([], array_intersect($others->pluck('id')->all(), $ids));
    }

    public function test_scope_falls_back_to_updated_at_when_status_changed_at_is_missing(): void
    {
        $legacy = $this->request('submitted', 0, ['status_changed_at' => null, 'updated_at' => now()->subDays(5)]);

        $this->assertSame([$legacy->id], DocumentRequest::query()->waitingLongerThan(2)->pluck('id')->all());
    }

    public function test_waiting_since_prefers_the_column_then_history_then_updated_at(): void
    {
        $column = $this->request('submitted', 4);
        $this->assertTrue($column->waitingSince()->equalTo(CarbonImmutable::parse(now()->subDays(4))));

        $history = $this->request('submitted', 0, ['status_changed_at' => null, 'history' => [['status' => 'submitted', 'at' => now()->subDays(6)->toIso8601String()]]]);
        $this->assertTrue($history->waitingSince()->equalTo(CarbonImmutable::parse(now()->subDays(6))));

        $bare = $this->request('submitted', 0, ['status_changed_at' => null, 'history' => [], 'updated_at' => now()->subDays(8)]);
        $this->assertTrue($bare->waitingSince()->equalTo(CarbonImmutable::parse(now()->subDays(8))));
    }

    public function test_transition_stamps_status_changed_at_and_restarts_the_waiting_clock(): void
    {
        $reviewer = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest']);
        $request = $this->request('submitted', 10, ['requester_id' => null, 'reviewer_id' => $reviewer->id, 'requester_name' => 'Pengaju', 'requester_phone' => '081234567890']);
        $this->assertCount(1, DocumentRequest::query()->waitingLongerThan(2)->get());

        $request->transition('review', $reviewer);

        $request->refresh();
        $this->assertSame('review', $request->status);
        $this->assertTrue($request->status_changed_at->equalTo(now()));
        $this->assertSame(0, DocumentRequest::query()->waitingLongerThan(2)->count());
        $this->travel(3)->days();
        $this->assertSame(1, DocumentRequest::query()->waitingLongerThan(2)->count());
    }

    public function test_table_filter_shows_only_requests_waiting_too_long_and_follows_the_configured_threshold(): void
    {
        $this->signIn();
        $stale = $this->request('submitted', 4);
        $fresh = $this->request('submitted', 1);
        $slowReview = $this->request('review', 3);

        Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords([$stale, $fresh, $slowReview])
            ->filterTable('waiting_long', true)
            ->assertCanSeeTableRecords([$stale, $slowReview])->assertCanNotSeeTableRecords([$fresh])
            ->filterTable('status', 'review')
            ->assertCanSeeTableRecords([$slowReview])->assertCanNotSeeTableRecords([$stale, $fresh]);

        ReminderTemplate::factory()->create(['request_reminder_after_days' => 4]);
        Livewire::test(ListDocumentRequests::class)->filterTable('waiting_long', true)
            ->assertCanSeeTableRecords([$stale])->assertCanNotSeeTableRecords([$fresh, $slowReview])
            ->assertSee('Menunggu lebih dari 4 hari');
    }

    public function test_widget_counts_each_stage_with_the_number_waiting_too_long_and_links_to_the_filtered_list(): void
    {
        $this->signIn();
        $this->request('submitted', 5);
        $this->request('submitted', 0);
        $this->request('review', 3);
        $this->request('approved', 30);

        $stats = (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());

        $this->assertCount(2, $stats);
        $this->assertSame('Menunggu Pemeriksaan', $stats[0]->getLabel());
        $this->assertSame(2, $stats[0]->getValue());
        $this->assertSame('1 menunggu lebih dari 2 hari', (string) $stats[0]->getDescription());
        $this->assertStringContainsString('waiting_long', rawurldecode($stats[0]->getUrl()));
        $this->assertStringContainsString('submitted', rawurldecode($stats[0]->getUrl()));
        $this->assertSame('Menunggu Persetujuan', $stats[1]->getLabel());
        $this->assertSame(1, $stats[1]->getValue());
    }

    public function test_widget_without_stale_requests_does_not_link_to_the_stale_filter(): void
    {
        $this->signIn();
        $this->request('submitted', 0);

        $stats = (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());

        $this->assertSame('Tidak ada yang menunggu lebih dari 2 hari', (string) $stats[0]->getDescription());
        $this->assertStringNotContainsString('waiting_long', rawurldecode($stats[0]->getUrl()));
    }

    public function test_widget_only_counts_requests_the_user_may_see(): void
    {
        $me = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest']);
        $mine = $this->request('submitted', 5, ['requester_id' => $me->id]);
        $this->request('submitted', 5);

        $stats = (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());

        $this->assertSame(1, $stats[0]->getValue());
        $this->assertNotNull($mine);
    }

    public function test_widget_is_hidden_when_nothing_is_waiting_or_without_permission(): void
    {
        $this->signIn();
        $this->request('approved', 30);
        $this->assertFalse(WaitingRequestsOverview::canView());

        $this->request('review', 0);
        $this->assertTrue(WaitingRequestsOverview::canView());
        $this->assertContains(WaitingRequestsOverview::class, Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(WaitingRequestsOverview::canView());
        Livewire::test(WaitingRequestsOverview::class)->assertForbidden();
    }
}
