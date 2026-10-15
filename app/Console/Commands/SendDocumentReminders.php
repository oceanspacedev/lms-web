<?php

namespace App\Console\Commands;

use App\Services\DocumentReminderSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('lms:send-reminders {--dry-run : Periksa jadwal tanpa mengirim atau membuat log}')]
#[Description('Kirim pengingat WhatsApp untuk dokumen yang akan berakhir')]
class SendDocumentReminders extends Command
{
    public function handle(DocumentReminderSender $sender): int
    {
        try {
            $result = $sender->run((bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Jadwal: {$result['due']}; diterima WagHub: {$result['accepted']}; gagal: {$result['failed']}; dibatalkan: {$result['cancelled']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
