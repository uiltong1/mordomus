<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Mordomus\Identity\Models\User;

/**
 * @extends Factory<User>
 */
#[UseModel(User::class)]
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // cast `hashed` (argon2id) converte em hash no create()
            'password_hash' => 'password',
            'locale' => 'pt_BR',
        ];
    }

    public static function password(): string
    {
        return 'password';
    }
}
