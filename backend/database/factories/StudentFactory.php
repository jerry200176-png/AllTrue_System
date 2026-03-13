<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'CampusID' => 1,
            'ClassID' => 7,
            'SchoolName' => $this->faker->company(),
            'RFID' => null,
            'LineID' => null,
            'TelegramID' => '',
            'TelegramID1' => null,
            'TelegramID2' => null,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $this->faker->numerify('09########'),
            'parent_name' => null,
            'parent_phone' => null,
            'notes' => null,
            'status' => 'active',
        ];
    }
}
