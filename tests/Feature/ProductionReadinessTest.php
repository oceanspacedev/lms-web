<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    private function productionConfiguration(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://lms.example.test', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'session.secure' => true, 'session.http_only' => true, 'session.driver' => 'database', 'cache.default' => 'database', 'debugbar.enabled' => false,
            'filesystems.disks.s3.key' => 'dummy-key', 'filesystems.disks.s3.secret' => 'dummy-secret', 'filesystems.disks.s3.bucket' => 'dummy-lms',
            'filesystems.disks.s3.endpoint' => 'https://storage.example.test', 'filesystems.disks.s3.visibility' => 'private', 'filesystems.disks.s3.http.verify' => true,
            'services.waghub.url' => 'https://waghub.example.test', 'services.waghub.token' => 'dummy-token', 'services.waghub.purpose' => 'dummy-reminder',
        ]);
        $this->app->instance('config_loaded_from_cache', true);
    }

    public function test_safe_production_configuration_passes_without_external_requests_or_secret_output(): void
    {
        $this->productionConfiguration();
        Http::preventStrayRequests();
        $this->artisan('lms:check-production')->doesntExpectOutputToContain('dummy-secret')->doesntExpectOutputToContain('dummy-token')->assertSuccessful();
        Http::assertNothingSent();
    }

    public static function unsafeSettings(): array
    {
        return [['app.debug', true], ['app.url', 'http://lms.example.test'], ['session.secure', false], ['cache.default', 'array'], ['filesystems.disks.s3.visibility', 'public'], ['filesystems.disks.s3.http.verify', false], ['services.waghub.token', null]];
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_production_configuration_is_rejected(string $key, mixed $value): void
    {
        $this->productionConfiguration();
        config([$key => $value]);
        $this->artisan('lms:check-production')->expectsOutputToContain('PERLU DIATUR')->assertFailed();
    }
}
