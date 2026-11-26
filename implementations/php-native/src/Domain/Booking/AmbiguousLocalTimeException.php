<?php
declare(strict_types=1);

namespace Talyvane\Domain\Booking;

use InvalidArgumentException;

/** Signals a repeated wall-clock time and carries the valid UTC-offset choices. */
final class AmbiguousLocalTimeException extends InvalidArgumentException
{
    /** @param list<array{utc:\DateTimeImmutable,offset_seconds:int,label:string}> $candidates */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('This local time occurs twice because of a daylight-saving transition. Select the intended UTC offset.');
    }
}
