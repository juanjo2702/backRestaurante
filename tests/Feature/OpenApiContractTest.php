<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_openapi_yaml_is_parseable_and_contains_new_contracts(): void
    {
        $sourcePath = base_path('openapi/openapi.yaml');
        $publicPath = public_path('docs/openapi.yaml');

        $this->assertFileExists($sourcePath);
        $this->assertFileExists($publicPath);
        $this->assertSame(file_get_contents($sourcePath), file_get_contents($publicPath));

        $document = Yaml::parseFile($sourcePath);

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertArrayHasKey('/api/v1/tables/{mesa}/qr.svg', $document['paths']);
        $this->assertArrayHasKey('/api/v1/tables/qr/bulk-export', $document['paths']);
        $this->assertArrayHasKey('/api/v1/payments/mock-checkout/submit', $document['paths']);
        $this->assertArrayHasKey('/api/public/payments/mock-checkout/submit', $document['paths']);
        $this->assertArrayHasKey('MockCheckoutSession', $document['components']['schemas']);
        $this->assertArrayHasKey('LoyaltyPointTransaction', $document['components']['schemas']);
    }
}
