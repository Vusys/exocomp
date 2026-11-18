<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Every spelling of the signed-in user, which config/auth.php says is a Customer.
 */
final class SignedInCallers
{
    public function helper(): void
    {
        auth()->user()->orders();
    }

    public function facade(): void
    {
        Auth::user()->orders();
    }

    public function request(Request $request): void
    {
        $request->user()->orders();
    }
}
