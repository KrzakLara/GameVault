<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in customer's account home: their own orders only. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $this->currentUser($request);
        $counts = $user->orders()->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return Inertia::render('Dashboard', [
            'stats' => [
                'total' => $counts->sum(),
                'delivered' => $counts['delivered'] ?? 0,
                'in_progress' => ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0),
                'unsuccessful' => ($counts['failed'] ?? 0) + ($counts['cancelled'] ?? 0),
            ],
            'recentOrders' => OrderResource::collection(
                $user->orders()->with('game:id,name,slug,platform,cover_url')->latest('id')->limit(5)->get()
            )->resolve(),
        ]);
    }
}
