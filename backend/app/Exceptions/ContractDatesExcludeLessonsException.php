<?php

namespace App\Exceptions;

/** In-app #464: thrown inside the course-update transaction to roll it back; the controller turns it into a 422. */
final class ContractDatesExcludeLessonsException extends \RuntimeException
{
    /** @param list<string> $lessonDates */
    public function __construct(public readonly array $lessonDates, string $message)
    {
        parent::__construct($message);
    }
}
