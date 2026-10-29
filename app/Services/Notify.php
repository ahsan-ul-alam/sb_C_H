<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Small helper around Laravel database notifications.
 * - Recipients are unique user accounts chosen by effective permission (all roles combined).
 * - A notification with the same key is not repeated while an unread copy exists.
 * - It runs after the surrounding transaction commits and can never break the business action that triggered it.
 */
class Notify
{
    /**
     * Users who currently hold a permission, minus excluded user ids.
     *
     * @param  list<int>  $exceptUserIds
     * @return Collection<int, User>
     */
    public static function withPermission(string $permission, array $exceptUserIds = []): Collection
    {
        return User::all()->filter(fn (User $u) => ! in_array($u->id, $exceptUserIds, true) && $u->can($permission))->values();
    }

    /**
     * @return Collection<int, User>
     */
    public static function partnerUsers(?int $partnerId): Collection
    {
        return $partnerId ? User::where('partner_id', $partnerId)->get() : new Collection;
    }

    public static function user(?int $userId): Collection
    {
        return $userId ? User::whereKey($userId)->get() : new Collection;
    }

    /**
     * @param  iterable<User>  $users
     */
    public static function send(iterable $users, string $title, string $message, string $routeName, string $category, string $key, array $routeParams = []): void
    {
        $recipients = collect($users)->unique('id')->values();
        if ($recipients->isEmpty()) {
            return;
        }
        $url = route($routeName, $routeParams, false); // relative path only; never an external URL

        DB::afterCommit(function () use ($recipients, $title, $message, $url, $category, $key): void {
            try {
                foreach ($recipients as $user) {
                    $exists = DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)
                        ->whereNull('read_at')->where('data', 'like', '%"key":'.json_encode($key).'%')->exists();
                    if (! $exists) {
                        $user->notify(new AppNotification(compact('title', 'message', 'url', 'category', 'key')));
                    }
                }
            } catch (Throwable $e) {
                // Runs after the business transaction committed, so it cannot undo or duplicate it.
                // Missing table = migration not applied yet (expected, warn); anything else is a real fault (error).
                $level = Schema::hasTable('notifications') ? 'error' : 'warning';
                Log::$level('Notification not delivered: '.$e->getMessage(), ['key' => $key]);
            }
        });
    }
}
