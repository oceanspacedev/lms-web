<?php

namespace Database\Factories;

use App\Models\EmailOtp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmailOtp>
 */
class EmailOtpFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'purpose' => EmailOtp::PURPOSE_LOGIN,
            'otp_hash' => Hash::make('123456'),
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addMinutes(10),
            'attempt_count' => 0,
        ];
    }
}
