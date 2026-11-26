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

        // Code under test raises a time limit for long jobs (set_time_limit); it must not outlive the test and kill a slow suite.
        @set_time_limit(0);

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
