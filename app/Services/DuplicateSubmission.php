<?php

namespace App\Services;

use RuntimeException;

/** The submission's key was already used; the message names the record it created. */
class DuplicateSubmission extends RuntimeException
{
    public function __construct(public readonly string $reference)
    {
        parent::__construct('This was already recorded ('.$reference.'). It was not saved a second time.');
    }
}
