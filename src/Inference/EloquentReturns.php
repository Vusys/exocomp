<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Symbol\Confidence;
use Vusys\Exocomp\Symbol\SymbolKind;

/**
 * What Eloquent's own API hands back from a model, so that a chain written against it ends at
 * the model rather than at "receiver type unknown".
 *
 * `User::factory()->create()`, `Order::query()->where(...)->first()`, `foreach (Order::all() as
 * $order)`: in a measured application these were 79% of the receivers `references-to` could not
 * type, and every one of them names its model in plain sight. The framework's methods are in
 * `vendor/` and declare `static`, `mixed` or a generic, so nothing in the index says so; the
 * shape of the API does, and that is what this reads.
 *
 * Everything here is {@see Confidence::Inferred}: it is the framework's
 * convention, not a declaration the tool has read. And it is a closed list. A method it does not
 * name — a macro, `when()`, `tap()`, anything taking a callback whose result it returns — leaves
 * the chain unknown, as before, because a builder method nobody listed could return anything.
 *
 * ponytail: hand-kept method lists; read the framework's own signatures if a version changes them.
 */
final readonly class EloquentReturns
{
    public const string BUILDER = 'builder';

    public const string FACTORY = 'factory';

    public const string FACTORY_MANY = 'factory of many';

    public const string COLLECTION = 'collection';

    private const string BUILDER_FQN = 'Illuminate\\Database\\Eloquent\\Builder';

    private const string FACTORY_FQN = 'Illuminate\\Database\\Eloquent\\Factories\\Factory';

    private const string COLLECTION_FQN = 'Illuminate\\Database\\Eloquent\\Collection';

    /** Builder methods that return the builder. Anything starting `where`, `orWhere` or `with` does too. */
    private const array BUILDER_KEEPS = [
        'query', 'newquery', 'newmodelquery', 'latest', 'oldest', 'orderby', 'orderbydesc', 'reorder',
        'select', 'addselect', 'distinct', 'limit', 'take', 'skip', 'offset', 'forpage', 'groupby', 'having',
        'join', 'leftjoin', 'rightjoin', 'crossjoin', 'has', 'orhas', 'doesnthave', 'ordoesnthave',
        'without', 'onlytrashed', 'lockforupdate', 'sharedlock', 'inrandomorder', 'whenhas',
    ];

    /**
     * `Conditionable`'s `when()`/`unless()`, and `tap()`, which runs `when(true, …)`: they return
     * what the callback returns, or the builder when that is null.
     */
    private const array CONDITIONAL = ['when', 'unless', 'tap'];

    /** Builder methods (and static calls on a model) that return one model. */
    private const array BUILDER_TO_MODEL = [
        'first', 'firstorfail', 'firstornew', 'firstorcreate', 'createorfirst', 'updateorcreate', 'create',
        'forcecreate', 'createquietly', 'make', 'sole', 'firstwhere', 'find', 'findorfail', 'findornew',
    ];

    /** The ones of those that return a collection instead when handed a list of keys. */
    private const array TAKES_KEYS = ['find', 'findorfail'];

    /** Builder methods that return something iterated as models. */
    private const array BUILDER_TO_COLLECTION = [
        'get' => self::COLLECTION_FQN,
        'all' => self::COLLECTION_FQN,
        'findmany' => self::COLLECTION_FQN,
        'paginate' => 'Illuminate\\Pagination\\LengthAwarePaginator',
        'simplepaginate' => 'Illuminate\\Pagination\\Paginator',
        'cursorpaginate' => 'Illuminate\\Pagination\\CursorPaginator',
        'cursor' => 'Illuminate\\Support\\LazyCollection',
        'lazy' => 'Illuminate\\Support\\LazyCollection',
    ];

    /** Factory methods that return the factory. Anything starting `for` or `has` does too. */
    private const array FACTORY_KEEPS = [
        'state', 'sequence', 'recycle', 'aftercreating', 'aftermaking', 'withoutparents', 'connection', 'configure', 'trashed',
    ];

    private const array FACTORY_TO_ONE = ['create', 'make', 'createone', 'makeone', 'createquietly', 'createonequietly'];

    private const array FACTORY_TO_MANY = ['createmany', 'makemany', 'createmanyquietly'];

    /** Collection methods that return one of its models. */
    private const array COLLECTION_TO_MODEL = ['first', 'last', 'firstorfail', 'sole', 'find', 'firstwhere', 'get', 'pop', 'shift'];

    /** Collection methods that return a collection of the same models. Anything starting `where` does too. */
    private const array COLLECTION_KEEPS = [
        'filter', 'reject', 'sortby', 'sortbydesc', 'sort', 'sortdesc', 'values', 'unique', 'take', 'skip', 'slice',
        'reverse', 'each', 'load', 'loadmissing', 'loadcount', 'fresh', 'except', 'only', 'diff', 'intersect',
        'shuffle', 'keyby', 'makehidden', 'makevisible',
    ];

    /** Model methods that return the model itself, or a fresh copy of it. */
    private const array MODEL_TO_SELF = [
        'fresh', 'refresh', 'replicate', 'load', 'loadmissing', 'loadcount', 'fill', 'forcefill', 'setrelation',
        'setrelations', 'unsetrelation', 'withoutrelations', 'makehidden', 'makevisible', 'append', 'setappends',
    ];

    /** Model methods that start a query of the model. */
    private const array MODEL_TO_BUILDER = ['newquery', 'newmodelquery', 'newquerywithoutscopes', 'newquerywithoutrelationships'];

    public function __construct(private SymbolGraph $graph, private Members $members, private Magic $magic) {}

    /**
     * A static call on a model class: `Order::query()`, `Order::find($id)`, `Order::factory()`.
     */
    public function fromModel(string $model, string $method, Expr\StaticCall $call): ?ResolvedType
    {
        if (! $this->magic->isModel($model)) {
            return null;
        }

        if (strtolower($method) === 'factory') {
            $first = Analyser::arguments($call)[0]->value ?? null;
            $many = $first instanceof Node\Scalar\Int_;

            return $this->factoryOf($model, $many, null);
        }

        return $this->fromBuilder($model, $method, $call) ?? $this->collectionFrom($model, $method);
    }

    /**
     * A method called on a model instance the framework, not the application, declares.
     */
    public function fromInstance(string $model, string $method): ?ResolvedType
    {
        $name = strtolower($method);

        if (! in_array($name, [...self::MODEL_TO_SELF, ...self::MODEL_TO_BUILDER], true) || ! $this->magic->isModel($model)) {
            return null;
        }

        return in_array($name, self::MODEL_TO_BUILDER, true)
            ? ResolvedType::wrapping(self::BUILDER_FQN, self::BUILDER, $model, sprintf('a query builder of %s, by Eloquent convention', $model))
            : ResolvedType::inferred($model, sprintf('%s() returns the model, by Eloquent convention', $method));
    }

    /** Relation factories that hold many models, read as an attribute into a collection. */
    private const array TO_MANY = ['hasmany', 'belongstomany', 'hasmanythrough', 'morphmany', 'morphtomany', 'morphedbymany'];

    /**
     * `$user->posts()`: a relation, which forwards to a query builder of the model it points at,
     * so `->create()`, `->latest()->first()` and the rest end at that model.
     */
    public function fromRelation(string $model, string $method): ?ResolvedType
    {
        $relation = $this->magic->relationTarget($model, $method);

        return $relation === null
            ? null
            : ResolvedType::wrapping(self::relationClass($relation[1]), self::BUILDER, $relation[0], sprintf('%s() is a %s relation to %s, by Eloquent convention', $method, $relation[1], $relation[0]));
    }

    /**
     * The framework's relation class for a relation factory: `hasMany` builds a `HasMany`.
     * A method is looked up there first and forwarded to the query builder only when the
     * relation does not declare it, as `Relation::__call` does at runtime.
     */
    public static function relationClass(string $factory): string
    {
        $class = [
            'hasone' => 'HasOne', 'hasmany' => 'HasMany', 'belongsto' => 'BelongsTo', 'belongstomany' => 'BelongsToMany',
            'hasonethrough' => 'HasOneThrough', 'hasmanythrough' => 'HasManyThrough', 'morphone' => 'MorphOne',
            'morphmany' => 'MorphMany', 'morphto' => 'MorphTo', 'morphtomany' => 'MorphToMany', 'morphedbymany' => 'MorphToMany',
        ][strtolower($factory)] ?? null;

        return $class === null ? self::BUILDER_FQN : 'Illuminate\\Database\\Eloquent\\Relations\\'.$class;
    }

    /**
     * `$user->posts`, a relation read as an attribute: the related models, or the one model.
     */
    public function fromRelationProperty(string $model, string $property): ?ResolvedType
    {
        $relation = $this->magic->relationTarget($model, $property);

        if ($relation === null) {
            return null;
        }

        [$target, $factory] = $relation;

        return in_array($factory, self::TO_MANY, true)
            ? ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $target, sprintf('the %s relation %s, read as an attribute, by Eloquent convention', $factory, $property))
            : ResolvedType::inferred($target, sprintf('the %s relation %s, read as an attribute, by Eloquent convention', $factory, $property));
    }

    /**
     * One link of a chain whose receiver is a builder, factory or collection of a model.
     */
    public function step(ResolvedType $owner, string $method, Expr\MethodCall|Expr\NullsafeMethodCall $call): ?ResolvedType
    {
        $model = $owner->model;

        if ($model === null) {
            return null;
        }

        return match ($owner->wrapper) {
            self::BUILDER => $this->fromBuilder($model, $method, $call),
            self::FACTORY, self::FACTORY_MANY => $this->fromFactory($owner, $model, $method),
            self::COLLECTION => $this->fromCollection($model, $method),
            default => null,
        };
    }

    /**
     * The model a `foreach` over $iterable yields, if it is something that iterates as models.
     */
    public function elementOf(ResolvedType $iterable): ?ResolvedType
    {
        return $iterable->wrapper === self::COLLECTION && $iterable->model !== null
            ? ResolvedType::inferred($iterable->model, 'an element of an Eloquent collection')
            : null;
    }

    private function fromBuilder(string $model, string $method, Expr\CallLike $call): ?ResolvedType
    {
        $name = strtolower($method);

        if (in_array($name, self::BUILDER_TO_MODEL, true)) {
            $first = Analyser::arguments($call)[0]->value ?? null;

            if (in_array($name, self::TAKES_KEYS, true) && $first instanceof Expr\Array_) {
                return ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, sprintf('%s() with a list of keys returns a collection, by Eloquent convention', $method));
            }

            return ResolvedType::inferred($model, sprintf('%s() returns the model, by Eloquent convention', $method));
        }

        if (isset(self::BUILDER_TO_COLLECTION[$name])) {
            return ResolvedType::wrapping(self::BUILDER_TO_COLLECTION[$name], self::COLLECTION, $model, sprintf('%s() returns the models, by Eloquent convention', $method));
        }

        if (in_array($name, self::CONDITIONAL, true)) {
            return $this->handsBackItsArgument($call)
                ? ResolvedType::wrapping(self::BUILDER_FQN, self::BUILDER, $model, sprintf('%s() returns the builder its callback is handed, by Eloquent convention', $method))
                : null;
        }

        if (in_array($name, self::BUILDER_KEEPS, true)
            || str_starts_with($name, 'where') || str_starts_with($name, 'orwhere') || str_starts_with($name, 'with')
            || $this->isLocalScope($model, $method)) {
            return ResolvedType::wrapping(self::BUILDER_FQN, self::BUILDER, $model, sprintf('a query builder of %s, by Eloquent convention', $model));
        }

        return null;
    }

    /**
     * Whether a `when()`-style call's callback returns nothing, its first parameter, or a chain
     * on it: then the call returns the builder. A callback returning anything else hands that
     * back instead, and a call with no callback at all returns a proxy.
     */
    private function handsBackItsArgument(Expr\CallLike $call): bool
    {
        $callback = null;

        foreach (Analyser::arguments($call) as $argument) {
            if ($argument->value instanceof Expr\Closure || $argument->value instanceof Expr\ArrowFunction) {
                $callback = $argument->value;

                break;
            }
        }

        $parameter = $callback?->params[0]->var ?? null;

        if ($callback === null || ! $parameter instanceof Expr\Variable || ! is_string($parameter->name)) {
            return false;
        }

        $name = $parameter->name;
        $rooted = static function (?Expr $expression) use ($name): bool {
            while ($expression instanceof Expr\MethodCall || $expression instanceof Expr\NullsafeMethodCall) {
                $expression = $expression->var;
            }

            return ! $expression instanceof Expr || ($expression instanceof Expr\Variable && $expression->name === $name);
        };

        if ($callback instanceof Expr\ArrowFunction) {
            return $rooted($callback->expr);
        }

        $finder = new NodeFinder;
        $returns = $finder->find($callback->stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\Return_);

        return array_all($returns, static fn (Node $return): bool => $return instanceof Node\Stmt\Return_ && $rooted($return->expr));
    }

    /**
     * `Order::all()`, which is a collection without ever being a builder.
     */
    private function collectionFrom(string $model, string $method): ?ResolvedType
    {
        return strtolower($method) === 'all'
            ? ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, 'all() returns the models, by Eloquent convention')
            : null;
    }

    private function fromFactory(ResolvedType $owner, string $model, string $method): ?ResolvedType
    {
        $name = strtolower($method);
        $many = $owner->wrapper === self::FACTORY_MANY;

        if ($name === 'count' || $name === 'times') {
            return $this->factoryOf($model, true, $owner->fqn);
        }

        if (in_array($name, self::FACTORY_TO_ONE, true)) {
            return $many
                ? ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, sprintf('%s() after count() returns the models, by Eloquent convention', $method))
                : ResolvedType::inferred($model, sprintf('a factory\'s %s() returns the model, by Eloquent convention', $method));
        }

        if (in_array($name, self::FACTORY_TO_MANY, true)) {
            return ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, sprintf('%s() returns the models, by Eloquent convention', $method));
        }

        if (in_array($name, self::FACTORY_KEEPS, true) || str_starts_with($name, 'for') || str_starts_with($name, 'has')
            || $this->returnsItself((string) $owner->fqn, $method)) {
            return $this->factoryOf($model, $many, $owner->fqn);
        }

        return null;
    }

    private function fromCollection(string $model, string $method): ?ResolvedType
    {
        $name = strtolower($method);

        if (in_array($name, self::COLLECTION_TO_MODEL, true)) {
            return ResolvedType::inferred($model, sprintf('a collection\'s %s() returns one of its models, by Eloquent convention', $method));
        }

        if (in_array($name, self::COLLECTION_KEEPS, true) || str_starts_with($name, 'where')) {
            return ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, sprintf('a collection of %s, by Eloquent convention', $model));
        }

        // What `map()` holds depends on the callback, so all that is known is that it is a
        // collection; the chain goes on as one rather than stopping.
        if (in_array($name, ['map', 'flatmap', 'mapwithkeys', 'pluck', 'keys', 'groupby', 'chunk', 'collapse', 'flatten'], true)) {
            return ResolvedType::inferred('Illuminate\\Support\\Collection', sprintf('%s() returns a collection of whatever its callback gives, by Eloquent convention', $method));
        }

        return null;
    }

    /**
     * A factory of $model, as the application's own factory class where there is one, so that a
     * state method declared on it (`User::factory()->admin()`) is found and followed.
     */
    private function factoryOf(string $model, bool $many, ?string $known): ResolvedType
    {
        $class = $known !== null && $known !== self::FACTORY_FQN && $this->graph->classLike($known) instanceof SymbolRecord
            ? $known
            : $this->factoryClass($model);

        return ResolvedType::wrapping($class, $many ? self::FACTORY_MANY : self::FACTORY, $model, sprintf('a factory of %s, by Eloquent convention', $model));
    }

    /**
     * The model's factory: what its own `newFactory()` declares, or the framework's naming
     * convention when that class exists in the corpus.
     */
    private function factoryClass(string $model): string
    {
        $declared = $this->members->declared($model, 'newFactory', SymbolKind::Method)?->typeFqn;

        if ($declared !== null && $this->graph->classLike($declared) instanceof SymbolRecord) {
            return $declared;
        }

        $position = strrpos($model, '\\');
        $convention = 'Database\\Factories\\'.($position === false ? $model : substr($model, $position + 1)).'Factory';

        return $this->graph->classLike($convention) instanceof SymbolRecord ? $convention : self::FACTORY_FQN;
    }

    /**
     * Whether $method on the factory class is declared to return the factory itself, the way a
     * state method is written.
     */
    private function returnsItself(string $factory, string $method): bool
    {
        $declared = $this->members->declared($factory, $method, SymbolKind::Method);

        if (! $declared instanceof SymbolRecord) {
            return false;
        }

        // A state method written before return types, `public function failed() { return
        // $this->state(...); }`, returns the factory by the convention every state method follows.
        $type = $declared->typeFqn ?? $declared->inferredFqn;

        return $type === null || in_array(strtolower($type), ['static', 'self'], true) || strcasecmp($type, $factory) === 0;
    }

    private function isLocalScope(string $model, string $method): bool
    {
        return $this->members->declared($model, 'scope'.ucfirst($method), SymbolKind::Method) instanceof SymbolRecord
            || $this->magic->isAttributeScope($model, $method);
    }

    /**
     * The declaration a call on a builder of `$model` runs, where the model declares it: a local
     * scope, `scopeOpen()` for `->open()` or a `#[Scope]` method of the same name. The builder
     * itself has no such method, so naming it there pointed at nothing.
     */
    public function scopeDeclaration(string $model, string $method): ?string
    {
        $legacy = $this->members->declared($model, 'scope'.ucfirst($method), SymbolKind::Method);

        if ($legacy instanceof SymbolRecord) {
            return $legacy->owner.'::'.$legacy->name;
        }

        $attribute = $this->members->declared($model, $method, SymbolKind::Method);

        return $attribute instanceof SymbolRecord && $this->magic->isAttributeScope($model, $method)
            ? $attribute->owner.'::'.$attribute->name
            : null;
    }
}
