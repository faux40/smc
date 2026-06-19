<?php

namespace Tests\Feature\Tenancy;

use App\Support\CertificateData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertificateBackgroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_background_data_uri_is_null_when_no_file_exists(): void
    {
        config(['certificates.background' => '/no/such/file.png']);

        $this->assertNull(CertificateData::backgroundDataUri());
    }

    public function test_background_data_uri_encodes_the_configured_image(): void
    {
        // A 1×1 PNG written to a temp file as the configured background.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $path = tempnam(sys_get_temp_dir(), 'certbg').'.png';
        file_put_contents($path, $png);
        config(['certificates.background' => $path]);

        try {
            $uri = CertificateData::backgroundDataUri();
            $this->assertNotNull($uri);
            $this->assertStringStartsWith('data:image/png;base64,', $uri);
            $this->assertStringContainsString(base64_encode($png), $uri);
        } finally {
            @unlink($path);
        }
    }
}
