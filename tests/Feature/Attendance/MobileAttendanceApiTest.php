<?php

namespace Tests\Feature\Attendance;

use App\Models\AppSetting;
use App\Models\AttendancePunch;
use App\Models\MobileToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MobileAttendanceApiTest extends AttendanceTestCase
{
    protected User $packer;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->packer = User::create([
            'name' => 'Rina', 'email' => 'rina@cheery.test', 'password' => 'password', 'is_active' => true,
            'role_id' => Role::where('slug', 'staff-gudang')->value('id'),
        ]);
        [, $this->token] = MobileToken::issue($this->packer, 'HP Rina');
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->json($method, $uri, $data);
    }

    public function test_account_without_employee_gets_a_clear_message(): void
    {
        $this->api('GET', '/api/mobile/absen')->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'belum terhubung'));
        $this->api('GET', '/api/mobile/me')->assertJsonPath('user.can.absen', false);
    }

    public function test_check_in_inside_radius_with_selfie(): void
    {
        $this->employee(['user_id' => $this->packer->id]);
        $this->warehouse();

        $this->api('GET', '/api/mobile/me')->assertJsonPath('user.can.absen', true);
        $this->api('GET', '/api/mobile/absen')->assertOk()
            ->assertJsonPath('next_action', 'in')
            ->assertJsonPath('shift.label', 'Reguler 08:00–17:00')
            ->assertJsonPath('require_selfie', true);

        // Tanpa selfie ditolak.
        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2577, 'longitude' => 112.7522])
            ->assertStatus(422)->assertJsonValidationErrors('selfie');

        $this->travelTo(now()->setTime(7, 58));
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)->post('/api/mobile/absen', [
            'type' => 'in', 'latitude' => -7.2577, 'longitude' => 112.7522, 'accuracy' => 12,
            'selfie' => UploadedFile::fake()->image('selfie.jpg', 480, 640),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('punch.time', '07:58')
            ->assertJsonPath('punch.location', 'Gudang Cheery Chic')
            ->assertJsonPath('today.next_action', 'out')
            ->assertJsonPath('today.day.label', 'Hadir');

        $punch = AttendancePunch::firstOrFail();
        $this->assertSame('phone', $punch->source);
        $this->assertFalse($punch->outside_radius);
        $this->assertLessThan(100, $punch->distance_meters);
        Storage::disk('local')->assertExists($punch->selfie_path);
    }

    public function test_outside_radius_is_refused_unless_allowed(): void
    {
        $this->employee(['user_id' => $this->packer->id]);
        $this->warehouse();
        AppSetting::put(AppSetting::ATTENDANCE_REQUIRE_SELFIE, '0');

        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2675, 'longitude' => 112.7521])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location')
            ->assertJsonPath('errors.location.0', fn ($m) => str_contains($m, '1,1 km'));

        AppSetting::put(AppSetting::ATTENDANCE_ALLOW_OUTSIDE, '1');
        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2675, 'longitude' => 112.7521])
            ->assertCreated()->assertJsonPath('punch.outside_radius', true);
    }

    public function test_fake_gps_and_machine_only_employees_are_refused(): void
    {
        $employee = $this->employee(['user_id' => $this->packer->id]);
        $this->warehouse();
        AppSetting::put(AppSetting::ATTENDANCE_REQUIRE_SELFIE, '0');

        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2575, 'longitude' => 112.7521, 'is_mock' => true])
            ->assertStatus(422)->assertJsonValidationErrors('location');

        $employee->update(['attendance_method' => 'machine', 'machine_pin' => '1']);
        $this->app['auth']->forgetGuards();
        $this->api('GET', '/api/mobile/absen')->assertJsonPath('next_action', null);
        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2575, 'longitude' => 112.7521])
            ->assertStatus(422)->assertJsonValidationErrors('employee');
    }

    public function test_double_press_does_not_create_two_taps_and_checkout_works(): void
    {
        $this->employee(['user_id' => $this->packer->id]);
        $this->warehouse();
        AppSetting::put(AppSetting::ATTENDANCE_REQUIRE_SELFIE, '0');

        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2575, 'longitude' => 112.7521])->assertCreated();
        $this->api('POST', '/api/mobile/absen', ['type' => 'in', 'latitude' => -7.2575, 'longitude' => 112.7521])->assertOk();
        $this->assertSame(1, AttendancePunch::count());

        $this->travelTo(now()->setTime(17, 5));
        $this->api('POST', '/api/mobile/absen', ['type' => 'out', 'latitude' => -7.2575, 'longitude' => 112.7521])
            ->assertCreated()
            ->assertJsonPath('today.day.check_out', '17:05')
            ->assertJsonPath('today.day.label', 'Telat 120 mnt');

        $this->api('GET', '/api/mobile/absen/riwayat?month=2026-11')->assertOk()
            ->assertJsonPath('summary.present', 1)
            ->assertJsonPath('days.0.date', '2026-11-03');
    }
}
