<?php

namespace Tests\Feature;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_start_a_conversation_with_a_first_message(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->postJson('/api/support/conversations', [
                'subject' => 'Cannot access my certificate',
                'message' => 'My certificate does not show up in my account.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('conversation.subject', 'Cannot access my certificate')
            ->assertJsonPath('conversation.status', 'open');

        $this->assertDatabaseHas('chat_conversations', [
            'user_id' => $student->id,
            'subject' => 'Cannot access my certificate',
        ]);

        $conversation = ChatConversation::firstOrFail();
        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'body' => 'My certificate does not show up in my account.',
        ]);
        $this->assertNull($conversation->agent_id);
    }

    public function test_student_only_sees_their_own_conversations(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $mine = ChatConversation::factory()->create(['user_id' => $student->id]);
        ChatConversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($student)
            ->getJson('/api/support/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.id', $mine->id);
    }

    public function test_student_cannot_view_another_students_conversation(): void
    {
        $student = User::factory()->student()->create();
        $other = User::factory()->student()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $other->id]);

        $this->actingAs($student)
            ->getJson("/api/support/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_student_can_send_a_message_to_their_own_conversation(): void
    {
        $student = User::factory()->student()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson("/api/support/conversations/{$conversation->id}/messages", ['message' => 'Also, is there a refund policy?'])
            ->assertStatus(201)
            ->assertJsonPath('message.body', 'Also, is there a refund policy?')
            ->assertJsonPath('message.sender.id', $student->id);

        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'body' => 'Also, is there a refund policy?',
        ]);
    }

    public function test_guest_cannot_access_support(): void
    {
        $this->getJson('/api/support/conversations')->assertStatus(401);
        $this->postJson('/api/support/conversations', ['subject' => 'Hi', 'message' => 'Hello'])->assertStatus(401);
    }

    public function test_agent_sees_all_conversations_with_unread_counts(): void
    {
        $student = User::factory()->student()->create();
        $agent = User::factory()->customerService()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);
        ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'body' => 'Help please.',
        ]);

        $this->actingAs($agent)
            ->getJson('/api/support/agent/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.unread', 1)
            ->assertJsonPath('conversations.0.user.id', $student->id);
    }

    public function test_opening_a_conversation_marks_messages_as_read(): void
    {
        $student = User::factory()->student()->create();
        $agent = User::factory()->customerService()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);
        ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'body' => 'Hello there.',
        ]);

        $this->actingAs($agent)
            ->getJson("/api/support/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'messages');

        $this->actingAs($agent)
            ->getJson('/api/support/agent/conversations')
            ->assertJsonPath('conversations.0.unread', 0);
    }

    public function test_agent_can_reply_to_and_assign_a_conversation(): void
    {
        $student = User::factory()->student()->create();
        $agent = User::factory()->customerService()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($agent)
            ->postJson("/api/support/conversations/{$conversation->id}/messages", ['message' => 'We are on it!'])
            ->assertStatus(201);

        $this->actingAs($agent)
            ->putJson("/api/support/conversations/{$conversation->id}/assign")
            ->assertOk()
            ->assertJsonPath('conversation.agent.id', $agent->id);

        $this->assertDatabaseHas('chat_conversations', [
            'id' => $conversation->id,
            'agent_id' => $agent->id,
        ]);
    }

    public function test_conversation_can_be_closed_and_reopened(): void
    {
        $student = User::factory()->student()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);

        $this->actingAs($student)
            ->putJson("/api/support/conversations/{$conversation->id}/close")
            ->assertOk();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id, 'status' => 'closed']);

        $this->actingAs($student)
            ->putJson("/api/support/conversations/{$conversation->id}/reopen")
            ->assertOk();

        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id, 'status' => 'open']);
    }

    public function test_student_cannot_use_the_agent_inbox(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->getJson('/api/support/agent/conversations')
            ->assertForbidden();
    }

    public function test_agent_can_open_a_students_conversation(): void
    {
        $student = User::factory()->student()->create();
        $agent = User::factory()->customerService()->create();
        $conversation = ChatConversation::factory()->create(['user_id' => $student->id]);
        ChatMessage::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
        ]);

        $this->actingAs($agent)
            ->getJson("/api/support/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonCount(1, 'messages');
    }
}
