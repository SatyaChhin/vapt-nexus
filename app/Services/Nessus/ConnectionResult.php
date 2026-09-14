<?php

namespace App\Services\Nessus;

use App\Enums\NessusServerStatus;

final readonly class ConnectionResult
{
    public function __construct(
        public bool $success,
        public NessusServerStatus $status,
        public string $message,
        public ?string $nessusStatus = null,
        public ?string $version = null,
        public ?string $edition = null,
        public ?int $latencyMs = null,
    ) {}

    public function withLatency(int $milliseconds): self
    {
        return new self(
            $this->success, $this->status, $this->message, $this->nessusStatus,
            $this->version, $this->edition, $milliseconds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'status' => $this->status->value,
            'nessus_status' => $this->nessusStatus,
            'version' => $this->version,
            'edition' => $this->edition,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
