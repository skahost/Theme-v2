<?php

namespace DriftRegister\Sso;

use RuntimeException;

/** A sign-in problem with a short machine-readable reason the pages turn into a message. */
final class SsoException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
