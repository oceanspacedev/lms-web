<?php

namespace App\Console\Commands;

use App\Services\DocumentRequestNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lms:send-request-notifications')]
#[Description('Kirim ulang notifikasi WhatsApp pengajuan yang belum diterima WagHub')]
class SendRequestNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocumentRequestNotifier $notifier): int
    {
        $this->info($notifier->run().' notifikasi diterima WagHub.');

        return self::SUCCESS;
    }
}
