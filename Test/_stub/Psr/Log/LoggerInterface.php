<?php

declare(strict_types=1);

namespace Psr\Log;

/**
 * Minimal PSR-3 LoggerInterface stub for unit tests that run outside a
 * composer-managed environment (e.g. the standalone phpunit runner used
 * in CI). The real psr/log interface fully shadows this one when the
 * package is autoloadable.
 *
 * Only the method signatures the module actually calls are declared.
 */
interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log($level, string|\Stringable $message, array $context = []): void;
}
