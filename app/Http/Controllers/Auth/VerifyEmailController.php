<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($this->currentUser($request)->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($this->currentUser($request)->markEmailAsVerified()) {
            // Foundation\Auth\User uses the MustVerifyEmail *trait* but not the interface the event's
            // docblock names; the constructor itself is untyped, so this is safe at runtime.
            // Implementing the interface would switch on mandatory e-mail verification (a product
            // decision, deliberately not taken here).
            // @phpstan-ignore argument.type
            event(new Verified($this->currentUser($request)));
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
