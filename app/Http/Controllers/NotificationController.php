<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Every query is scoped to the signed-in user's own notifications. A notification only points at a page;
 * opening that page re-runs the normal route authorisation, so a notification never grants access.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $unreadOnly = $request->query('filter') === 'unread';
        $query = $this->hasTable() ? $request->user()->notifications()->when($unreadOnly, fn ($q) => $q->whereNull('read_at')) : null;

        return view('notifications.index', [
            'notifications' => $query ? $query->paginate(20)->withQueryString() : new LengthAwarePaginator([], 0, 20),
            'unreadOnly' => $unreadOnly,
            'unread' => $this->unreadCount($request),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->unreadCount($request)]);
    }

    public function feed(Request $request): JsonResponse
    {
        $items = $this->hasTable() ? $request->user()->notifications()->limit(8)->get()->map(fn ($n) => $this->present($n))->values() : [];

        return response()->json(['unread' => $this->unreadCount($request), 'items' => $items]);
    }

    public function read(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'unread' => $this->unreadCount($request)])
            : back()->with('status', 'Marked as read.');
    }

    public function readAll(Request $request): JsonResponse|RedirectResponse
    {
        if ($this->hasTable()) {
            $request->user()->unreadNotifications->markAsRead();
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => 'All notifications marked as read.', 'unread' => 0])
            : back()->with('status', 'All notifications marked as read.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $n): array
    {
        $url = (string) ($n->data['url'] ?? '');

        return [
            'id' => $n->id,
            'title' => (string) ($n->data['title'] ?? 'Notification'),
            'message' => (string) ($n->data['message'] ?? ''),
            'category' => (string) ($n->data['category'] ?? 'info'),
            // Only same-site paths are ever handed to the browser.
            'url' => str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '',
            'read' => $n->read_at !== null,
            'time' => $n->created_at->diffForHumans(),
        ];
    }

    private function unreadCount(Request $request): int
    {
        return $this->hasTable() ? $request->user()->unreadNotifications()->count() : 0;
    }

    private function hasTable(): bool
    {
        return Schema::hasTable('notifications');
    }
}
