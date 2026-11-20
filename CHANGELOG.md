# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **A route's middleware is part of a walk from that route.** `reaches 'route:…'` starts at each middleware's `handle()` as well as at the controller. Middleware comes from global middleware, the route's own middleware and the groups it is in, resolved through the aliases and groups declared in `app/Http/Kernel.php` (`$middleware`, `$middlewareGroups`, `$middlewareAliases` or `$routeMiddleware`) or in `bootstrap/app.php`'s `withMiddleware()` (`alias()`, `append()`, `prepend()`, `group()`, `appendToGroup()`, `prependToGroup()`, `web()`, `api()`). The `web` and `api` groups that Laravel applies by convention to `routes/web.php` and `routes/api.php` are `inferred`. A name only the framework defines, such as `throttle`, is listed in a note as not followed. On Koel, a `route:*` sweep to `App\Http\Middleware\*` went from 0 of 100 routes to 100.

- **`reaches` and `graph` follow an authorization check into its policy.** `$this->authorize('update', $order)`, `Gate::allows()`, `Gate::authorize()` (and the facade's other checks) and `$user->can()`, `cannot()` and `cant()` continue into the policy for the class they check. That is its `before()` where it has one, then the method the ability names (`forceDelete` for `force-delete`). A policy registered in `$policies`, with `Gate::policy()` or by `#[UsePolicy]` on the model is as sure as the check. One found only by Laravel's naming convention is `inferred`, and so is a check made on a receiver whose type is unknown. A check whose ability is not a literal, whose subject's type is unknown, or that finds no policy method is listed as not followed, with the reason, so it never turns into a "no". On Koel, whose 13 policies are all found by name, a `route:*` sweep to `App\Policies\*` went from 0 of 100 routes to 25.

- **`reaches` and `graph` follow a model write into its observers.** `$order->save()`, `delete()`, `update()`, `create()` and the other writes that fire model events now continue into each observer method the write fires. Observers registered with `#[ObservedBy]` on the model, with `Order::observe()`, or in an `$observers` array are read. An event the write always fires, such as `deleted` from `delete()`, is as sure as the registration. One it fires only for some models, such as `created` from `save()`, is `inferred`. Mass updates fire no model events and get no observer step. On Koel, three more routes now reach `ModelImageObserver::onModelDeleted`, through `PlaylistObserver`, `AlbumObserver` and `ArtistObserver`.

- **`instanceof` types the variable it tests.** `$x` is the class inside `if ($x instanceof C) {…}` and an `elseif`, on the right of `&&`, and in a ternary's first branch. It is the class for the rest of the body after `assert($x instanceof C)`, and after a guard such as `if (! $x instanceof C) { throw … }` that has no other branch and always leaves. PHP guarantees each of these, so the type is `resolved`. It does not apply in an `else`, before the test in the same condition, or after the block ends. The type the variable had before comes back. `references-to`, `callers-of`, `calls-from`, `reaches` and `query`'s receiver predicates all use it.

### Fixed

- **`references-to` on an override is safe to rename from, and `--family` gives the whole hierarchy.** On a method that overrides or implements another, the parent's declaration was a `resolved` reference while the sibling implementations were missing, so rewriting the `resolved` set broke the hierarchy. Now:
  - By default the answer is that declaration, the declarations that override it, and its calls, plus a note naming the root.
  - `--family` answers for the root: every override and implementation, and calls through any of their types, all `resolved`.
  - A call through an interface or parent that does not declare the method is unresolved, with the reason, rather than an `inferred` match (#85).

- **A constant expression counts as a literal argument.** `--arg-is-literal` and `--arg-is-not-literal` (in `query` and `check`) treat a value fixed in the source as literal: concatenations of literals, class constants (`self::SQL`), an enum case's `->value`, PHP's own constants, and arithmetic and arrays of those. They can carry no runtime data, so an SQL audit flagged them as noise. An interpolated string, and a constant the application `define()`s, still don't count (#86).

- **Every kind of magic is reported the same way: the address it was reached by, and where it lands.** A call is addressed as reached through its receiver: `Order::find`, `Order::save`, `$builder->open()`, `$s->invoices()->save()`, `$host->greet()`. Its record also names the declaration that runs: `Builder::find`, `Model::save`, `Order::scopeOpen`, `HasOneOrMany::save` and `Greets::hello`. `calls-from` prints it after an arrow (`→`), and JSON carries it as `lands`.
  - `callers-of` the landing declaration finds the call too: `callers-of 'Illuminate\Database\Eloquent\Builder::find'` now lists `Order::find(1)`.
  - `reaches` arrives at either name, and walks on from where the call lands.
  - `show Order::find` prints `Builder::find`, with a note saying how the call gets there.
  - Before, inherited methods were named by the receiver and magic by the declaration, and forwarded static calls by an address `show` could not open (#58).

- **A first-class callable is a `Closure`.** `$f = $h->make(...)` was typed as what `make()` returns, so `$f->refund()` was a `resolved` call to a method on that class. It is now a `Closure`, and a call on it that a `Closure` doesn't have is unresolved with that reason. Calling it, `$f()`, is a call to `make()` and returns what `make()` returns (#35).
- **No more assertion failure on a first-class callable.** Reading a call's arguments no longer asks php-parser for the arguments of `$x->m(...)`, which fails an assertion under `zend.assertions=1`, the development default (#35).
- **`calls-from`, `reaches` and `graph` type closure parameters.** `fn (Invoice $r) => $r->delete()` was "receiver type unknown" in `calls-from`, though `callers-of` and `query` already typed it. A closure inside a body now has its own scope: its parameters, and what it can see of the body's (#40).
- **An address that names nothing is an error, not an empty answer.** `callers-of`, `references-to` and `reaches` answered "0 callers" or "no path" about a class that does not exist. They now place the class as `show` does: in the index, through Composer's autoload map, or by correcting a guessed namespace where exactly one class has that short name, with a note saying so. Otherwise they exit 1 with the `locate` hint. A `reaches` start whose member the class doesn't declare is an error too. `implementors` on a class and `traits-users` on a non-trait say what kind the type is and which command answers instead (#42).
- **Every closure is its own caller.** A closure was named `Class::{closure}`, and outside a class just `{closure}`, so `callers-of` kept one closure per class, and one for the whole project outside classes. Pest tests and route closures vanished. A closure is now named like a PHP 8.4 stack trace frame: `Class::{closure:refund():12}`, or `{closure:tests/Unit/RefundTest.php:5}` outside a class (#41).
- **A vendor index survives the next query.** Once `index --warm --vendor` has indexed dependencies, a sync without `--vendor` keeps them, instead of deleting them as stale. `index --no-vendor` removes them (#43).
- **Symlinked packages are read.** A Composer path repository installs a package as a symlink. The index now follows it, and a class in it keeps its `vendor/` path rather than being "placed at …, which is not there" (#72). An ancestor that is a PHP built-in, such as `ArrayAccess`, is no longer given the advice to index dependencies.
- **`calls-from` brings the index up to date first**, as `callers-of` does, so a class written a moment ago has its property types. `show` and `outline` find a class that shares a file with another through the index, without `--scan`. A receiver on a class the index doesn't hold says so, instead of "property has no declared class type" (#44).
- **A function call is never corrected to a class.** Walking `response()`, `reaches` answered with an application class called `Response`. The walk no longer corrects names, and a namespace correction needs the short name to match case (#47).
- **`members` with a guessed namespace lists inherited members** after correcting it, instead of only the class's own, with advice about `--own` and `--vendor` that did not apply (#62).
- **`locate` places a vendor class by its full name** through Composer's autoload map, as `show` does. A negative for a short name says that dependencies were not searched (#63).
- **`app()` with no argument is the application**, so `app()->make(X::class)` is `Application::make`, not an unresolved call (#46).
- **A known class in `vendor/` is no longer said to declare nothing.** For a class the index doesn't hold, such as `Illuminate\Support\Collection`, the resolver took the empty member list as complete and reported "Collection declares no filter()" without reading the class. It now reads it, follows `@return static<…>` and `@return $this`, and a call on it is excluded from an application method of the same name. This was much of the unresolved noise for common names in `callers-of` (#81).
- **Relations, scopes and forwarded calls point at members that exist.**
  - A method a relation declares itself is that relation's: `$s->invoices()->save($i)` is `HasOneOrMany::save` and `$s->order()->associate()` is `BelongsTo::associate`. Only what the relation doesn't declare goes to the query builder (#57).
  - A local scope called on a builder is the model's scope: `Order::query()->open()` is `Order::scopeOpen`, not `Builder::open`. A `#[Scope]` method is typed like a legacy one, statically and on a builder, and the chain continues (#75).
  - `Order::find()` and `Order::where()`, which nothing in the model's ancestry declares and `__callStatic` forwards, are `inferred` with that reason, not `resolved` "class name" (#58).
- **Trait `as` aliases and `insteadof` are followed.** `Greets::hello as greet` makes `$host->greet()` a call to `Greets::hello`, in `calls-from` and in `callers-of` (which also searches for the alias). `secret as public revealed` works the same way. A method `insteadof` excluded no longer collects the calls that run the other trait's method (#70). `members` does not list aliases yet.
- **`overrides` looks down from an interface and up into dependencies.**
  - From an interface it lists every implementation ("implements it").
  - It reads ancestors the index doesn't hold, so `Model::save` appears beside a model's own `save()`, and a model that doesn't override it is no longer "not declared anywhere in its hierarchy".
  - A vendor class can be the starting point.
  - A descendant's declaration is labelled as overriding the member, not as "inherited".
  - `implementors` says how many of its count are interfaces that extend the subject (#71).
- **`config` finds reads through the repository, and lists writes apart.**
  - Reads: `config()->get()`, `config()->string()` and the other typed getters, `app('config')->get()`, `$app['config']['a']`, a variable that holds such a group (`$config['hosts']`), and an injected repository held in a `$config` property.
  - Writes: `Config::set()` and `config([...])` are listed under "written", not as reads.
  - Config files whose keys are class constants are read (#65).
- **`schema` reads other connections, attributes raw SQL to its table, and explains missing tables.**
  - `Schema::connection('x')->create(...)` is read, with a note naming the connection.
  - A `DB::statement()` is noted against the table its SQL names.
  - A column added under an `if` or in a loop, or with a computed name, is in the "not read" block.
  - Asking for a table that was renamed or dropped says so, and by which migration (#78).
- **`show` finds an Eloquent accessor by its attribute name.** `show Order::shippingAddress` and `show Order::shipping_address` find `getShippingAddressAttribute()`, or an `Attribute`-returning `shippingAddress()`, with a note. Before, the fallback suggested same-named properties on unrelated classes (#74).
- **An attribute cast to a class is typed when read.** `$order->status->label()` follows a cast to an enum or class. A custom cast is typed by what its `get()` returns, `datetime` and `date` by Carbon, and `collection` by `Collection` (#76).
- **`bindings` reads more shapes, and `app('key')` uses them.**
  - New shapes: `public $bindings` and `$singletons`, `tag()`, `extend()`, contextual bindings given to several classes, and primitive ones (`needs('$currency')->give('GBP')`).
  - `app('payments')` is typed from the binding of that key, in `calls-from`, `reaches` and `callers-of`. The keys the framework registers itself, such as `app('config')`, `app('db')` and `app('cache')`, are typed too. When there is none, the reason says so, instead of "app(), in an installed package, does not return a class" (#54).
- **A second checkout sharing the cache no longer misses classes.** A sync that only adopted files another checkout had already parsed reported "nothing changed", so lookups memoised before it kept answering from the empty index.
- **`query '$X->m($S, ...)'` matches.** A named argument followed by `...` matched nothing at all, not even calls with two arguments. It now binds the leading arguments and matches any number more (#48).
- **A `--max-tokens` cut is JSON in `--json`, and keeps what could not be resolved.** The cap cut the rendered text by lines, so the unresolved block and the notes went first, and in `--json` the cut was a line of plain text. It now cuts listed records, summarises the unresolved block by reason, and reports the cut as `{"elided": N, "reason": "max-tokens"}` (#49).
- **Small option fixes** (#64):
  - `show --at file:line:col` accepts the position exocomp prints.
  - `show --at` on a line inside a closure outside any class, such as a Pest test, answers with the closure and its `it()` description.
  - `members --matching` says when its total is only the reachable members.
- **`check` baselines survive reformatting.** A baseline entry is matched on the pretty-printed shape of the code, so a formatter run no longer turns a baselined violation into a new one. Entries written before are still honoured, by their old text. `--update-baseline` without `--baseline` writes `exocomp-baseline.json` next to the rules file. A rule that can't be read is named in the error (#66).
- **`query` and `check` say what a receiver predicate could not decide.** With `--receiver-type`, `--receiver-implements` or `--receiver-extends`, a match whose receiver's type is unknown is still kept out of the answer, but `query` lists it in a "not decided" block and `check` counts it in a note. A zero is no longer indistinguishable from "nothing here could be checked" (#79).
- **`--stdin` works for `show`, and is refused where it would be ignored.** `show` finds the address in the buffer on standard input, as the reference says. Index-backed commands now refuse `--stdin` with an error, as they already refused `--rev`, instead of answering about the file on disk (#80).
- **`routes` registers what the framework registers.** `Route::match(['get', 'post'], …)` is `GET|POST`, and `Route::any()` lists every method, instead of `MATCH` and `ANY`. So `routes --method POST` and `reaches 'route:POST /both'` find them. `Route::resources([...])` and `Route::singleton()` (and `apiSingleton()`) generate their routes. A resource's key is named after the singular resource, `/orders/{order}`, as `route:list` shows it, not `{id}`. View and redirect routes are counted in a note. A string controller that already starts with the group namespace isn't given it twice (#50, #73).
- **`reaches 'route:*'` says how much it walked.** The sweep's headline says how many matching routes were not walked, and `--max-routes N` raises the cap of 100 (`0` walks them all) (#68).
- **`schema` reads more Blueprint helpers.**
  - `uuid()`, `ulid()`, `ipAddress()` and `macAddress()` without a name are `uuid`, `ulid`, `ip_address` and `mac_address`, and `foreignIdFor(Invoice::class)` is `invoice_id`.
  - The second argument of `index()`, `unique()` and `primary()` is the index's name, shown and kept, not listed as a column. `dropIndex()` and its siblings remove an index by name or columns.
  - `morphs()` indexes its two columns.
  - `dropSoftDeletes()`, `dropTimestamps()`, `dropRememberToken()`, `dropMorphs()` and `dropConstrainedForeignId()` are applied (#51).
- **`morph-map` reads keys written as class constants.** `Order::MORPH_KEY => Order::class` was dropped without a word. A key that still can't be read is now noted (#52).
- **`bindings` reads three more shapes.** A factory returning `$app->make(X::class)`, `app(X::class)`, `resolve(X::class)` or `$app[X::class]` builds X. An abstract written as `self::KEY` is read through the constant. `instance('x', new X())` binds X, instead of being called "a factory that does more" (#53).
- **`dispatches` finds more sends and listeners.**
  - Sends: `Bus::chain([...])`, `Bus::batch([...])`, `X::withChain([...])`, `Queue::push()`/`later()`, and a variable assigned `new X` before it is sent.
  - Listeners: an array callable passed to `Event::listen()`, a closure listener typed by its parameter, a union-typed `handle()`, and subscribers registered in `$subscribe` or with `Event::subscribe()` (#55).
- **`model` lists local scopes and the model's own attributes.** `scopeOpen()` and `#[Scope] paid()` are listed, and `--only scope` shows both kinds. The `SoftDeletes` scope is listed once. `#[Table('legacy_lines')]` is the table (#75, #77). The model's own builder and collection (`newEloquentBuilder()`, `#[UseEloquentBuilder]`, `newCollection()`, `#[CollectedBy]`) are listed, and typing uses them: `Person::query()->adults()` is `PersonBuilder::adults`, and the chain goes on (#77).
- **A call on a class nothing declares is unresolved, never `resolved`.** A type that is in no index, nowhere Composer would put it and not PHP's own, in a namespace the project has code in, is almost always a missing `use`. A call on it was a `resolved` edge to a class that does not exist, and was missing from `callers-of` the real class. It is now unresolved: "App\Probes\StripeGateway: no such class is declared anywhere (a missing import?)", in `calls-from` and in `callers-of` (#69).
- **A `@template` name is never a class.** `@return list<TModel>` was read as a list of `App\…\TModel`, and calls on its elements were `inferred` calls on that phantom class (#61).
- **`self $other` as a parameter type is the declaring class**, not a class called `self`.
- **`self` in a trait is the class using it.** `?self` and `self` in a trait's signatures were bound to the trait, so `FindsHost::findBySlug('x')->only()` stopped at "Finds declares no only()" (#37).

### Added

- **More expressions are typed.** A caught exception, `catch (GatewayFailed $e)`, is the class it names (#36). A `match` whose arms agree is that class (#38), and so is `$this->cached ??= new Gateway` (#38). The arm of a `match (true)` that tests `$x instanceof C` narrows `$x` (#38). A backed enum's `from()` and `tryFrom()` return the enum and `cases()` a list of it (#39). PHP 8.5's `$x |> $this->m(...)` and `$x |> (fn (Holder $h) => …)` are what the callable returns, and `clone($x, [...])` is a copy of `$x` rather than a call to a function named `clone` (#83).
- **Documented element types are followed.**
  - A generic class's last parameter is what it holds: `@param Collection<int, Invoice> $c` types `foreach ($c as $i)`, `$c->first()`, `$c->filter()->first()` and an `each(fn ($i) => …)` callback.
  - A class template is bound the same way: `@param Box<Gateway> $b` makes `$b->get()` (`@return T`) a Gateway (#60, #61).
  - Keyed and positional destructuring take their types from an `array{…}` shape or a list (#60).
  - `@return static` on a method natively typed `self` is the receiver's class, so a fluent call from a subclass finds the subclass's methods (#61).
- **Test doubles are the class they double.** `$this->createMock(Gateway::class)`, `createStub()`, `createPartialMock()` and `Mockery::mock()` are a Gateway (`inferred`), not a `MockObject` with methods it doesn't have (#61).
- **More Eloquent chains continue.**
  - A relation method without a return type is read from its body, as the relation property already was (#56).
  - `when()`, `unless()` and a builder's `tap()` continue as the builder when the callback hands it back, and the callback's untyped parameter is that builder. `tap($x, fn ($y) => …)` returns `$x` and passes it on (#59).
  - The untyped parameter of an `each()`, `map()`, `filter()` (and similar) callback on a collection or builder of a model is the model. A `chunk()` callback's parameter is a collection of them (#59).
  - `map()` on a model collection continues as a collection rather than stopping (#59).
  - A factory state method without a return type keeps the factory, as one returning `static` already did (#82).
- **A property the class never declares is typed from its assignments.** `$this->gateway = $gateway` in a constructor, from a typed parameter, types `$this->gateway` the way a declared but untyped property already was. It is listed as a member at its first assignment (#45).

## [0.9.1] - 2026-11-19

`query` and `check` no longer count an interpolated string as a literal argument.

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Fixed

- **An interpolated string is not a literal.** `--arg-is-literal` and `--arg-is-not-literal` counted `"services.{$name}.timeout"`, and a heredoc with variables, as literal strings, including inside an array. So `query` and `check` put such a call on the wrong side of the predicate (#28).

## [0.9.0] - 2026-11-18

The signed-in user is followed. `auth()->user()`, `Auth::user()` and `$request->user()` are the model `config/auth.php` names. In the dogfood run, unresolved receivers went from 124 to 58 on Monica and from 160 to 96 on Firefly III.

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Added

- **The signed-in user is the app's user model.** `auth()->user()`, `Auth::user()`, a guard's `user()` and `$request->user()` (a form request's too) stopped at "declares no user()", because the framework declares only the `Authenticatable` contract. The model is now read from `config/auth.php`, as `User::class` or the default of `env('AUTH_MODEL', User::class)`, and the call is that model, as `inferred`. Where the providers name more than one model, or none that can be read, it stays unresolved and says why. In the dogfood run, unresolved receivers went from 124 to 58 on Monica and from 160 to 96 on Firefly III.

## [0.8.0] - 2026-11-18

What comes out of a documented array is followed. `$pages[0]`, `$chain['book']` and `foreach ($pages as $page)` take their class from the array's `@var`, `@param` or `@return`. On BookStack, unresolved receivers in the dogfood run went from 216 to 79.

Bumping the version and the index schema changes the symbol cache's identity, so every project re-indexes once.

### Added

- **What is read out of a documented array is typed.** An array a docblock describes, `@return array{book: Book, page: Page}`, `@var Page[]`, `@param list<Page> $pages` or an inline `/** @var array<string, Page> $byName */`, now types what comes out of it: `$chain['book']`, `$pages[0]` and `foreach ($pages as $page)`, as `inferred`. A key the shape doesn't name, or an element that isn't one class, stays unknown, and the array itself is never taken for a receiver. A method's or property's documented elements are kept in the index (schema v7), so every project re-indexes once. On BookStack, unresolved receivers in the dogfood run went from 216 to 79; on Matomo from 331 to 313.

## [0.7.0] - 2026-11-17

Code written before native types is followed. An untyped property or method takes its class from what the class's own code assigns or returns, and from its docblock only where the code doesn't settle it. On Matomo, unresolved receivers in the dogfood run went from 547 to 331.

Bumping the version and the index schema changes the symbol cache's identity, so every project re-indexes once.

### Added

- **A property or method with no native class type is typed from its class's code, then from its docblock.** Code written before native types stopped at "property has no declared class type" or "method declares no class-like return type" on every chain through it. Where every assignment to `$this->prop` in the class names one class (`new`, a typed parameter, `$this`), that class is the property's type. Where every `return` names one class (those, plus a typed property or a same-class method's declared type), it is the method's. Only where the code doesn't settle it are `@var` and `@return` read. Both answers are `inferred`, never `resolved`, with the evidence as the reason. The index schema moves to v6, so every project re-indexes once.

## [0.6.0] - 2026-11-17

Calls into the framework are followed. A method declared only in a vendor parent or trait is typed from its declaration, BookStack-style relations returned through a helper name their target, and an ancestry lookup that re-walked the hierarchy on every call is memoised. On BookStack, unresolved receivers in the dogfood run went from 324 to 216.

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Added

- **A method declared in a framework parent or trait is typed from its declaration.** `$this->guard()` in a controller using `AuthenticatesUsers` stopped at "declares no guard(), and its ancestry leaves the indexed source". Where the index and the magic layer have nothing, the ancestry is now read on into `vendor/`. A single native class return type is the answer, and failing that a single class in `@return`, as `inferred`. That's the rest of #13.
- **An element of a collection, by index.** `$orders[0]` on a collection of a known model is that model.

### Fixed

- **`SymbolGraph::ancestorsOf()` is memoised as intended.** The memo was read but never written, and a loop variable shadowed its key, so each call re-walked the hierarchy with an index query per step. That was about 16,000 queries in one reference search on a 1,600-file app.
- **A relation built into a variable and returned through a helper is followed.** `$relation = $this->hasMany(Page::class)->orderBy(…); return $this->ordered($relation);`, as BookStack writes its relations, named no target in what it returned. Where the method declares a relation return type and every relation factory call in its body names one class, that class is the target.

## [0.5.0] - 2026-11-17

The Laravel references release. Measured on a real application, `references-to` left 394 receivers unresolved over eight model members, and this release takes that to 13 with nothing previously resolved lost. Eloquent chains, relations, closures, the framework's helper functions and ide-helper annotations are all followed now, and a search on a common name runs about 2.5× faster. `model` and `schema` stop dropping relations, defaults and indexes they used to leave out.

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Changed

- **`references-to` and `callers-of` are about 2.5× faster on a common name.** Every assignment in every candidate file used to be typed as it was met, though most variables are never the receiver of the call being searched for. Each one could read a class's whole ancestry, often through `vendor/`, for its `@method` tags. A variable's type is now worked out the first time it's used, against the scope as it stood at the assignment. On a 568-file Laravel app, eight model-member searches went from 41.6 s to 16 s, and peak memory halved, with byte-identical output. A corpus class's parents are also read from the index rather than by parsing its file.

### Added

- **Relations continue a chain to the model they point at.** `$user->posts()->create(...)`, `->latest()->first()`, `$user->posts->first()`, `foreach ($user->posts as $post)` and a to-one `$post->author` now reach the related model, read from the relation method's own `return $this->hasMany(Post::class)`. It only applies where the method is declared to return one of the framework's relation classes; a custom relation class keeps its own methods. `inferred`.
- **A ternary narrowed by `instanceof` is typed.** `$x instanceof Order ? $x : null` is an `Order` (`resolved`). A ternary or `??` whose branches agree, or whose other branch is `null`, takes that type.
- **Installed packages' helper functions are typed.** `request()->user()` stopped at "the return type of request() was not read", because the helpers live in `vendor/`. The files Composer autoloads (`autoload_files.php`) are now read the first time a function isn't in the index. A native return type naming one class is used as declared. A docblock `@return ($key is null ? Request : mixed)` is evaluated against the call: an argument left out or passed as `null` takes the first branch. On a real app this excluded the `request()->user()` calls that buried every model's `user` relation (#13).
- **Eloquent's convention outranks a generated annotation.** laravel-ide-helper's `@method static UserFactory factory()` and `@method static Builder|User query()` name the bare framework class, so a chain read through them stopped at the factory or builder. A model's static call or framework method now takes Eloquent's typing unless the model really declares the method.
- **Eloquent chains are typed as the model they hand back.** `User::factory()->create()`, `User::find($id)`, `Order::query()->where(...)->latest()->first()`, `foreach (Order::all() as $order)` and `$orders->first()` now give their receiver the model, as `inferred`. Measured on a real application, these were 79% of the receivers `references-to` could not type. The model's own local scopes and its factory's `static` state methods are followed. Anything outside a closed list of builder, factory and collection methods (a macro, `when()`, a callback) still stays unknown (#11).

### Fixed

- **A closure no longer wipes out the method it is written in.** Leaving any closure or arrow function reset the scope to empty, so after the first `map(fn …)` in a method, every parameter and variable it had typed was unknown. A controller's `$request->user()` after a callback was "receiver type unknown". The enclosing scope is now restored. Inside, an arrow function sees the enclosing scope and a `function () use (…)` sees what it `use`s, as PHP gives them. On a real app this settled another 22% of the unresolved receivers that #11 left.
- **A method declared to return `static` or `self` continues the chain.** The return type was taken literally, as a class called `static`, so every fluent chain through one stopped there. `static` is now the receiver's class and `self` the declaring class.
- **`model` lists a model's attribute defaults.** `$attributes` was read but its facts went to a group the output never rendered, so every default was silently dropped. A literal `null` default was also reported as "the cast is computed". Defaults now have their own `attribute defaults` group (`--only attributes`), `null` is a value, and an expression the reader doesn't evaluate is declined as "the default is computed". Found on a real app's `User` model.
- **`model`'s notes line up.** A fact with no value didn't pad its name, so its note started in a different column from the lines around it.
- **`schema` counts the indexes written on a column.** A table whose indexes were all inline (`->unique()`, `id()`) reported "0 indexes" beside columns marked `unique`. Inline `unique`, `index`, `primary`, `fullText` and `spatialIndex`, and the primary key that `id()` and the `*increments` types carry, are now indexes of the table. A foreign key isn't, since it's a constraint that only some drivers index (#6).
- **An annotated property says what backs it.** Every `@property` line in `model` ended "checked by nothing", even beside the cast that backs it. Each one now says what backs it ("backed by a cast and a column"), that nothing does when the migrations were read to the end, or that it wasn't checked when they couldn't be (#6).
- **A relation of a custom class is reported, not dropped.** A method returning a package's own relation class (`BitemporalMany extends HasMany`) appeared nowhere in `model`'s output, so a model's main relations could vanish without a word. It's recognised by its ancestry and declined with the core relation it extends, the factory that builds it, and the class it's given (#4).
- **Overrides of Eloquent's relation factories are not relations.** A trait overriding `newBelongsTo()`, `newHasMany()` and the rest was reported as declaring those relations. Only a public method with no required parameters can be a relation, which is all Eloquent can use as one (#5).
- **`static::class` in a trait names the model, not the trait.** `model` reported a tree trait's `belongsTo(static::class)` as a resolved relation to the trait itself, which was confidently wrong. Now `static::class` names the model being read, `self::class` names the class that uses the trait (directly or through another trait), and `parent::class` names that class's parent. Where the parent is outside the readable ancestry, it's declined rather than answered with the class itself (#3).
- **Concurrent runs no longer fail with "database is locked".** Every project shares one cache file, so two runs at once, in different projects or the same one, wrote to the same database. Each write batch started with a deferred `BEGIN`, read, and then wrote, and in WAL mode SQLite refuses to upgrade a read to a write if another process committed in between. It refuses at once and ignores the busy timeout. Batches now take the write lock when they start, with `BEGIN IMMEDIATE`, so the second run waits. Six projects each re-reading a changed file fifteen times in parallel failed 20 of 90 runs before the change and none after it.

## [0.4.0] - 2026-10-15

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Changed

- **A long unresolved list is summarised by reason.** On a 7,000-file application, `references-to` for a service's `create` method printed `12358 references (1 resolved, 0 inferred, 12357 unresolved)` and then 12,357 lines, almost all `Model::factory()->create()`. The agent that ran it read the first line and went back to grep. With more than ten unresolved hits, `references-to` and `callers-of` now print one line per reason with its count and three examples, and `--all-unresolved` lists every hit. In `--json` each group is a record with its count, followed by its examples.
- **The skill is rewritten around the moment to use it.** Of 481 PHP greps in 37 sessions, about 175 were the shapes exocomp answers better, and the skill was loaded in 1 session of about 68. The description now names those shapes: `grep -n 'function x'` then `sed -n`, `grep -rl 'class X'`, grepping a name for its callers, and guessing method names. It also says where grep is still the right tool. `SKILL.md` drops from 390 lines to 42, and the detail moves to `reference.md` beside it.
- **A command line that does not parse exits `2`, and names what was probably meant.** It used to exit `1`, which this tool uses for "not found", so an invented `callers-of --depth 3` read as an empty answer. A misspelt option now gets the nearest real one, and an option that belongs to another command gets that command's name.

### Added

- **Calls proved not to be on the target are excluded, and the header counts them.** A call on a value that Eloquent's own API returned from a model cannot be a call on a class with no ancestor outside the indexed source. Eloquent only returns framework classes, or application classes that extend them. These calls are left out, the header gives the count (`…, 11666 excluded`), and a note states the rule. A callback, a `::class` argument, a value read out of the model, a scope, a macro, or an application method of that name on a framework subclass leaves the call unresolved instead. On the search above, 12,357 unresolved became 450, and the first screen answers the question: nothing calls it.
- **Container lookups are typed.** `app(X::class)`, `resolve(X::class)`, `app()->make(X::class)`, `$this->app->make(X::class)` and `App::make(X::class)` return an `X` (`resolved`), or the class a provider binds `X` to (`inferred`). "The return type of app() was not read" was the commonest reason a hit went unresolved. `vendor/` is not needed, because the argument is the answer.

## [0.3.0] - 2026-10-15

Bumping the version changes the symbol cache's identity, so every project re-indexes once.

### Fixed

- **`callers-of` and `references-to` on a common member name ran for minutes.** Every symbol lookup in the index was driven from the project's list of files rather than from the symbol, so each one walked the whole corpus. That was about 45 ms per lookup on a 7,000-file application, and a search for `create` makes thousands of them. Lookups now start from the symbol (`CROSS JOIN` fixes the order; planner statistics would not survive a fresh cache), and `symbols.fqn` has an index. Measured on one application across five searches that had been timing out: 62–191 s became 11–31 s, with byte-identical output.
- **The parse cache evicted the classes used most.** It evicted in load order, so `Model` and its traits, walked for every model receiver, were re-parsed twenty times in a single search. It is now least-recently-used. Annotations, `__call` forwarders and `extends` clauses are kept per class after the parse is evicted.
- **Colour escapes reached pipes when `FORCE_COLOR` was set.** Help, the command list and usage errors went through Symfony's formatter, which colours whenever `FORCE_COLOR` is set, wherever the output is going. Colour now needs a terminal on both stdout and stderr, or `--ansi`.

### Added

- **`--time-limit` on `callers-of` and `references-to`** (default 60 s, `0` for none). A search that reaches the limit stops opening files and answers with what it found. The header says it is incomplete and how many files were not searched, and a note (carried by `--json` too) repeats it. On a terminal, a progress line is drawn on stderr.
- **A member not found where it was asked for is found where it is declared.** `show` answers a member inherited from a parent or trait rather than refusing. On `show`, `members <class>::<member>`, `callers-of` and `references-to`, a member the class does not have is answered from the one related class that declares it (a subclass, an implementor, a used trait, or a trait a subclass uses), with a note. If several related classes declare it, or only unrelated ones do, every declaration is listed with its relationship and the command exits `1`.
- **`members <class>::<member>` narrows to that member.** It used to ignore the member half of the address.

### Changed

- `callers-of` and `references-to` exit `1` when the member provably does not exist on the class (its ancestry is readable to the end) and no related declaration could be answered instead. Every match in that case is a name, not a reference to anything asked about.

## [0.2.0] - 2026-09-16

Six changes, every one of them traced to a specific failure in ten days of real agent
transcripts: 259 invocations across 17 sessions, read alongside the grep calls that were made
instead. Adoption itself was not the problem — exocomp calls per session went from 0.8 to 10.7
once the binary was reachable, and the searches it exists to replace fell 64%. These are the
things that were still going wrong afterwards.

Bumping the version changes the symbol cache's identity, so every project re-indexes once. That
is required rather than incidental: the analysis layer now describes anonymous classes, and the
source roots have grown, so a cache built under 0.1.0 is missing rows.

### Added

- **A file may stand in for a name, in `show`.** Addresses are names because names survive edits,
  and that remains the rule — but the largest category of symbol searches still being done with
  grep was `grep -n 'function processBatches' -A 60 <path>`: a reader who already had the file,
  guessing a body length rather than spending a round trip working out the namespace. A path is
  what comes out of a diff, a stack trace or a previous answer, so it is now an address:
  `show 'app/Services/OrderService.php::place'`, or the path alone where the file declares one
  class-like. The member half is still a name, so the address survives an edit above it. Paths
  resolve against the working directory and the project root, since answers report the second.
  - **This is the only way to read a Laravel migration.** A migration is `return new class
    extends Migration` — anonymous, with no name for any command to take, and on a real
    application a four-figure count of files holding the only written record of how a table came
    to be what it is. Anonymous classes were recorded as unaddressable; they are now described
    like any other class, and their members print exactly as a named class's do.
  - Ambiguity is refused rather than guessed at. Two class-likes in one file, or two members of
    that name, are reported with the candidates and their lines named.
  - `show --at <path>.php:line` prints the innermost declaration covering a position, for one
    that came out of a trace. A position does not survive an edit, which is why it is a flag and
    not an address form.

- **`locate` takes several names.** "Which of these exists, and where" is the real question far
  more often than one name is; it was being asked as a shell `for` loop, or as four names OR'd
  into one grep. One name keeps the answer it has always had.

- **`members --matching <regex>`**, filtering on the member name alone. Piping this command's
  output into `grep` was the commonest thing done to it — 25 times in ten days — and it quietly
  gave back the silent truncation the output contract exists to prevent. The header reads
  `(2 of 214 members matching)`, so a narrow pattern cannot read as a small class.

- **Source directories a framework loads by scanning are indexed.** Composer's autoload sections
  are the authority on where a project's classes live and are not the whole story: Laravel's
  migrations appear in none of them, which is why nothing derived from the map could see inside.
  `autoload.classmap` directories were being dropped for the same reason, and that is how a
  freely-named test suite is declared. On the application this was measured against, the roots go
  from four to six and the index from 6,627 files to 7,081.

### Changed

- **A guessed namespace is answered rather than reported.** Seven of every ten `show` and
  `members` failures were a real class asked for under a guessed namespace, and five of those ten
  printed the right name as a note and then made the caller ask again — a round trip spent on a
  fact the tool had already worked out and already said out loud. Where exactly one declared name
  carries that short name, the question is answered with it and the substitution is stated in
  terms that cannot be skimmed past. Where two do, the ambiguity is real and the miss stands with
  both named. It is withheld on any lookup the caller did not type, because walking an ancestry
  asks about hundreds of names that legitimately do not exist and substituting there would
  fabricate a hierarchy. This supersedes the 0.1.0 behaviour of naming the real declaration in
  the error. A corollary: the namespace is now optional when unambiguous, so
  `show 'OrderRepository::find'` is a legitimate address.

- **A token ceiling of ~25,000 applies whenever output is not a terminal**, and says so when it
  bites. Of 236 real invocations, 234 piped the answer into `head -N` and 7 used `--limit` or
  `--max-tokens` — 33 to 1 against an instruction that is written down, explained, and had
  already been restated three times. A third of those pipes cut the answer; `head` cuts silently,
  destroying the one guarantee this tool makes about its own output, and takes the exit code with
  it, as one session proved in passing by printing `0` from `exocomp locate X | head -40; echo $?`
  after a miss. Guidance was not going to fix that. The ceiling is roughly eight times the largest
  answer ever measured against a median of 1KB, so it cannot ration ordinary work; it exists to
  stop a `members` on a god object filling a caller's context in one call. An explicit
  `--max-tokens` always wins, `--max-tokens 0` included.

### Fixed

- **`locate` returned confident false negatives from an index that had not finished building.**
  It was the only command with nothing to fall through to: every other one tries Composer's map
  when a name misses, and this one reported whatever the index said. On a worktree whose first
  pass was incomplete that produced "nothing named `Gateway` is declared" while `show` read
  `App\Models\Billing\Gateway` out of the same checkout seconds later — eight such answers in one
  morning, all on worktrees created that day, none afterwards. A wrong "no" is worse than the grep
  it replaced, because grep never lies with authority, and this one landed in the command the
  guidance names as the way out of a wrong guess. The source roots are now read before any
  negative is returned, and the negative states the size of the index behind it and that the bytes
  were read. `--no-sync` opts out and says it has, so the faster answer can never claim the
  stronger guarantee.

## [0.1.0] - 2026-09-11

### Added

- **`show` takes several addresses at once.** Read back from real sessions, the commonest thing
  to follow `members` was not another command but `sed -n 'X,Yp'` — having learned where three
  members are, the reader wanted their bodies, and one line range beat three round trips. The
  range is the worse answer in a way that does not announce itself: it is correct only while
  every member sits in one file, contiguously, with nothing else in between, and a member that
  turns out to be inherited is not in that file at all. `show 'A::x' 'A::y' 'B::z'` prints them
  in the order asked for, across classes and files.
  - A missing address does not withdraw the others. It is listed in the trailing block with its
    reason, including the real namespace when the short name is declared elsewhere, and the exit
    code is `1` if even one address is missing — a partial answer must not read as a whole one
    to `exocomp show a b && …`.
  - One address is unchanged, deliberately: a lone header and body, and `--json` still a single
    object rather than a stream of one. Several addresses emit one JSON record per line, led by
    `{"found": false}` when the answer is partial.

- **Magic members.** Members that exist at runtime and are declared nowhere the call site can
  see. Counted over the `app/` tree of one measured 4,516-file Laravel application: 4,155
  `@property` tags, 3,552 `@method`, 265 `@mixin`, 271 accessors and 180 local scopes — against
  ten `__get` bodies, one `__call` and two macro registrations. Almost none of it is the
  interesting problem; almost all of it is written down somewhere the parser has already read.
  - **Framework conventions** resolve to the declaration they are spelled differently from:
    `Order::active()` to `scopeActive()`, `$order->display_name` to `getDisplayNameAttribute()`
    or to the newer method returning `Attribute`, and a relation read as an attribute to the
    relation method. These reach `resolved`, because the transform is a total function of the
    name and the target is found and checked — but only once the installed framework has been
    confirmed to still implement the convention. Where it cannot be read they degrade to
    `inferred` and say so.
  - **`references-to` searches the call-site spelling too.** Finding every reference to
    `scopeActive` means finding every `->active()`, and no call site writes the declared name;
    the old answer was the declaration alone, reported with full confidence. The alternative
    spelling is paired with the owner's short name so the search stays narrow — `active` appears
    in 719 files of that corpus against 9 for `scopeActive`.
  - **Annotations** — `@method`, `@method static`, `@property`, `@property-read`,
    `@property-write` and `@mixin` chains — resolve at `inferred` and can never be more. An
    annotation is a claim about the code rather than a fact of it, and the reason names the file
    it came from, because 261 of those 265 `@mixin` tags point into a generated helper.
  - **A single-statement `__call` or `__get` forwarder** is followed exactly one step to its
    typed target, the same rule already applied to container bindings. A body that computes its
    target is declined.
  - **Macros** are answered with where they were registered and never with a target: a closure
    is not a declaration, and whether the provider that registers one has run on the path being
    asked about is not something the source says.
  - **`model` reads accessors and the `@property` block**, and reconciles the four independent
    accounts of a model's attributes — the migration history, `$casts`, the accessors and the
    annotations — reporting the annotated properties that nothing else backs. Generated blocks
    go stale silently and nothing else in a PHP toolchain says so. Measured on 120 real models:
    3 flagged, one of them a property with no column, cast, accessor or relation whose name
    appears nowhere else in the file.
  - **A class declaring `__call` is said to**, everywhere the answer would otherwise read as a
    call that will not run.

- **Phase 5 — reachability.** The query the read engine was built to make possible.
  - `reaches <from> <to>` — whether a call path exists, with the path itself and the confidence
    of its weakest step. The destination is a shape rather than an address: `Order::save` for
    that member, `Order` for anything on the class, `::save` for any class's, with globs in
    either half.
  - `route:<pattern>` as a starting point sweeps every matching endpoint in one call. Measured
    on a 950,000-line application: a hundred endpoints checked for a model write in 22 seconds,
    twenty-five of them reaching it.
  - A negative answer prints its evidence and exits 1 — how far the walk got, whether a budget
    cut it short, and every edge it could not follow. When nothing was skipped it says so, which
    is the strongest thing a "no" can say and is only printed when it is true.
  - `graph <symbol> --depth N` — the bounded outward neighbourhood, grouped by step, with the
    same trailing block. Outgoing only: `callers-of` already answers the inverse by searching
    the corpus rather than expanding every declaration in it.
  - Six kinds of framework edge close the joints a language-only graph is missing: a dispatched
    job to its own `handle()`, an event to its registered and discovered listeners, a facade to
    the class the container resolves, an interface to its binding and to its implementations,
    and a class pulled out of the container to the method called on it.
  - Dependencies are a wall by default. The edge into one is recorded and checked against the
    destination, so a path ending at a framework method is found; `--through-vendor` walks on.

- **Phase 4 — the Laravel framework profile.** Everything it knows is derived by parsing
  ordinary PHP and SQL. Nothing boots the application: the disposable worktrees this tool is
  built for routinely lack a working `.env` and a reachable database, and a profile that needs
  one is unavailable exactly when someone is spelunking an unfamiliar branch.
  - `model <class>` — table, attributes, casts, relations **and their target classes**, global
    scopes and traits, resolved through parents and traits, each fact naming the class it came
    from. A scope registered by a trait's `bootX()` method is found even though it appears in no
    body on the model — the construct at the centre of the cross-tenant bug family.
  - `facade <Facade[::method]>` — the accessor, the container key, the concrete class and the
    method's declaration. The core alias table is parsed out of `registerCoreContainerAliases()`
    in the installed framework rather than hardcoded.
  - `schema [table]` — a table's columns and indexes, from the squashed schema dump plus every
    migration since, in that order. Reported as `inferred` throughout: it is the source's
    account of the schema, never the database's.
  - `config <key>` — where a key is defined and everywhere it is read, including reads whose key
    is computed, which are reported rather than dropped.
  - `bindings [filter]` — interface to concrete, including contextual bindings, which are the
    least visible kind.
  - `routes [filter]` — URIs resolved through every enclosing group prefix, with resource routes
    expanded to the endpoints the framework generates.
  - `dispatches <class>` — where a job or event is sent and what receives it: the edge that
    leaves a framework call graph disconnected at its most interesting joint.
  - `morph-map [name]` — the polymorphic alias map, followed into the config file when it is
    registered from one. Every answer says that these keys are stored in the database.
  - Tier E fixtures: a Laravel-shaped application that is never installed and never booted.
- **Phase 3 — inference and references.**
  - `references-to <symbol>` — every reference to a class or member, grouped `resolved` /
    `inferred` / `textual`. A same-named member on an unrelated class is excluded; one on a
    subclass is included; everything whose receiver cannot be established is reported with the
    reason rather than dropped or claimed.
  - `callers-of` and `calls-from` — the call graph in either direction, with edges that cannot
    be resolved reported rather than omitted.
  - `--receiver-type`, `--receiver-implements` and `--receiver-extends` on `query` and in rule
    files. A receiver whose type cannot be established does not match, because letting it
    through would quietly turn a scoped audit into an unscoped one.
  - Inference scoped to what is written down: declared types, promoted properties,
    `$this`/`self`/`static`/`parent`, `new X`, assignment from a resolved call, enum cases, and
    docblocks via `phpstan/phpdoc-parser` — always `inferred`, never `resolved`.
- **Phase 2 — structural query and the CI ratchet.**
  - `query <pattern>` — the code shape you are looking for, written as PHP. `$UPPERCASE` matches
    any node, `_UPPERCASE` any name, and `...` / `()` / `{}` any arguments, parameters or body.
    Predicates: `--in`, `--not-in`, `--annotated`, `--has-attribute`, `--arg-is-literal`,
    `--arg-is-not-literal`, `--param-count`, `--visibility`, `--returns`, `--uses-trait`,
    `--extends`, `--implements` — all syntactic, so nothing is guessed.
  - `check [rules.json]` — run a saved rule set against a baseline, exit non-zero on anything
    new. Baseline entries are keyed by rule, file, enclosing declaration and matched code rather
    than by line, so an unrelated edit above a waived violation does not fail the build.
  - The index now records every name a file *mentions*, so a corpus-wide query opens only the
    files that could possibly match. On a 950k-line codebase that took the audit's own worked
    example from 12.6s to 280ms, scanning 12 files instead of 6,536, for an 11% larger index.
- **Phase 1 — the index, the inheritance graph, and history.**
  - `locate <name>` — where a short or fully-qualified name is declared.
  - `subclasses`, `implementors`, `traits-users` — the inheritance graph, transitively.
    `implementors` follows `implements` and `extends` together, so a class satisfying an
    interface through an intermediate one is found rather than silently dropped.
  - `overrides <symbol>` — every declaration of one member across its hierarchy.
  - `members <class>` now resolves through traits, parents and interfaces, grouped by where
    each member came from, with anything the index could not reach reported rather than
    omitted. `--own` keeps the declared-only view, answered from the file with no index.
  - `index --status` / `--warm` — maintenance only; nothing else requires it.
  - `--rev <SHA>` on the commands that read one file at a time, answering about a git revision
    rather than the working tree. 193 measured commands piped `git show SHA:file` into grep,
    and no existing PHP intelligence tool addresses this at all. The index-backed commands
    refuse it rather than ignoring it, since the index describes the working tree.
  - `--stdin`, to analyse a buffer the caller already holds.

- **A content-addressed SQLite cache, shared between checkouts.** Facts belong to a file's
  contents rather than its path, so a second worktree of the same commit costs a walk instead of
  a parse: measured on a 950k-line application, 14.0s to build and 1.3s for a second checkout
  with nothing re-parsed. It lives outside the project, because a cache inside a disposable
  worktree dies with it.

- **Phase 0 — the four read commands**, over `nikic/php-parser` with no index, no daemon, no
  type inference and no framework profile.
  - `show <symbol>` — the exact source of a named class, method, property or constant,
    including its docblock; `--without-bodies` for the skeleton.
  - `outline <file|class>` — every declaration with signature, visibility, return type and
    line span, no bodies.
  - `members <class>` — the members a class declares, with what is *not* listed handed back
    alongside the command that would list it.
  - `imports <file|class>` — the `use` map with aliases resolved.
- **The output contract**, from the first commit rather than retrofitted: text by default with
  `--json` opt-in, `file:line:col` leading every record, visible truncation via `--limit` and
  `--max-tokens`, a `resolved`/`inferred`/`textual` confidence on every result, name-based
  addressing, and exit codes 0 (found) / 1 (not found) / 2 (error).
- `--scan`, for the last-resort content search of the source roots.
- **Fixture tiers A, B and F**, with golden-file snapshots of every command. Tier A carries
  PHP 8.4 property hooks and asymmetric visibility as the standing regression guard on
  language-version currency.

### Changed

- `routes` reads the index, so a string-named controller can be given the root namespace it is
  relative to. That root is read off the application's own route provider rather than assumed:
  it is a per-application decision and recent framework versions default it to nothing. Costs
  about a third of a second on a thousand-route table, which is the right trade for class names
  a reader can paste into another command.
- `calls-from` runs on the graph layer's lookup rather than its own copy. A graph that
  disagreed with the command used to check it would be worse than no graph.
- `$this->dispatch(new X)` is read as a dispatch, `inferred` rather than `resolved` because the
  receiver's type is not checked.
- Mutation-testing thresholds raised from 71% to 72% — the first ratchet up in this project,
  against a measured 73.24% over 6,704 mutants. Two consecutive phases have finished above
  72.8%, so it is earned rather than aspirational; it stops short of 73 because a threshold
  within a point of the measurement makes the next commit a coin toss rather than a check.
- Mutation-testing thresholds re-set from 78% to 71%, below the 73.3% a clean run over Phases
  0-3 measures. The source grew roughly fivefold since 78% was measured, so the old figure was
  failing the build against a codebase it was never measured on. This is a drop, not a ratchet,
  and `infection.json5` records it as one along with what the remaining escapes are made of.

- Composer's classmap is now loaded lazily, after PSR-4 arithmetic rather than before it.
  Measured on a 950k-line Laravel application, the dumped classmap contains no application
  classes at all unless `dump-autoload -o` was used, so loading it first cost 33 ms — over a
  third of the tool's working time — to answer questions it could not help with.
- The content scan of the source roots is opt-in behind `--scan` when a classmap exists. It
  cost 1.3 seconds and was being paid on misses, where the slowest answer is "no". Without a
  classmap it still runs unasked, since nothing else is left to try. When skipped, the tool
  reports that it declined and names the flag.
- Anonymous classes appear in `outline`'s trailing block with their line and member count.
  They cannot be addressed by name, so they are not listed as though they could be — but
  omitting them entirely meant an outline could silently drop several methods.
- Dense property signatures carry scalar, `true`/`false`/`null` and constant defaults, because
  for a table name or a lookback window the value is the answer.

### Fixed

- **`--format=paths` no longer emits a blank line** for a record that could not be placed in a
  file. The format is shaped for `xargs`, where an empty line is an empty argument.
- **A guessed namespace is answered with the real one.** Reading real sessions, the commonest way
  a name failed was an agent knowing the class is called `OrderRepository` and guessing it lives
  under `App\Repositories\`. "No declaration of that" was correct and useless — the
  index already held the answer — and the advice given instead, pass `--scan`, cannot place a
  class that is not there under the name asked for, so it cost a second run and returned the same
  nothing. The miss now names where the short name really is declared, and withholds the scan
  advice when it does, because advising a search past the answer is worse than saying nothing.
  The index is read as it stands and never built for this.
- **An accessor's reason described the wrong mechanism for half the rows it was attached to.**
  `model` gave `getDisplayNameAttribute()` and `shortCode(): Attribute` the same account — "read
  as an attribute by the framework's naming convention" — which is true of the first and false of
  the second: the newer spelling is found by its return type and follows no naming convention at
  all. The reason column is the trust surface, and one that is wrong teaches a reader the wrong
  rule about the framework. The resolver had distinguished the two all along; only the command
  did not.
- **A note advised a scan that would have changed nothing.** The "pass `--scan`" advice is worth
  giving about a name someone asked for. The magic ladder asks about hundreds of names that
  legitimately do not exist, and each miss was surfacing it — so a successful search carried a
  warning that its answer might be incomplete, and a reader who acted on it paid a redundant
  re-run for byte-identical output. Speculative reads no longer raise it.
- **`--resolved-only` emitted references a rename must not rewrite.** It dropped only the
  `textual` group and printed the `inferred` one, while its own help called it "the references
  the tool will stand behind" and the design gates the write side on `resolved` alone. A rename
  driven by it would have rewritten references established from a docblock. Nothing had shown it
  up because inferred results were rare on the symbols anyone had tried; this work moved records
  out of "unresolved" and into "inferred", where the flag was quietly emitting them. On six real
  symbols it was emitting nineteen such entries.
- **A corpus-wide reference search no longer exhausts memory.** The magic ladder walks the
  ancestry of each receiver type it meets, and the class source held every parse — 1,077 classes,
  each with its file text and syntax tree, taking one search from 54MB to 511MB and past PHP's
  default limit, where it died rather than answering. The cache is now bounded, and the ladder is
  asked only when the receiver is related to the target or the index says it has a `__call` or
  `__get` to forward through: 1,077 loads become 31 on a typical search.
- **A call target is verified, not assumed.** Knowing the receiver's type was only half of it:
  `calls-from` reported `Repository::findByUuid` as a resolved call against a final class that
  declares no such method, and the fixture corpus had been asserting that output since Phase 3.
  A member that is absent from a class read to the end of its ancestry is now `textual` with the
  reason, and one on a class whose ancestry leaves the corpus is unchanged — an absence there
  proves nothing. `references-to` said the same about any name on a class it had read to the
  end, and now says so once, in a note about the question rather than about each answer.
- **"A parent or trait outside the readable source does"** was printed for every method the
  graph layer could not find, including on classes with no parent and no traits. It now says
  which of the three situations it is in and only claims an ancestry it has evidence for.
- **A value called as a function** — `$handler($x)` — was reported as a dynamic function name
  even when its type was declared. It is a call to `__invoke`, and the call site writes no
  method name at all, which is exactly why a textual search cannot find it.
- **A call to a function the corpus declares** now yields that function's return type.
- **The `__get` caveat** is no longer printed over answers that name the mechanism exactly.
- **A relation built into a variable** and handed back through a helper is read, and one derived
  from another relation is recorded as declined rather than omitted — both gated on the method's
  declared return type being a `Relations\*`.
- A string controller name beginning with a backslash is absolute, and is no longer given the
  route namespace as well. Prefixing regardless produced a name with the root written twice —
  which resolves to nothing, was reported as `inferred` rather than as the resolved fact it is,
  and looked authoritative while being useless.
- "Method not found on X" no longer covers two different statements. A class whose ancestry runs
  off the end of the indexed source has an API far larger than its own declarations — an
  Eloquent model's whole query interface arrives through a magic `__call` on a base class in
  `vendor/` — and reporting an undeclared method there as "not found" read as "this call is
  broken". The answer now names the class the ancestry stops at.
- Negative lookups are cached. `??=` re-ran whenever the cached answer was null, and null is the
  common answer when walking a framework application: every retry was an index lookup, an
  autoload-map lookup and a stat. A nineteen-endpoint sweep went from 26 seconds to 13, with
  system time falling from 17 seconds to 6.
- The locator says each note once. A command that locates one name per node repeated the same
  remark once per node.
- `--limit` applies to the whole answer rather than to whichever list each command remembered
  to pass through the budget. `model` capped only its trailing block, `config` only the reads,
  `dispatches` only the dispatch sites — so a capped answer could be indistinguishable from a
  complete one, which is the failure the contract exists to prevent.
- A property fetch naming a method is `inferred` rather than `resolved`, and says why:
  `$order->customer` reaches the relation method through `__get`, which is a real reference and
  not a fact the engine can see.
- The inheritance walk follows a class's own `extends` clause through Composer's autoload map
  when the index runs out, so a model extending a framework base class is still recognised as
  one, and `subclasses Illuminate\Database\Eloquent\Model` answers instead of refusing.
- Identifiers are folded by ASCII rules, as PHP folds them. `mb_strtolower` merged names the
  engine keeps apart — `Étage` and `étage` are two different classes, and `locate` reported the
  same line for both. The index schema version goes with it, since a cache built under the old
  folding holds merged rows.
- Unqualified calls to global helpers are recognised inside a namespace. The name resolver
  reports `config()` written in `App\Providers` as `App\Providers\config`, so matching on the
  resolved name found nothing in any file that has a namespace — that is, in all of them.
- `--rev` is refused by the commands that cannot honour it rather than silently ignored. The
  index describes the working tree, so `locate X --rev <old SHA>` was reporting a class as
  existing at a commit that predates it.
- A capped default value is cut on a character boundary rather than a byte one. Cutting by
  bytes split multi-byte characters in half, leaving output that was not valid UTF-8.
- `--json` no longer emits an empty object when a record cannot be encoded. It substitutes the
  offending bytes and says so, falling back to an address-plus-error record — an empty record
  in a stream is indistinguishable from one that was dropped.
