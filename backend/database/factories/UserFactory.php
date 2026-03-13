<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'LoginName' => $this->faker->unique()->safeEmail(),
            'Name' => $this->faker->name(),
            'PSW' => Hash::make('password'),
            'type' => 'U',
            'phone' => (int) $this->faker->numerify('09########'),
            'status' => 'active',
            'employment_type' => 'full_time',
        ];
    }

    public function superAdmin(): self
    {
        return $this->state(fn () => ['type' => 'S']);
    }

    public function pendingDirector(): self
    {
        return $this->state(fn () => ['type' => 'U']);
    }

    public function director(): self
    {
        return $this->state(fn () => ['type' => 'D']);
    }

    public function teacher(): self
    {
        return $this->state(fn () => ['type' => 'T']);
    }
}
