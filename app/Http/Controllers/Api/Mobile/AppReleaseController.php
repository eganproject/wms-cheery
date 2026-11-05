<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAppRelease;
use App\Services\MobileAppPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kiriman APK otomatis dari GitHub Actions setiap aplikasi Android berubah,
 * supaya admin tidak perlu mengunggah manual.
 *
 * Dijaga token rahasia MOBILE_APP_PUBLISH_TOKEN (.env server) yang sama
 * dengan secret WMS_PUBLISH_TOKEN di GitHub. Tanpa token, endpoint mati.
 */
class AppReleaseController extends Controller
{
    public function store(Request $request, MobileAppPublisher $publisher): JsonResponse
    {
        $token = (string) config('services.mobile_app.publish_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401, 'Token kiriman APK salah.');

        $request->validate([
            'apk' => ['required', 'file', 'max:'.(200 * 1024)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'required' => ['nullable', 'boolean'],
        ]);

        // Kiriman ulang versi yang sama (mis. workflow dijalankan ulang) dianggap beres.
        $info = $publisher->inspect($request->file('apk'));
        $existing = MobileAppRelease::where('version_code', $info->versionCode)->first();
        if ($existing) {
            return response()->json([
                'message' => "Versi {$existing->version_name} sudah ada di server.",
                'version_code' => $existing->version_code,
                'created' => false,
            ]);
        }

        $release = $publisher->publish(
            $request->file('apk'),
            $request->input('notes'),
            $request->boolean('required'),
        );

        return response()->json([
            'message' => "Versi {$release->version_name} diterbitkan ke HP.",
            'version_code' => $release->version_code,
            'created' => true,
        ], 201);
    }
}
