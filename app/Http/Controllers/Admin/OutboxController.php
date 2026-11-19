<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutboxStatus;
use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OutboxController extends Controller
{
    public function index(Request $request, OutboxPublisher $publisher): Response|JsonResponse
    {
        $deadLetters = OutboxMessage::query()->where('status', OutboxStatus::DeadLetter)->latest('id')->limit(50)->get()
            ->map(fn (OutboxMessage $m) => [
                'id' => $m->id,
                'event_id' => $m->event_id,
                'type' => $m->type,
                'attempts' => $m->attempts,
                'last_error' => $m->last_error,
                'dead_lettered_at' => $m->dead_lettered_at?->toIso8601String(),
            ]);

        $props = ['stats' => $publisher->stats(), 'deadLetters' => $deadLetters];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('admin/Outbox', $props);
    }

    public function requeue(Request $request, OutboxMessage $message, OutboxPublisher $publisher): RedirectResponse|JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof User && $admin->is_admin, 403);

        $count = $publisher->requeueDeadLetters($admin, $message->id);

        if ($count === 0) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Only dead-lettered messages can be requeued.', 'error' => 'not_dead_lettered'], 409)
                : back()->withErrors(['outbox' => 'Only dead-lettered messages can be requeued.']);
        }

        return $request->expectsJson() ? response()->json(['requeued' => $count]) : back()->with('success', 'Message requeued.');
    }
}
