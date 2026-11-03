<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsappOtp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<WhatsappOtp>
 */
class WhatsappOtpFactory extends Factory
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
            'whatsapp_number' => '6281234567890',
            'purpose' => WhatsappOtp::PURPOSE_LOGIN,
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempt_count' => 0,
        ];
    }
}
