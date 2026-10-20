<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Mordomus\Identity\Models\User;

#[UseModel(User::class)]
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),

            'password_hash' => 'password',
            'locale' => 'pt_BR',
        ];
    }

    public static function password(): string
    {
        return 'password';
    }
}
