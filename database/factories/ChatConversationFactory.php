<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
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
            'agent_id' => null,
            'subject' => fake()->sentence(6),
            'status' => 'open',
            'last_message_at' => now(),
        ];
    }

    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => User::factory(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }
}
