<?php

declare(strict_types=1);

namespace App\Kernel\Log;

use App\Kernel\Operations\ErrorReporter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/** Forwards only ERROR+ through the explicitly bounded error reporting boundary. */
final class ErrorReportingHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly ErrorReporter $reporter)
    {
        parent::__construct(Level::Error);
    }

    protected function write(LogRecord $record): void
    {
        $this->reporter->report($record->message, $record->context);
    }
}
