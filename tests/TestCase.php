<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Relasi yang dimuat satu per satu di dalam daftar (N+1) langsung
        // menggagalkan tes, jadi halaman yang mulai lambat ketahuan sebelum
        // sampai ke gudang.
        Model::preventLazyLoading();
    }
}
