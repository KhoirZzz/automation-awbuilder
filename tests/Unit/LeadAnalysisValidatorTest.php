<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Support\LeadAnalysisValidator;
use App\Models\ServiceTemplate;
use App\Models\Deployment;
use App\Enums\ServiceDuration;
use App\Enums\DeploymentStatus;
use App\Exceptions\InvalidLeadAnalysisException;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LeadAnalysisValidatorTest extends TestCase
{
    use RefreshDatabase;

    private LeadAnalysisValidator $validator;
    private ServiceTemplate $activeTemplate;
    private ServiceTemplate $inactiveTemplate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new LeadAnalysisValidator();

        // Setup active and inactive templates
        $this->activeTemplate = ServiceTemplate::create([
            'key' => 'shopee-bot',
            'name' => 'Shopee Bot',
            'template_path' => 'shopee-bot',
            'is_active' => true,
        ]);

        $this->inactiveTemplate = ServiceTemplate::create([
            'key' => 'old-scraper',
            'name' => 'Old Scraper',
            'template_path' => 'old-scraper',
            'is_active' => false,
        ]);
    }

    public function test_valid_lead_analysis_passes(): void
    {
        $rawResponse = [
            'service_key' => 'shopee-bot',
            'durasi' => '1_minggu',
            'client_slug_request' => 'toko-abc'
        ];

        $result = $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');

        $this->assertEquals($this->activeTemplate->id, $result->serviceTemplateId);
        $this->assertEquals(ServiceDuration::ONE_WEEK, $result->duration);
        $this->assertEquals('toko-abc', $result->clientSlug);
        $this->assertEquals('telegram', $result->source);
        $this->assertEquals('tg_ref_123', $result->leadReference);
    }

    public function test_inactive_service_key_fails(): void
    {
        $rawResponse = [
            'service_key' => 'old-scraper',
            'durasi' => '1_minggu',
            'client_slug_request' => 'toko-abc'
        ];

        $this->expectException(InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/not found or is inactive/');

        $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
    }

    public function test_invalid_duration_fails(): void
    {
        $rawResponse = [
            'service_key' => 'shopee-bot',
            'durasi' => '10_tahun',
            'client_slug_request' => 'toko-abc'
        ];

        $this->expectException(InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/Invalid duration value/');

        $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
    }

    public function test_reserved_word_slug_fails(): void
    {
        $rawResponse = [
            'service_key' => 'shopee-bot',
            'durasi' => '1_minggu',
            'client_slug_request' => 'admin'
        ];

        $this->expectException(InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/reserved system word/');

        $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
    }

    public function test_invalid_dns_slug_fails(): void
    {
        $rawResponse = [
            'service_key' => 'shopee-bot',
            'durasi' => '1_minggu',
            'client_slug_request' => 'toko_abc' // underscores not allowed in DNS label
        ];

        $this->expectException(InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/violates DNS labeling rules/');

        $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
    }

    public function test_duplicate_active_slug_fails(): void
    {
        // Pre-create an active deployment with the same slug
        Deployment::create([
            'source' => 'telegram',
            'lead_reference' => 'prev_ref',
            'service_template_id' => $this->activeTemplate->id,
            'client_slug' => 'toko-abc',
            'instance_path' => '/var/www/deployments/toko-abc',
            'started_at' => now(),
            'expires_at' => now()->addWeek(),
            'status' => DeploymentStatus::ACTIVE,
        ]);

        $rawResponse = [
            'service_key' => 'shopee-bot',
            'durasi' => '1_minggu',
            'client_slug_request' => 'toko-abc'
        ];

        $this->expectException(InvalidLeadAnalysisException::class);
        $this->expectExceptionMessageMatches('/already in use/');

        $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
    }

    public function test_price_normalization(): void
    {
        $testCases = [
            ['input' => 100000, 'expected' => 100000],
            ['input' => '150000', 'expected' => 150000],
            ['input' => '100k', 'expected' => 100000],
            ['input' => '100rb', 'expected' => 100000],
            ['input' => '100ribu', 'expected' => 100000],
            ['input' => '1.5jt', 'expected' => 1500000],
            ['input' => '1juta', 'expected' => 1000000],
            ['input' => 'Rp. 150.000', 'expected' => 150000],
        ];

        foreach ($testCases as $case) {
            $rawResponse = [
                'service_key' => 'shopee-bot',
                'durasi' => '1_minggu',
                'client_slug_request' => 'toko-abc',
                'price' => $case['input']
            ];

            $result = $this->validator->validate($rawResponse, 'telegram', 'tg_ref_123');
            $this->assertEquals($case['expected'], $result->price, "Failed for input: " . $case['input']);
        }
    }

    public function test_calculate_price_for_duration(): void
    {
        // Test base price 100,000 (W)
        $w100 = 100000;
        $this->assertEquals(100000, LeadAnalysisValidator::calculatePriceForDuration($w100, '1_minggu'));
        $this->assertEquals(350000, LeadAnalysisValidator::calculatePriceForDuration($w100, '1_bulan')); // 2 * 100k + 150k = 350k
        $this->assertEquals((int)(350000 * 3 * 0.9), LeadAnalysisValidator::calculatePriceForDuration($w100, '3_bulan'));
        $this->assertEquals((int)(350000 * 6 * 0.8), LeadAnalysisValidator::calculatePriceForDuration($w100, '6_bulan'));
        $this->assertEquals((int)(350000 * 12 * 0.7), LeadAnalysisValidator::calculatePriceForDuration($w100, '1_tahun'));

        // Test base price 125,000 (W)
        $w125 = 125000;
        $this->assertEquals(125000, LeadAnalysisValidator::calculatePriceForDuration($w125, '1_minggu'));
        $this->assertEquals(400000, LeadAnalysisValidator::calculatePriceForDuration($w125, '1_bulan')); // 2 * 125k + 150k = 400k

        // Test base price 50,000 (W <= 75k)
        $w50 = 50000;
        $this->assertEquals(175000, LeadAnalysisValidator::calculatePriceForDuration($w50, '1_bulan')); // 3.5 * 50k = 175k
    }
}
