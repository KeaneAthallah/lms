<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $conversations = ChatConversation::query()
            ->where('user_id', $request->user()->id)
            ->with(['agent'])
            ->withCount(['messages as unread' => function ($query) use ($request) {
                $query->where('sender_id', '!=', $request->user()->id)->where('is_read', false);
            }])
            ->ordered()
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (ChatConversation $conversation): array => $this->conversationPayload($conversation)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $conversation = ChatConversation::create([
            'user_id' => $request->user()->id,
            'subject' => $data['subject'],
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'body' => $data['message'],
        ]);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation->fresh()->load('user', 'agent')),
        ], 201);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAccess($conversation, $request->user());

        ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages = $conversation->messages()
            ->with('sender:id,name,avatar_path')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $message): array => $this->messagePayload($message));

        return response()->json([
            'conversation' => $this->conversationPayload($conversation->fresh()->load('user', 'agent')),
            'messages' => $messages,
        ]);
    }

    public function storeMessage(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAccess($conversation, $request->user());

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $message = ChatMessage::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'body' => $data['message'],
        ]);

        $conversation->update([
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return response()->json([
            'message' => $this->messagePayload($message->load('sender:id,name,avatar_path')),
        ], 201);
    }

    public function close(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAccess($conversation, $request->user());

        $conversation->update(['status' => 'closed']);

        return response()->json(['message' => 'Conversation closed.']);
    }

    public function reopen(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAccess($conversation, $request->user());

        $conversation->update(['status' => 'open']);

        return response()->json(['message' => 'Conversation reopened.']);
    }

    public function agentIndex(Request $request): JsonResponse
    {
        $conversations = ChatConversation::query()
            ->with(['user:id,name,avatar_path', 'agent:id,name,avatar_path'])
            ->withCount(['messages as unread' => function ($query) use ($request) {
                $query->where('sender_id', '!=', $request->user()->id)->where('is_read', false);
            }])
            ->orderByRaw('case when status = ? then 0 else 1 end, last_message_at desc', ['open'])
            ->limit(200)
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (ChatConversation $conversation): array => $this->conversationPayload($conversation)),
        ]);
    }

    public function assign(Request $request, ChatConversation $conversation): JsonResponse
    {
        $conversation->update(['agent_id' => $request->user()->id]);

        return response()->json([
            'conversation' => $this->conversationPayload($conversation->fresh()->load('user', 'agent')),
        ]);
    }

    private function authorizeAccess(ChatConversation $conversation, User $user): void
    {
        abort_unless(
            $conversation->user_id === $user->id
                || $user->hasRole(Role::CustomerService)
                || $user->isAdmin(),
            403,
            'You do not have access to this conversation.',
        );
    }

    private function conversationPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'status' => $conversation->status,
            'unread' => (int) $conversation->unread,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'user' => $conversation->user ? [
                'id' => $conversation->user->id,
                'name' => $conversation->user->name,
                'avatar_url' => $conversation->user->avatar_path
                    ? asset('storage/'.$conversation->user->avatar_path)
                    : null,
            ] : null,
            'agent' => $conversation->agent ? [
                'id' => $conversation->agent->id,
                'name' => $conversation->agent->name,
                'avatar_url' => $conversation->agent->avatar_path
                    ? asset('storage/'.$conversation->agent->avatar_path)
                    : null,
            ] : null,
        ];
    }

    private function messagePayload(ChatMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'is_read' => $message->is_read,
            'created_at' => $message->created_at?->toISOString(),
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'avatar_url' => $message->sender->avatar_path
                    ? asset('storage/'.$message->sender->avatar_path)
                    : null,
            ] : null,
        ];
    }
}
