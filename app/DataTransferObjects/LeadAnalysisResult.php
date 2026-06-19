<?php

namespace App\DataTransferObjects;

use App\Enums\ServiceDuration;
use Carbon\Carbon;

class LeadAnalysisResult
{
    public function __construct(
        public readonly int $serviceTemplateId,
        public readonly ServiceDuration $duration,
        public readonly string $clientSlug,
        public readonly Carbon $expiresAt,
        public readonly string $source,
        public readonly string $leadReference,
        public readonly ?int $price,
        public readonly string $rawLlmResponse
    ) {}
}
