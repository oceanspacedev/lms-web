<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRequestStatusTimelineTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    /**
     * @param  list<array<string, mixed>>  $history
     * @param  array<string, mixed>  $attributes
     */
    private function request(string $status, array $history, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'public_token' => self::TOKEN, 'requester_name' => 'Pengaju Publik', 'history' => $history, ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    private function event(string $status, string $at, ?string $note = null, string $user = 'Nama Staf Rahasia'): array
    {
        return ['status' => $status, 'user' => $user, 'user_id' => 1, 'at' => $at, 'note' => $note];
    }

    /**
     * @param  list<array{label: string, state: string}>  $steps
     * @return list<string>
     */
    private function states(array $steps): array
    {
        return array_column($steps, 'state');
    }

    public function test_progress_marks_done_current_and_pending_steps(): void
    {
        $this->assertSame(['current', 'pending', 'pending', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'submitted'])->progressSteps()));
        $this->assertSame(['done', 'current', 'pending', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'signing'])->progressSteps()));
        $this->assertSame(['done', 'done', 'current', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'handover'])->progressSteps()));
        $this->assertSame(['done', 'done', 'done', 'done'], $this->states(DocumentRequest::factory()->make(['status' => 'archived'])->progressSteps()));
        $this->assertSame(['Diperiksa', 'Menunggu Tanda Tangan', 'Menunggu Dokumen Fisik', 'Selesai'], array_column(DocumentRequest::factory()->make(['status' => 'submitted'])->progressSteps(), 'label'));
    }

    public function test_legacy_statuses_do_not_break_progress_or_timeline(): void
    {
        $this->assertSame(['current', 'pending', 'pending', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'review'])->progressSteps()));
        $this->assertSame(['done', 'current', 'pending', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'approved'])->progressSteps()));
        $rejected = DocumentRequest::factory()->make(['status' => 'rejected', 'history' => [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('review', '2026-10-02T01:00:00Z'), $this->event('rejected', '2026-10-03T01:00:00Z', 'Ditolak pimpinan')]]);
        $this->assertSame(['stopped', 'pending', 'pending', 'pending'], $this->states($rejected->progressSteps()));
        $this->assertCount(3, $rejected->publicTimeline());
    }

    public function test_revision_returns_to_first_step_and_rejection_stops_at_checking(): void
    {
        $this->assertSame(['current', 'pending', 'pending', 'pending'], $this->states(DocumentRequest::factory()->make(['status' => 'revision'])->progressSteps()));

        $rejected = DocumentRequest::factory()->make(['status' => 'rejected', 'history' => [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('rejected', '2026-10-02T01:00:00Z', 'Tidak sesuai')]]);
        $this->assertSame(['stopped', 'pending', 'pending', 'pending'], $this->states($rejected->progressSteps()));
    }

    public function test_cancellation_stops_at_the_last_stage_reached(): void
    {
        $fromSigning = DocumentRequest::factory()->make(['status' => 'cancelled', 'history' => [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('signing', '2026-10-02T01:00:00Z'), $this->event('cancelled', '2026-10-03T01:00:00Z', 'Batal')]]);
        $this->assertSame(['done', 'stopped', 'pending', 'pending'], $this->states($fromSigning->progressSteps()));

        $fromHandover = DocumentRequest::factory()->make(['status' => 'cancelled', 'history' => [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('signing', '2026-10-02T01:00:00Z'), $this->event('handover', '2026-10-03T01:00:00Z'), ['event' => 'late_reason', 'status' => 'handover', 'at' => '2026-10-12T01:00:00Z', 'note' => 'Sibuk'], $this->event('cancelled', '2026-10-13T01:00:00Z', 'Batal')]]);
        $this->assertSame(['done', 'done', 'stopped', 'pending'], $this->states($fromHandover->progressSteps()));
    }

    public function test_timeline_labels_new_statuses_and_events_with_roles(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'cancelled', 'history' => [
            $this->event('submitted', '2026-10-01T01:00:00Z'),
            $this->event('signing', '2026-10-02T01:00:00Z'),
            ['event' => 'draft_replaced', 'status' => 'signing', 'user' => 'Nama Staf Rahasia', 'user_id' => 1, 'at' => '2026-10-03T01:00:00Z', 'note' => null],
            $this->event('handover', '2026-10-04T01:00:00Z'),
            ['event' => 'signed_replaced', 'status' => 'handover', 'user' => 'X', 'user_id' => null, 'at' => '2026-10-05T01:00:00Z', 'note' => null],
            ['event' => 'late_reason', 'status' => 'handover', 'user' => 'X', 'user_id' => null, 'at' => '2026-10-12T01:00:00Z', 'note' => 'Kurir terlambat'],
            $this->event('cancelled', '2026-10-13T01:00:00Z', 'Dibatalkan pemohon'),
        ]]);

        $timeline = $request->publicTimeline();
        $this->assertSame(['Dibatalkan', 'Alasan keterlambatan dari pengaju', 'Scan diganti', 'Scan diunggah, menunggu dokumen fisik', 'Draft dokumen diganti', 'Draft dokumen diterbitkan, menunggu tanda tangan', 'Pengajuan diterima'], array_column($timeline, 'label'));
        $this->assertSame(['Tim legal', 'Pengaju', 'Pengaju', 'Pengaju', 'Tim legal', 'Tim legal', 'Pengaju'], array_column($timeline, 'actor'));
        $this->assertSame('danger', $timeline[0]['tone']);
        $this->assertSame('warning', $timeline[1]['tone']);
        $this->assertSame('Kurir terlambat', $timeline[1]['note']);
    }

    public function test_timeline_is_newest_first_with_roles_notes_and_jakarta_time(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'review', 'history' => [
            $this->event('draft', '2026-09-30T01:00:00Z'),
            $this->event('submitted', '2026-10-01T01:00:00Z'),
            $this->event('revision', '2026-10-02T01:00:00Z', 'Lengkapi lampiran'),
            $this->event('submitted', '2026-10-03T01:00:00Z'),
            $this->event('review', '2026-10-04T17:30:00Z'),
        ]]);

        $timeline = $request->publicTimeline();
        $this->assertSame(['Diteruskan untuk persetujuan', 'Revisi dikirim', 'Perlu revisi', 'Pengajuan diterima'], array_column($timeline, 'label'));
        $this->assertSame(['Pemeriksa', 'Pengaju', 'Pemeriksa', 'Pengaju'], array_column($timeline, 'actor'));
        $this->assertSame('Lengkapi lampiran', $timeline[2]['note']);
        $this->assertSame('warning', $timeline[2]['tone']);
        $this->assertSame('2026-10-05 00:30', $timeline[0]['at']->format('Y-m-d H:i'));
    }

    public function test_decision_after_review_is_attributed_to_the_approver(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'rejected', 'history' => [
            $this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('review', '2026-10-02T01:00:00Z'), $this->event('rejected', '2026-10-03T01:00:00Z', 'Ditolak pimpinan'),
        ]]);

        $this->assertSame('Penyetuju', $request->publicTimeline()[0]['actor']);
        $this->assertSame('danger', $request->publicTimeline()[0]['tone']);
    }

    public function test_timeline_tolerates_history_without_time_or_note(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'submitted', 'history' => [['status' => 'submitted']]]);

        $this->assertSame([['label' => 'Pengajuan diterima', 'actor' => 'Pengaju', 'at' => null, 'note' => null, 'tone' => 'normal']], $request->publicTimeline());
    }

    public function test_public_status_page_shows_progress_and_history_without_exposing_staff_or_pic_names(): void
    {
        $pic = User::factory()->create(['name' => 'Nama PIC Rahasia']);
        $this->request('review', [
            $this->event('submitted', '2026-10-01T01:00:00Z'),
            $this->event('review', '2026-10-02T03:15:00Z', null, 'Nama Staf Rahasia'),
        ], ['pic_user_id' => $pic->id]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Tahap pengajuan')->assertSee('Menunggu Persetujuan')->assertSee('Riwayat')
            ->assertSee('Diteruskan untuk persetujuan')->assertSee('Pengajuan diterima')->assertSee('Pemeriksa')
            ->assertSee('02 Okt 2026 10:15 WIB')->assertSee('aria-current="step"', false)
            ->assertDontSee('Nama Staf Rahasia')->assertDontSee('Nama PIC Rahasia');
    }

    public function test_rejected_page_keeps_reason_and_marks_stopped_stage(): void
    {
        $this->request('rejected', [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('rejected', '2026-10-02T01:00:00Z', 'Tidak memenuhi syarat')]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Alasan penolakan')->assertSee('Tidak memenuhi syarat')->assertSee('dihentikan');
    }

    public function test_revision_page_still_shows_form_with_note(): void
    {
        $this->request('revision', [$this->event('submitted', '2026-10-01T01:00:00Z'), $this->event('revision', '2026-10-02T01:00:00Z', 'Lengkapi lampiran NIB')]);

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()
            ->assertSee('Revisi ke-1')->assertSee('Lengkapi lampiran NIB')->assertSee('Kirim revisi');
    }
}
