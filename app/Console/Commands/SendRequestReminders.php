<?php

namespace App\Console\Commands;

use App\Services\DocumentRequestReminderSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lms:send-request-reminders')]
#[Description('Ingatkan pemeriksa atau penyetuju untuk pengajuan yang menunggu terlalu lama')]
class SendRequestReminders extends Command
{
    public function handle(DocumentRequestReminderSender $sender): int
    {
        $this->info($sender->run().' pengingat pengajuan dijadwalkan untuk dikirim.');

        return self::SUCCESS;
    }
}
