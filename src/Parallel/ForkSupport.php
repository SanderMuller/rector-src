<?php

declare(strict_types=1);

namespace Rector\Parallel;

use PHPStan\Turbo\TurboExtensionEnabler;

/**
 * Parallel workers are forked from the main process when that is safe, and spawned as new processes otherwise.
 * A forked worker skips the boot a spawned one repeats: autoloading, the container and the PHPStan services.
 *
 * Mirrors @see https://github.com/phpstan/phpstan-src/blob/2.3.x/src/Parallel/ForkParallelChecker.php
 */
final class ForkSupport
{
    /**
     * @var string[]
     */
    private const array REQUIRED_FUNCTIONS = [
        'pcntl_fork',
        'pcntl_waitpid',
        'pcntl_wifexited',
        'pcntl_wexitstatus',
        'posix_kill',
    ];

    public static function isAvailable(): bool
    {
        // pcntl and posix do not exist on Windows
        if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
            return false;
        }

        $disabledFunctions = array_map(trim(...), explode(',', (string) ini_get('disable_functions')));
        if (array_intersect(self::REQUIRED_FUNCTIONS, $disabledFunctions) !== []) {
            return false;
        }

        // forked workers read phpstan.phar through a file handle they share with the main process,
        // and only the PHPStan Turbo extension gives each of them a handle of its own
        if (! TurboExtensionEnabler::isActive()) {
            return false;
        }

        // the JIT buffer is not safe to fill from several forked processes at once
        return ! self::isJitEnabled();
    }

    private static function isJitEnabled(): bool
    {
        if (! extension_loaded('Zend OPcache')) {
            return false;
        }

        $status = opcache_get_status(false);

        return is_array($status) && ($status['jit']['enabled'] ?? false) === true;
    }
}
