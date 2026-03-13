<?php

namespace Database\Factories;

use App\Models\UserCampus;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserCampusFactory extends Factory
{
    protected $model = UserCampus::class;

    public function definition(): array
    {
        return [
            'UserID' => 1,
            'CampusID' => 1,
            'Admin' => 0,
            'Approved' => true,
        ];
    }
}
