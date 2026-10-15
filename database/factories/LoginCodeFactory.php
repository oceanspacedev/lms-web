<?php

namespace Database\Factories;

use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginCode>
 */
class LoginCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => '6281234567890',
            'code_hash' => LoginCode::hashCode('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'status' => 'sent',
        ];
    }
}
