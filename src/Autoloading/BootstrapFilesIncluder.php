<?php

declare(strict_types=1);

namespace Rector\Autoloading;

use PHPStan\DependencyInjection\Container;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\DependencyInjection\PHPStanServicesFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Webmozart\Assert\Assert;

/**
 * @see \Rector\Tests\Autoloading\BootstrapFilesIncluderTest
 */
final class BootstrapFilesIncluder
{
    private bool $isDeferred = false;

    public function __construct(
        private readonly PHPStanServicesFactory $phpStanServicesFactory
    ) {
    }

    /**
     * Inspired by
     * @see https://github.com/phpstan/phpstan-src/commit/aad1bf888ab7b5808898ee5fe2228bb8bb4e4cf1
     */
    public function includeBootstrapFiles(Container $container): void
    {
        $bootstrapFiles = SimpleParameterProvider::provideArrayParameter(Option::BOOTSTRAP_FILES);

        Assert::allString($bootstrapFiles);

        /** @var string[] $bootstrapFiles */
        foreach ($bootstrapFiles as $bootstrapFile) {
            if (! is_file($bootstrapFile)) {
                throw new ShouldNotHappenException(sprintf('Bootstrap file "%s" does not exist.', $bootstrapFile));
            }

            // mimic PHPStan bootstrap file inclusion (bootstrap files have access to the global $container variable)
            (static function (string $file) use ($container): void {
                require $file;
            })($bootstrapFile);
        }

        $this->requireRectorStubs();
    }

    /**
     * Holds the bootstrap files back until includeDeferredBootstrapFiles(), so no user code runs in the main process
     * before it forks the parallel workers, like in PHPStan
     * @see https://github.com/phpstan/phpstan-src/commit/55d9d9bcc
     */
    public function defer(): void
    {
        $this->isDeferred = true;
    }

    public function includeDeferredBootstrapFiles(): void
    {
        if (! $this->isDeferred) {
            return;
        }

        $this->isDeferred = false;
        $this->includeBootstrapFiles($this->phpStanServicesFactory->getContainer());
    }

    private function requireRectorStubs(): void
    {
        $stubsRectorDirectory = realpath(__DIR__ . '/../../stubs-rector');
        if ($stubsRectorDirectory === false) {
            return;
        }

        $dir = new RecursiveDirectoryIterator($stubsRectorDirectory, RecursiveDirectoryIterator::SKIP_DOTS);

        $stubs = new RecursiveIteratorIterator($dir);

        foreach ($stubs as $stub) {
            /** @var SplFileInfo $stub */
            require_once $stub->getRealPath();
        }
    }
}
