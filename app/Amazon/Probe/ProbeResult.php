<?php

namespace App\Amazon\Probe;

/** What happened to one capability. Holds no identifiers or amounts. */
final class ProbeResult
{
    public string $status = 'pending';

    public ?int $httpStatus = null;

    public ?string $errorCode = null;

    public ?string $errorMessage = null;

    /** @var list<string> */
    public array $fieldsRequested = [];

    /** @var list<string> */
    public array $fieldsRejected = [];

    /** @var array<string, array<string, mixed>> */
    public array $fieldStats = [];

    public ?int $rowCount = null;

    /** @var array<string, mixed> */
    public array $notes = [];

    /** Amazon's report or query id while pending. Never stored or printed. */
    public ?string $amazonId = null;

    public function __construct(public readonly Capability $capability) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function toArray(): array
    {
        return [
            'key' => $this->capability->key,
            'label' => $this->capability->label,
            'wanted_for' => $this->capability->wantedFor,
            'family' => $this->capability->family,
            'account' => $this->capability->account,
            'marketplace_id' => $this->capability->marketplaceId,
            'status' => $this->status,
            'http_status' => $this->httpStatus,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'fields_requested' => $this->fieldsRequested,
            'fields_rejected' => $this->fieldsRejected,
            'row_count' => $this->rowCount,
            'field_stats' => $this->fieldStats,
            'notes' => $this->notes,
        ];
    }
}
