<?php

namespace Tests;

use App\Models\User;
use App\Support\Settings;
use App\Support\Setup;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Per-process caches must not leak between tests.
        Setup::reset();
        Settings::flush();
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
