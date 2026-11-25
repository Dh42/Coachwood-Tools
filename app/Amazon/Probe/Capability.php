<?php

namespace App\Amazon\Probe;

/** One thing the probe tries once. See ProbeCatalog for the list. */
final class Capability
{
    public const REPORT = 'report';

    public const DATA_KIOSK = 'data_kiosk';

    public const API = 'api';

    /**
     * @param  'report'|'data_kiosk'|'api'  $family
     * @param  'vendor'|'seller'  $account
     * @param  array<string, mixed>  $request  report: reportType, options, download. data_kiosk: query. api: path, query, needs.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $wantedFor,
        public readonly string $family,
        public readonly string $account,
        public readonly string $marketplaceId,
        public readonly array $request,
    ) {}

    public function refreshToken(): ?string
    {
        return config("services.sp_api.{$this->account}_refresh_token");
    }
}
