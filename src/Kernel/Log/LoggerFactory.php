<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;

/**
 * Builds the application logger: JSON lines to `storage/logs/app.log` and to stderr (docker logs),
 * with secrets masked by `SecretRedactor`.
 */
final class LoggerFactory
{
    public static function create(string $logDir, string $level = 'info', bool $stderr = true, ?\App\Kernel\Operations\ErrorReporter $reporter = null): Logger
    {
        $logger = new Logger('app');
        $monologLevel = match (strtolower($level)) {
            'debug' => Level::Debug,
            'notice' => Level::Notice,
            'warning' => Level::Warning,
            'error' => Level::Error,
            'critical' => Level::Critical,
            default => Level::Info,
        };
        $handlers = [new RotatingFileHandler(rtrim($logDir, '/') . '/app.log', 30, $monologLevel)];
        if ($stderr) {
            $handlers[] = new StreamHandler('php://stderr', $monologLevel);
        }
        foreach ($handlers as $handler) {
            $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true));
            $logger->pushHandler($handler);
        }
        if ($reporter !== null) {
            $logger->pushHandler(new ErrorReportingHandler($reporter));
        }
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new SecretRedactor());

        return $logger;
    }
}
