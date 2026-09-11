<?php

namespace App\Exceptions;

use RuntimeException;

class ManualStampRequiresNoteException extends RuntimeException
{
    public static function make(): self
    {
        return new self('A manual stamp with no appointment behind it needs a note saying why it was given.');
    }
}
