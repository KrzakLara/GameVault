<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OutboxStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OutboxMessageResource;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\OutboxPublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OutboxController extends Controller
{
    /** "retrying" = pending again after at least one failed delivery attempt. */
    public const FILTERS = ['all', 'pending', 'retrying', 'processing', 'published', 'dead_letter'];

    public function index(Request $request, OutboxPublisher $publisher): Response|JsonResponse
    {
        $filter = in_array($request->query('status'), self::FILTERS, true) ? (string) $request->query('status') : 'all';

        $messages = OutboxMessage::query()
            ->when($filter === 'retrying', fn (Builder $q) => $q->where('status', OutboxStatus::Pending)->where('attempts', '>', 0))
            ->when(in_array($filter, ['pending', 'processing', 'published', 'dead_letter'], true), fn (Builder $q) => $q->where('status', $filter))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $props = [
            'stats' => $publisher->stats(),
            'retrying' => OutboxMessage::query()->where('status', OutboxStatus::Pending)->where('attempts', '>', 0)->count(),
            'messages' => OutboxMessageResource::collection($messages),
            'filters' => ['status' => $filter],
            'statusFilters' => self::FILTERS,
        ];

        return $request->expectsJson() ? response()->json($props) : Inertia::render('admin/Outbox', $props);
    }

    public function requeue(Request $request, OutboxMessage $message, OutboxPublisher $publisher): RedirectResponse|JsonResponse
    {
        $count = $publisher->requeueDeadLetters($this->admin($request), $message->id);

        if ($count === 0) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Only dead-lettered messages can be requeued.', 'error' => 'not_dead_lettered'], 409)
                : back()->withErrors(['outbox' => 'Only dead-lettered messages can be requeued.']);
        }

        return $request->expectsJson() ? response()->json(['requeued' => $count]) : back()->with('success', 'Message requeued for delivery.');
    }

    /** Bulk recovery after an outage has been fixed. Audited like the single requeue. */
    public function requeueAll(Request $request, OutboxPublisher $publisher): RedirectResponse|JsonResponse
    {
        $count = $publisher->requeueDeadLetters($this->admin($request));

        return $request->expectsJson()
            ? response()->json(['requeued' => $count])
            : back()->with('success', $count === 0 ? 'There were no dead-lettered messages.' : "Requeued {$count} dead-lettered message(s).");
    }

    private function admin(Request $request): User
    {
        $user = $this->currentUser($request);
        abort_unless($user->is_admin, 403);

        return $user;
    }
}
