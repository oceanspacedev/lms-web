<?php

namespace App\Console\Commands;

use App\Services\DocumentRequestReminderSender;
use App\Services\HandoverReminderSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lms:send-request-reminders')]
#[Description('Ingatkan pemeriksa untuk pengajuan yang menunggu terlalu lama, serta pemohon dan staf untuk dokumen fisik terlambat dan scan belum diunggah')]
class SendRequestReminders extends Command
{
    public function handle(DocumentRequestReminderSender $sender, HandoverReminderSender $handoverSender): int
    {
        $this->info($sender->run().' pengingat pengajuan dijadwalkan untuk dikirim.');
        $this->info($handoverSender->run().' pengingat keterlambatan dan unggah scan dijadwalkan untuk dikirim.');

        return self::SUCCESS;
    }
}
