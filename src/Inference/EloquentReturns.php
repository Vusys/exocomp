<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Inference;

use PhpParser\Node;
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
    public function fromModel(string $model, string $method, Node\Expr\StaticCall $call): ?ResolvedType
    {
        if (! $this->magic->isModel($model)) {
            return null;
        }

        if (strtolower($method) === 'factory') {
            $first = $call->getArgs()[0]->value ?? null;
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
            : ResolvedType::wrapping(self::BUILDER_FQN, self::BUILDER, $relation[0], sprintf('%s() is a %s relation to %s, by Eloquent convention', $method, $relation[1], $relation[0]));
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
    public function step(ResolvedType $owner, string $method, Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call): ?ResolvedType
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

    private function fromBuilder(string $model, string $method, Node\Expr\CallLike $call): ?ResolvedType
    {
        $name = strtolower($method);

        if (in_array($name, self::BUILDER_TO_MODEL, true)) {
            $first = $call->getArgs()[0]->value ?? null;

            if (in_array($name, self::TAKES_KEYS, true) && $first instanceof Node\Expr\Array_) {
                return ResolvedType::wrapping(self::COLLECTION_FQN, self::COLLECTION, $model, sprintf('%s() with a list of keys returns a collection, by Eloquent convention', $method));
            }

            return ResolvedType::inferred($model, sprintf('%s() returns the model, by Eloquent convention', $method));
        }

        if (isset(self::BUILDER_TO_COLLECTION[$name])) {
            return ResolvedType::wrapping(self::BUILDER_TO_COLLECTION[$name], self::COLLECTION, $model, sprintf('%s() returns the models, by Eloquent convention', $method));
        }

        if (in_array($name, self::BUILDER_KEEPS, true)
            || str_starts_with($name, 'where') || str_starts_with($name, 'orwhere') || str_starts_with($name, 'with')
            || $this->isLocalScope($model, $method)) {
            return ResolvedType::wrapping(self::BUILDER_FQN, self::BUILDER, $model, sprintf('a query builder of %s, by Eloquent convention', $model));
        }

        return null;
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
        $type = $this->members->declared($factory, $method, SymbolKind::Method)?->typeFqn;

        return $type !== null && (in_array(strtolower($type), ['static', 'self'], true) || strcasecmp($type, $factory) === 0);
    }

    private function isLocalScope(string $model, string $method): bool
    {
        return $this->members->declared($model, 'scope'.ucfirst($method), SymbolKind::Method) instanceof SymbolRecord;
    }
}
