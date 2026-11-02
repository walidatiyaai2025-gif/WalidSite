<?php

namespace App\Domain\LegacyImport;

use RuntimeException;

final class LegacyImportException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'Legacy content import rejected.'
    ) {
        parent::__construct($message);
    }
}
