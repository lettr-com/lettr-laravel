<?php

namespace Lettr\Laravel\Exceptions;

use InvalidArgumentException;

class ApiKeyIsMissing extends InvalidArgumentException
{
    /**
     * Create a new exception instance.
     */
    public static function create(): self
    {
        return new self(
            'The Lettr API Key is missing. Please publish the [lettr.php] configuration file and set the [api_key].'
        );
    }
}
