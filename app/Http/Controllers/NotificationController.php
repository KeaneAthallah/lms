<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->take(50)
            ->get();

        $unreadCount = $request->user()->unreadNotifications->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'notifications' => $notifications->map(fn (DatabaseNotification $n): array => [
                'id' => (string) $n->id,
                'title' => $n->data['title'] ?? 'Notification',
                'message' => $n->data['message'] ?? '',
                'type' => $n->data['type'] ?? $n->type,
                'created_at' => $n->created_at?->toISOString(),
                'read_at' => $n->read_at?->toISOString(),
            ]),
        ]);
    }

    public function markRead(Request $request, string $notification)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
