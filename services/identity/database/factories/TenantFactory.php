<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mordomus\Identity\Models\Tenant;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => $name,
            'slug' => Tenant::uniqueSlug($name),
            'timezone' => 'America/Sao_Paulo',
            'preferred_hour' => '09:00',
        ];
    }
}
