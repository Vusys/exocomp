<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\AuthenticatesUsers;

/**
 * Calls a method declared only in a framework trait, then a same-named method on what it returns.
 */
final class SessionController
{
    use AuthenticatesUsers;

    public function check(): void
    {
        $this->guard()->user();
    }
}
