<?php

declare(strict_types=1);

namespace App\Services;

class VerificationResult
{
    public bool $isValid;
    public array $errors;
    public array $warnings;
    public array $data;

    private function __construct(bool $isValid, array $errors, array $warnings, array $data)
    {
        $this->isValid   = $isValid;
        $this->errors    = $errors;
        $this->warnings  = $warnings;
        $this->data      = $data;
    }

    public static function ok(array $warnings = [], array $data = []): self
    {
        return new self(true, [], $warnings, $data);
    }

    public static function fail(array $errors, array $warnings = [], array $data = []): self
    {
        return new self(false, $errors, $warnings, $data);
    }
}
