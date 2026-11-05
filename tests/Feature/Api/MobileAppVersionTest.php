<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cek versi terbaru aplikasi Android (pembaruan dari dalam aplikasi).
 */
class MobileAppVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.mobile_app.github_repo' => 'eganproject/wms-cheery',
            'services.mobile_app.github_token' => null,
            'services.mobile_app.version_code' => null,
            'services.mobile_app.download_url' => null,
            'services.mobile_app.min_version_code' => 0,
        ]);
    }

    private function release(string $tag, array $extra = []): array
    {
        return array_merge([
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-11-05T01:00:00Z',
            'body' => "Perbaikan scan resi.\n\nCara pasang: buka halaman ini dari HP.",
            'assets' => [[
                'name' => 'wms-cheery-scan.apk',
                'size' => 12345,
                'url' => 'https://api.github.com/repos/eganproject/wms-cheery/releases/assets/1',
                'browser_download_url' => "https://github.com/eganproject/wms-cheery/releases/download/{$tag}/wms-cheery-scan.apk",
            ]],
        ], $extra);
    }

    public function test_returns_latest_android_release_from_github(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([
                $this->release('android-v1.0.30', ['draft' => true]),
                $this->release('v2.0.0'),
                $this->release('android-v1.0.27'),
                $this->release('android-v1.0.25'),
            ]),
        ]);

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest.version_code', 27)
            ->assertJsonPath('latest.version_name', '1.0.27')
            ->assertJsonPath('latest.download_url', 'https://github.com/eganproject/wms-cheery/releases/download/android-v1.0.27/wms-cheery-scan.apk')
            ->assertJsonPath('latest.size', 12345)
            ->assertJsonPath('latest.notes', 'Perbaikan scan resi.')
            ->assertJsonPath('min_version_code', 0);

        // Jawaban kedua dari cache, tidak memanggil GitHub lagi.
        $this->getJson('/api/mobile/app-version')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_github_failure_returns_no_update(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 500)]);

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest', null);
    }

    public function test_manual_version_from_env_skips_github(): void
    {
        Http::fake();
        config([
            'services.mobile_app.version_code' => 40,
            'services.mobile_app.version_name' => '1.0.40',
            'services.mobile_app.download_url' => 'https://wms.example.com/apk/scan.apk',
            'services.mobile_app.min_version_code' => 35,
        ]);

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest.version_code', 40)
            ->assertJsonPath('latest.download_url', 'https://wms.example.com/apk/scan.apk')
            ->assertJsonPath('min_version_code', 35);

        Http::assertNothingSent();
    }

    public function test_private_repo_downloads_through_server(): void
    {
        config(['services.mobile_app.github_token' => 'secret-token']);
        Http::fake([
            'api.github.com/repos/eganproject/wms-cheery/releases?*' => Http::response([$this->release('android-v1.0.27')]),
            'api.github.com/repos/eganproject/wms-cheery/releases/assets/1' => Http::response('APKDATA', 200),
        ]);

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest.download_url', '/api/mobile/app-download');

        $response = $this->get('/api/mobile/app-download');
        $response->assertOk();
        $this->assertSame('APKDATA', $response->streamedContent());

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_download_without_token_is_not_found(): void
    {
        Http::fake(['api.github.com/*' => Http::response([$this->release('android-v1.0.27')])]);

        $this->getJson('/api/mobile/app-download')->assertNotFound();
    }
}
