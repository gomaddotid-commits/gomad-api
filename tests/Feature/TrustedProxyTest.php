<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_forwarded_https_is_used_for_vite_asset_urls(): void
    {
        Vite::useHotFile(sys_get_temp_dir().'/gomad-vite-hot-'.bin2hex(random_bytes(8)));

        $response = $this->withHeader('X-Forwarded-Proto', 'https')->get('/');

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '/(?:href|src)="https:\/\/[^"]+\/build\/assets\//',
            $response->getContent(),
        );
    }
}
