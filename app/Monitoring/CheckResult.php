<?php

namespace App\Monitoring;

/** One probe outcome: e.g. label "backend", ok true, 12 ms, "HTTP 200". */
final class CheckResult
{
    public function __construct(
        public readonly string $label,
        public readonly bool $ok,
        public readonly ?int $ms,
        public readonly string $detail,
    ) {}

    /** @return array{label: string, ok: bool, ms: int|null, detail: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'ok' => $this->ok, 'ms' => $this->ms, 'detail' => $this->detail];
    }
}
