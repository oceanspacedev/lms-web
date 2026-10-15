<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tes yang me-reset database hanya boleh berjalan di database memori dari phpunit.xml.
     * Menjalankan phpunit tanpa konfigurasi (misalnya --no-configuration) memakai .env dan akan mengosongkan database/database.sqlite.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $uses = class_uses_recursive(static::class);
        $resetsDatabase = array_intersect([RefreshDatabase::class, DatabaseMigrations::class, DatabaseTruncation::class], $uses) !== [];
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($resetsDatabase && (! $this->app->environment('testing') || $database !== ':memory:')) {
            throw new RuntimeException("Tes dihentikan: reset database hanya boleh di database memori (APP_ENV=testing), bukan [{$connection}] {$database}. Jalankan lewat php artisan test atau phpunit dengan phpunit.xml.");
        }

        return parent::setUpTraits();
    }
}
