<?php

namespace App\Support;

use App\DataTransferObjects\LeadAnalysisResult;
use App\Enums\ServiceDuration;
use App\Enums\DeploymentStatus;
use App\Exceptions\InvalidLeadAnalysisException;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use Illuminate\Support\Facades\Log;

class LeadAnalysisValidator
{
    /**
     * Validate raw LLM response and return a strongly typed LeadAnalysisResult DTO.
     *
     * @param array $rawResponse
     * @param string $source
     * @param string $leadReference
     * @return LeadAnalysisResult
     * @throws InvalidLeadAnalysisException
     */
    public function validate(array $rawResponse, string $source, string $leadReference): LeadAnalysisResult
    {
        $serviceKey = $rawResponse['service_key'] ?? null;
        $durationString = $rawResponse['durasi'] ?? null;
        $slugRequest = $rawResponse['client_slug_request'] ?? null;

        $errors = [];

        // 1. Service key check
        $serviceTemplate = null;
        if (empty($serviceKey)) {
            $errors[] = 'Service key is empty.';
        } else {
            $serviceTemplate = ServiceTemplate::where('key', $serviceKey)
                ->where('is_active', true)
                ->first();
            if (!$serviceTemplate) {
                $errors[] = "Service template with key '{$serviceKey}' not found or is inactive.";
            }
        }

        // 2. Duration check
        $durationEnum = null;
        if (empty($durationString)) {
            $errors[] = 'Duration is empty.';
        } else {
            $durationEnum = ServiceDuration::tryFrom($durationString);
            if (!$durationEnum) {
                $errors[] = "Invalid duration value '{$durationString}'.";
            }
        }

        // 3. Client slug sanitization & check
        $clientSlug = null;
        if (empty($slugRequest)) {
            $errors[] = 'Client slug request is empty.';
        } else {
            $clientSlug = strtolower(trim($slugRequest));

            // Regex for DNS label (RFC 1035): starts and ends with alphanumeric, contains only alphanumeric and hyphens, max 63 chars
            if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $clientSlug)) {
                $errors[] = "Client slug '{$clientSlug}' violates DNS labeling rules (must be 2-63 chars, alphanumeric and single hyphens only).";
            }

            // Explicit rejection of directory separators or whitespaces
            if (str_contains($clientSlug, '..') || str_contains($clientSlug, '/') || str_contains($clientSlug, '\\') || preg_match('/\s/', $clientSlug)) {
                $errors[] = "Client slug '{$clientSlug}' contains forbidden characters (whitespace, slash, or dot-dot).";
            }

            // Reserved words rejection
            $reservedSlugs = config('deploy.reserved_slugs', []);
            if (in_array($clientSlug, $reservedSlugs, true)) {
                $errors[] = "Client slug '{$clientSlug}' is a reserved system word.";
            }

            // Check if active or pending deployment exists
            $existingDeployment = Deployment::where('client_slug', $clientSlug)
                ->whereIn('status', [DeploymentStatus::ACTIVE->value, DeploymentStatus::PENDING->value])
                ->exists();
            if ($existingDeployment) {
                $errors[] = "Client slug '{$clientSlug}' is already in use by an active or pending deployment.";
            }
        }

        // If any error exists, log to deploy-audit and throw exception
        if (!empty($errors)) {
            Log::channel('deploy-audit')->warning('Lead analysis validation failed.', [
                'lead_reference' => $leadReference,
                'source' => $source,
                'raw_response' => $rawResponse,
                'errors' => $errors
            ]);

            throw new InvalidLeadAnalysisException('Validation failed: ' . implode(' | ', $errors));
        }

        $rawPrice = $rawResponse['price'] ?? $rawResponse['harga'] ?? null;
        $price = null;
        if (!empty($rawPrice)) {
            if (is_numeric($rawPrice)) {
                $price = (int)$rawPrice;
            } else {
                $clean = strtolower(trim((string)$rawPrice));
                // Check if it contains multiplier words
                if (preg_match('/(k|rb|ribu|jt|juta)/', $clean)) {
                    $clean = str_replace(',', '.', $clean); // Standardize decimals
                    // Keep numbers, letters, and dots
                    $clean = preg_replace('/[^0-9a-z.]/', '', $clean);
                    if (preg_match('/^([\d.]+)(k|rb|ribu)$/', $clean, $matches)) {
                        $price = (int)((float)$matches[1] * 1000);
                    } elseif (preg_match('/^([\d.]+)(jt|juta)$/', $clean, $matches)) {
                        $price = (int)((float)$matches[1] * 1000000);
                    }
                } else {
                    // Remove all formatting characters except digits
                    $clean = preg_replace('/\D/', '', $clean);
                    if (!empty($clean)) {
                        $price = (int)$clean;
                    }
                }
            }
        }

        if (empty($price) && $serviceTemplate && !empty($serviceTemplate->price)) {
            $price = self::calculatePriceForDuration((int)$serviceTemplate->price, $durationString);
        }

        $expiresAt = $durationEnum->calculateExpiry();

        return new LeadAnalysisResult(
            serviceTemplateId: $serviceTemplate->id,
            duration: $durationEnum,
            clientSlug: $clientSlug,
            expiresAt: $expiresAt,
            source: $source,
            leadReference: $leadReference,
            price: $price,
            rawLlmResponse: json_encode($rawResponse)
        );
    }

    /**
     * Calculate price based on base price (1 week) and selected duration.
     */
    public static function calculatePriceForDuration(int $basePrice, string $duration): int
    {
        switch ($duration) {
            case '1_minggu':
                return $basePrice;
            case '1_bulan':
                // Formula: 2 * W + 150k
                if ($basePrice <= 75000) {
                    return (int)($basePrice * 3.5);
                }
                return ($basePrice * 2) + 150000;
            case '3_bulan':
                $monthlyPrice = self::calculatePriceForDuration($basePrice, '1_bulan');
                return (int)($monthlyPrice * 3 * 0.9);
            case '6_bulan':
                $monthlyPrice = self::calculatePriceForDuration($basePrice, '1_bulan');
                return (int)($monthlyPrice * 6 * 0.8);
            case '1_tahun':
                $monthlyPrice = self::calculatePriceForDuration($basePrice, '1_bulan');
                return (int)($monthlyPrice * 12 * 0.7);
            default:
                return $basePrice;
        }
    }
}
