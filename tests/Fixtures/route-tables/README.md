# Route tables

Laravel's own output for the Laravel fixture's route files, not written by hand (#114):

- `../laravel/exocomp-routes.json` is `php artisan route:list --json --except-vendor`;
- `routes-v7.php` is `php artisan route:cache`.

Both were captured from Laravel 13.42.0 with the PM workspace's `tools/exo-capture-routes.sh`. It
builds a throwaway application with the fixture's route files, the controllers they name and
the fixture's `RouteServiceProvider` wiring (web and api groups, root namespace
`App\Http\Controllers`).

Edits after capture, made by that script:
- the host application's package routes (tetryon, storage) are removed;
- one package route, `widgets/ping`, is added in the shape `route:list` gives a package route, with an anonymised path;
- in the cache, `compiled` is emptied and each serialized closure is replaced with an empty one, still `O:`-prefixed, because the originals embed the capturing machine's paths.
