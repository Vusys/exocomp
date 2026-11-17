<?php

namespace Illuminate\Foundation\Auth;

use Illuminate\Http\Request;

/**
 * A framework trait an application controller uses, declaring a method the index never sees.
 */
trait AuthenticatesUsers
{
    /**
     * @return Request
     */
    protected function guard()
    {
        return new Request;
    }
}
