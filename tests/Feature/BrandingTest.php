<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Identitas aplikasi pada halaman yang dilihat sebelum masuk.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Halaman login tidak boleh menyebutkan kredensial apa pun.
     *
     * Alamat dan kata sandi contoh berguna saat aplikasi masih diuji, tetapi di
     * server yang sudah dipakai ia adalah undangan terbuka — dan akun itu memang
     * ada, dibuat oleh seeder.
     */
    public function test_the_login_page_never_shows_any_credentials(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertDontSee('Akun demo');
        $response->assertDontSee('wmsotosby.test');
        $response->assertDontSee('password</span>', false);
    }

    public function test_the_login_page_carries_the_cheerychic_logo(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('images/cheerychic-logo.png', false);

        $this->assertFileExists(public_path('images/cheerychic-logo.png'));
    }

    /**
     * Favicon disebut terang-terangan dan berkasnya memakai logo CheeryChic.
     */
    public function test_the_favicon_is_declared_and_present(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('favicon-32x32.png', false)
            ->assertSee('apple-touch-icon.png', false);

        $this->assertFileExists(public_path('favicon-32x32.png'));
        $this->assertFileExists(public_path('apple-touch-icon.png'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }
}
