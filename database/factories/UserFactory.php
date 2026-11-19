<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => 'User '.Str::random(5),
            'email' => Str::lower(Str::random(10)).'@example.com',
            'password' => 'password',
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_ADMIN]);
    }

    public function pending(): static
    {
        return $this->state(['status' => User::STATUS_PENDING]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => User::STATUS_DISABLED]);
    }
}
