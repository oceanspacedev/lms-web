<?php

namespace Tests\Feature;

use Illuminate\Foundation\Console\ServeCommand;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DevelopmentServerTemporaryFileTest extends TestCase
{
    public function test_livewire_can_create_a_temporary_upload_with_the_development_server_environment(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('The Windows development server needs the system temporary directory variables.');
        }

        $command = new ServeCommand;
        $shouldPassThrough = new ReflectionMethod(ServeCommand::class, 'shouldPassThroughEnvironmentVariable');
        $environment = [];

        foreach ($_ENV as $key => $value) {
            $environment[$key] = $shouldPassThrough->invoke($command, $key) ? $value : false;
        }

        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $file = new Livewire\Features\SupportFileUploads\TemporaryUploadedFile('document.pdf', 'local');
            exit($file->isValid() ? 0 : 1);
            PHP,
        ], base_path(), $environment);

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
    }
}
