<?php

namespace Tests\Feature\Admin;

use App\Models\MobileAppRelease;
use App\Models\Role;
use App\Models\User;
use App\Support\ApkInfo;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pengaturan > Aplikasi Android: APK diunggah ke server, HP memperbarui
 * diri dari sini tanpa GitHub.
 */
class MobileAppReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        Storage::fake('local');
        config(['cache.default' => 'array']);
    }

    private function apk(): UploadedFile
    {
        return new UploadedFile(base_path('tests/fixtures/mobile-app/wms-scan-16.apk'), 'wms-cheery-scan.apk', null, null, true);
    }

    public function test_reads_version_from_apk(): void
    {
        $info = ApkInfo::read(base_path('tests/fixtures/mobile-app/wms-scan-16.apk'));

        $this->assertSame('id.cheerychic.wmsscan', $info->package);
        $this->assertSame(16, $info->versionCode);
        $this->assertSame('1.0.16', $info->versionName);
    }

    public function test_admin_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.mobile-app.index'))
            ->assertOk()
            ->assertSee('Unggah versi baru')
            ->assertSee(route('mobile.app-download'));
    }

    public function test_upload_apk_and_phone_gets_it_without_github(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.mobile-app.store'), [
                'apk' => $this->apk(),
                'notes' => 'Scan lebih cepat.',
                'is_required' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $release = MobileAppRelease::sole();
        $this->assertSame(16, $release->version_code);
        $this->assertSame('1.0.16', $release->version_name);
        $this->assertTrue($release->is_required);
        Storage::disk('local')->assertExists($release->file_path);

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest.version_code', 16)
            ->assertJsonPath('latest.version_name', '1.0.16')
            ->assertJsonPath('latest.download_url', '/api/mobile/app-download')
            ->assertJsonPath('latest.notes', 'Scan lebih cepat.')
            ->assertJsonPath('min_version_code', 16);

        $this->get('/api/mobile/app-download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive');

        Http::assertNothingSent();
    }

    public function test_duplicate_version_and_non_apk_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('admin.mobile-app.store'), ['apk' => $this->apk()]);

        $this->actingAs($this->admin)
            ->post(route('admin.mobile-app.store'), ['apk' => $this->apk()])
            ->assertSessionHasErrors('apk');

        $this->actingAs($this->admin)
            ->post(route('admin.mobile-app.store'), ['apk' => UploadedFile::fake()->createWithContent('x.apk', 'bukan zip')])
            ->assertSessionHasErrors('apk');

        $this->assertSame(1, MobileAppRelease::count());
    }

    public function test_inactive_release_is_not_offered(): void
    {
        Http::fake();
        $this->actingAs($this->admin)->post(route('admin.mobile-app.store'), ['apk' => $this->apk()]);
        $release = MobileAppRelease::sole();

        $this->actingAs($this->admin)
            ->put(route('admin.mobile-app.update', $release), ['notes' => '', 'is_required' => '0', 'is_active' => '0'])
            ->assertSessionHasNoErrors();

        $this->getJson('/api/mobile/app-version')
            ->assertOk()
            ->assertJsonPath('latest', null)
            ->assertJsonPath('min_version_code', 0);
        $this->get('/api/mobile/app-download')->assertNotFound();
    }

    public function test_delete_removes_file(): void
    {
        $this->actingAs($this->admin)->post(route('admin.mobile-app.store'), ['apk' => $this->apk()]);
        $release = MobileAppRelease::sole();

        $this->actingAs($this->admin)->delete(route('admin.mobile-app.destroy', $release))->assertRedirect();

        $this->assertSame(0, MobileAppRelease::count());
        Storage::disk('local')->assertMissing($release->file_path);
    }

    public function test_staff_without_permission_cannot_upload(): void
    {
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'staff-gudang')->value('id')]);

        $this->actingAs($staff)->get(route('admin.mobile-app.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.mobile-app.store'), ['apk' => $this->apk()])->assertForbidden();
    }

    public function test_github_actions_publishes_apk_with_token(): void
    {
        config(['services.mobile_app.publish_token' => 'rahasia-123']);
        $json = ['Accept' => 'application/json'];

        $this->post('/api/mobile/app-releases', ['apk' => $this->apk()], $json)->assertUnauthorized();
        $this->post('/api/mobile/app-releases', ['apk' => $this->apk()], $json + ['Authorization' => 'Bearer salah'])
            ->assertUnauthorized();

        $this->post('/api/mobile/app-releases', ['apk' => $this->apk(), 'notes' => 'Perbaikan scan'], $json + ['Authorization' => 'Bearer rahasia-123'])
            ->assertCreated()
            ->assertJsonPath('version_code', 16)
            ->assertJsonPath('created', true);

        $release = MobileAppRelease::sole();
        $this->assertSame('Perbaikan scan', $release->notes);
        $this->assertNull($release->uploaded_by);
        Storage::disk('local')->assertExists($release->file_path);

        // Workflow dijalankan ulang: tidak gagal, tidak dobel.
        $this->post('/api/mobile/app-releases', ['apk' => $this->apk()], $json + ['Authorization' => 'Bearer rahasia-123'])
            ->assertOk()
            ->assertJsonPath('created', false);
        $this->assertSame(1, MobileAppRelease::count());
    }

    public function test_publish_endpoint_is_off_without_token(): void
    {
        config(['services.mobile_app.publish_token' => null]);

        $this->post('/api/mobile/app-releases', ['apk' => $this->apk()], ['Accept' => 'application/json', 'Authorization' => 'Bearer apa-saja'])
            ->assertNotFound();
        $this->assertSame(0, MobileAppRelease::count());
    }
}
