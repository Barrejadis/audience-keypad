<?php
declare(strict_types=1);

/**
 * Error raised by the probe language (parser, evaluator, aggregator).
 * Carries the HTTP status the endpoint must return for the failure.
 */
class ProbeException extends Exception
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
