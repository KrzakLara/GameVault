<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The authenticated user, for actions behind the auth middleware. Fails closed with 401
     * instead of assuming: if a route were ever exposed without auth, nothing proceeds as null.
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
