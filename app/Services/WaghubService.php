<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WaghubService
{
    public function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || preg_match('/[^0-9+\s().-]/', $phone)) {
            return null;
        }
        $phone = preg_replace('/[\s().-]/', '', $phone);
        $phone = str_starts_with($phone, '+62') ? substr($phone, 1) : $phone;
        $phone = str_starts_with($phone, '08') ? '62'.substr($phone, 1) : $phone;

        return preg_match('/^628[0-9]{8,11}$/', $phone) ? $phone : null;
    }

    public function assertConfigured(): void
    {
        foreach (['url', 'token', 'purpose'] as $key) {
            if (blank(config("services.waghub.{$key}"))) {
                throw new RuntimeException('Konfigurasi WAGHUB_'.strtoupper($key).' belum diisi.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    public function send(array $payload, string $idempotencyKey): Response
    {
        $this->assertConfigured();

        return Http::withToken(config('services.waghub.token'))
            ->acceptJson()->asJson()->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout(config('services.waghub.timeout'))->withOptions(['allow_redirects' => false])
            ->post(rtrim(config('services.waghub.url'), '/').'/api/v1/messages', $payload);
    }
}
