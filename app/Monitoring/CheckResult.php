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
        /** Set for a check of an alternate address: its position in the list. */
        public readonly ?int $via = null,
    ) {}

    /** @return array{label: string, ok: bool, ms: int|null, detail: string, via?: int} */
    public function toArray(): array
    {
        $out = ['label' => $this->label, 'ok' => $this->ok, 'ms' => $this->ms, 'detail' => $this->detail];
        if ($this->via !== null) {
            $out['via'] = $this->via;
        }

        return $out;
    }
}
