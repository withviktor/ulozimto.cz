<?php

namespace App\Message;

final class ScanFileMessage
{
    public function __construct(
        public readonly string $fileId,
    ) {}
}
