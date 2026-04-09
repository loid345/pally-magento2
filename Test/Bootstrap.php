<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Provides minimal stand-ins for Magento framework symbols that pure-logic
 * unit tests touch but should not require a full Magento install to exercise.
 */

if (class_exists(\Magento\Framework\Component\ComponentRegistrar::class)) {
    require_once __DIR__ . '/../registration.php';
}

// Minimal classmap-style autoloader for Pally\Payment\* so tests work even
// when Magento is not installed and PSR-4 autoloading is unavailable.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Pally\\Payment\\')) {
        return;
    }

    $relative = substr($class, strlen('Pally\\Payment\\'));
    $path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Stand-in for Magento\Sales\Model\Order — only loaded when the real Magento
// class is not autoloadable in this environment. Test/_stub is intentionally
// kept outside the Test/Unit tree so PHPUnit and the CI checks don't pick it
// up as a test or production source file.
if (!class_exists(\Magento\Sales\Model\Order::class)) {
    require_once __DIR__ . '/_stub/Magento/Sales/Model/Order.php';
}

// Stand-in for Psr\Log\LoggerInterface — CI's standalone phpunit runner
// does not ship psr/log, so we provide a thin shim here. The real package
// shadows this declaration when installed via composer.
if (!interface_exists(\Psr\Log\LoggerInterface::class)) {
    require_once __DIR__ . '/_stub/Psr/Log/LoggerInterface.php';
}
