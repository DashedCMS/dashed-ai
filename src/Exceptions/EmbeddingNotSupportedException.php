<?php

namespace Dashed\DashedAi\Exceptions;

use Exception;

class EmbeddingNotSupportedException extends Exception
{
    public static function forProvider(string $provider): self
    {
        return new self("The AI provider [{$provider}] does not support embeddings.");
    }
}
