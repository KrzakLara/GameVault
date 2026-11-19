<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Inventory\ImportGameKeys;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportGameKeysRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class GameKeyController extends Controller
{
    public function store(ImportGameKeysRequest $request, Game $game, ImportGameKeys $import): RedirectResponse|JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof User && $admin->is_admin, 403);

        $result = $import->handle($game, $request->codes(), $admin);

        return $request->expectsJson()
            ? response()->json($result, 201)
            : back()->with('success', "Imported {$result['imported']} key(s); skipped {$result['duplicates']} duplicate(s).");
    }
}
