<?php

use Illuminate\Http\Request;

if (! function_exists('request')) {
    /**
     * The framework's own spelling: with no key it returns the request, with one, the input.
     *
     * @return ($key is null ? Request : mixed)
     */
    function request($key = null, $default = null)
    {
        return new Request;
    }
}

if (! function_exists('auth')) {
    function auth($guard = null): \Illuminate\Contracts\Auth\Factory
    {
        return app('auth');
    }
}

if (! function_exists('collect')) {
    function collect($value = []): \Illuminate\Support\Collection
    {
        return new \Illuminate\Support\Collection;
    }
}

if (! function_exists('fixture_now')) {
    function fixture_now(): \Illuminate\Http\Request
    {
        return new Request;
    }
}
