<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Index\MemberOrigin;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Inference\DocBlockTypes;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;
use Vusys\Exocomp\Symbol\Confidence;

/**
 * Reads an Eloquent model the way someone reading the file would, and further.
 *
 * "Further" is the point. A model's table, casts and scopes are routinely declared on a parent
 * or flattened in from a trait, and the 139 measured searches for `$table`, `$fillable` and
 * `$casts` were mostly opening one file, finding nothing, and opening the next. This walks the
 * chain once and says which class each answer came from.
 *
 * Two conventions are reproduced rather than read, and both are labelled: the table name when
 * none is declared, and the scope a `SoftDeletes` trait adds without appearing in any body.
 * Everything else is a literal in the source or is declined.
 */
final class ModelReader
{
    /** @var list<string> */
    private const array SCALARS = ['table', 'connection', 'primaryKey', 'keyType', 'incrementing', 'timestamps', 'dateFormat', 'perPage'];

    /** @var list<string> */
    private const array LISTS = ['fillable', 'guarded', 'hidden', 'visible', 'appends', 'touches', 'with', 'withCount', 'dates'];

    /** @var list<string> */
    private const array MAPS = ['casts', 'attributes'];

    /** Where the framework keeps the types a relation method declares it returns. */
    private const string RELATION_NAMESPACE = 'Illuminate\\Database\\Eloquent\\Relations\\';

    /**
     * The relation factories. A method is a relation because it calls one of these on `$this`,
     * never because of what it is named — `reference()` returning a string is not a relation,
     * and a reader that decided by name would say it was.
     *
     * @var list<string>
     */
    private const array RELATIONS = [
        'hasone', 'hasmany', 'belongsto', 'belongstomany', 'hasonethrough', 'hasmanythrough',
        'morphone', 'morphmany', 'morphto', 'morphtomany', 'morphedbymany', 'hasoneofmany',
    ];

    /** @var list<ModelFact> */
    private array $facts = [];

    /** @var list<ModelFact> */
    private array $declined = [];

    /** @var array<string, true> */
    private array $claimed = [];

    /** @var list<string> */
    private array $notes = [];

    private readonly DocBlockTypes $docblocks;

    public function __construct(
        private readonly SymbolGraph $graph,
        private readonly ClassSource $source,
    ) {
        $this->docblocks = new DocBlockTypes;
    }

    /**
     * The index knows the hierarchy only as far as the corpus goes. A model extending a
     * framework base class — the ordinary shape for anything authenticatable — has that parent
     * recorded as an edge to a class `vendor/` holds and the index does not, so the graph walk
     * halts one hop short and answers "not a model" for a great many models. The `extends` chain
     * is therefore followed through the source as a fallback.
     */
    public function isModel(string $fqn): bool
    {
        if (array_any(array_keys($this->graph->ancestorsOf($fqn)), fn (string $ancestor): bool => strcasecmp($ancestor, LaravelProfile::MODEL) === 0)) {
            return true;
        }

        return $this->source->descendsFrom($fqn, LaravelProfile::MODEL);
    }

    /**
     * @param  ?callable(): array<string, Table>  $schema  the reconstructed tables, used to
     *                                                     corroborate a derived table name
     *                                                     rather than to produce one. Deferred
     *                                                     because replaying a large migration
     *                                                     directory costs about half a second
     *                                                     and a model that declares `$table`
     *                                                     never needs it.
     */
    public function read(string $fqn, ?callable $schema = null): ?EloquentModel
    {
        $chain = $this->source->withAncestors($fqn, stopAt: LaravelProfile::MODEL);

        if ($chain === []) {
            return null;
        }

        $this->facts = [];
        $this->declined = [];
        $this->claimed = [];
        $this->notes = [];

        $own = $chain[0];

        foreach ($chain as $class) {
            $this->readProperties($class, $class->fqn === $own->fqn);
            $this->readCastsMethod($class);
            $this->readRelations($class);
            $this->readScopeCalls($class);
            $this->readScopedByAttribute($class);
            $this->readAccessors($class);
            $this->readAnnotations($class);
        }

        $this->readTraits($own);
        $this->resolveTable($own, $schema);
        $this->reconcile($own, $schema, $this->unreadableAncestry($chain));

        return new EloquentModel(
            fqn: $own->fqn,
            path: $own->path,
            line: $own->node->getStartLine(),
            facts: $this->facts,
            declined: $this->declined,
            notes: [...$this->source->notes(), ...$this->notes],
        );
    }

    private function readProperties(LoadedClass $class, bool $isOwn): void
    {
        foreach ($class->node->getProperties() as $property) {
            foreach ($property->props as $item) {
                $name = $item->name->toString();
                $default = $item->default;

                if ($default === null) {
                    continue;
                }

                match (true) {
                    in_array($name, self::SCALARS, true) => $this->scalarProperty($class, $name, $default, $item->getStartLine(), $isOwn),
                    in_array($name, self::LISTS, true) => $this->listProperty($class, $name, $default, $item->getStartLine()),
                    in_array($name, self::MAPS, true) => $this->mapProperty($class, $name, $default),
                    default => null,
                };
            }
        }
    }

    private function scalarProperty(LoadedClass $class, string $name, Node\Expr $default, int $line, bool $isOwn): void
    {
        // Nearest declaration wins, exactly as PHP resolves it — a parent's `$connection` is
        // only the answer when the model itself does not set one.
        if (isset($this->claimed[$name])) {
            return;
        }

        $value = LiteralValue::scalar($default);
        $this->claimed[$name] = true;

        if ($value === null && ! $default instanceof Node\Expr\ConstFetch) {
            $this->declined[] = new ModelFact('attribute', $name, null, Confidence::Textual, $class->path, $line, 'declared here, but its value is computed', $class->fqn);

            return;
        }

        $this->facts[] = new ModelFact(
            'attribute',
            $name,
            is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            Confidence::Resolved,
            $class->path,
            $line,
            $isOwn ? null : 'declared by '.$class->fqn,
            $class->fqn,
        );
    }

    private function listProperty(LoadedClass $class, string $name, Node\Expr $default, int $line): void
    {
        if (isset($this->claimed[$name])) {
            return;
        }

        $this->claimed[$name] = true;

        if (! $default instanceof Node\Expr\Array_) {
            $this->declined[] = new ModelFact($name, $name, null, Confidence::Textual, $class->path, $line, 'declared here, but not as a literal array', $class->fqn);

            return;
        }

        foreach (LiteralValue::items($default) as $item) {
            if (! $item['literal'] || ! is_string($item['value'])) {
                $this->declined[] = new ModelFact($name, '?', null, Confidence::Textual, $class->path, $item['line'], 'this entry is computed', $class->fqn);

                continue;
            }

            $this->facts[] = new ModelFact($name, $item['value'], null, Confidence::Resolved, $class->path, $item['line'], null, $class->fqn);
        }

        // `$guarded = []` is the framework's "everything is mass-assignable" spelling, and its
        // emptiness is the whole message. Reporting nothing would read as "not declared".
        if ($name === 'guarded' && $default->items === []) {
            $this->facts[] = new ModelFact($name, '(empty — every attribute is mass-assignable)', null, Confidence::Resolved, $class->path, $line, null, $class->fqn);
        }
    }

    private function mapProperty(LoadedClass $class, string $name, Node\Expr $default): void
    {
        if (isset($this->claimed[$name])) {
            return;
        }

        $this->claimed[$name] = true;
        $this->recordPairs($class, $name, $default);
    }

    private function recordPairs(LoadedClass $class, string $name, Node\Expr $expression): void
    {
        foreach (LiteralValue::pairs($expression) as $pair) {
            if ($pair['value'] === null) {
                $this->declined[] = new ModelFact($name, $pair['key'], null, Confidence::Textual, $class->path, $pair['line'], 'the cast is computed', $class->fqn);

                continue;
            }

            $this->facts[] = new ModelFact($name, $pair['key'], $pair['value'], Confidence::Resolved, $class->path, $pair['line'], null, $class->fqn);
        }
    }

    /**
     * Laravel 11 moved casts from a property to a `casts()` method, and plenty of code has both
     * spellings across a hierarchy. Reading only the property would report a model's casts as
     * empty while the file plainly lists them.
     */
    private function readCastsMethod(LoadedClass $class): void
    {
        $method = $class->node->getMethod('casts');

        if (! $method instanceof Node\Stmt\ClassMethod || isset($this->claimed['casts()'])) {
            return;
        }

        foreach (new NodeFinder()->findInstanceOf([$method], Node\Stmt\Return_::class) as $return) {
            if ($return->expr instanceof Node\Expr\Array_) {
                $this->claimed['casts()'] = true;
                $this->recordPairs($class, 'casts', $return->expr);
            }
        }
    }

    private function readRelations(LoadedClass $class): void
    {
        foreach ($class->node->getMethods() as $method) {
            $name = $method->name->toString();

            if (isset($this->claimed['relation:'.strtolower($name)])) {
                continue;
            }

            $call = $this->returnedRelation($method) ?? $this->declaredRelation($method);

            if (! $call instanceof MethodCall) {
                $this->recordDerivedRelation($class, $method, $name);

                continue;
            }

            $this->claimed['relation:'.strtolower($name)] = true;
            $this->recordRelation($class, $name, $call, $method->getStartLine());
        }
    }

    /**
     * The relation a method returns directly, which is how nearly all of them are written.
     */
    private function returnedRelation(Node\Stmt\ClassMethod $method): ?MethodCall
    {
        foreach (new NodeFinder()->findInstanceOf([$method], Node\Stmt\Return_::class) as $return) {
            $call = $return->expr instanceof Node\Expr ? $this->relationCall($return->expr) : null;

            if ($call instanceof MethodCall) {
                return $call;
            }
        }

        return null;
    }

    /**
     * A relation built into a variable and handed back through something else.
     *
     * Package code writes them this way — build the relation, pass it through a scope helper,
     * return that — and reading only the return statement finds nothing, which then reads as a
     * model that has no such relation. The method's own declared return type is what makes it
     * safe to widen the search: a method saying it returns a `Relations\*` type and calling a
     * relation factory on `$this` is a relation, wherever in its body that call sits.
     */
    private function declaredRelation(Node\Stmt\ClassMethod $method): ?MethodCall
    {
        $returns = $method->getReturnType();

        if (! $returns instanceof Node\Name || ! str_starts_with(Analyser::resolvedName($returns), self::RELATION_NAMESPACE)) {
            return null;
        }

        foreach (new NodeFinder()->findInstanceOf([$method], MethodCall::class) as $call) {
            $found = $this->relationCall($call);

            if ($found instanceof MethodCall) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Peels a chain like `$this->hasMany(X::class)->orderBy('position')` back to the call that
     * made it a relation. Reading only the outermost call would see `orderBy` and conclude the
     * method was not a relation at all.
     */
    private function relationCall(Node\Expr $expression): ?MethodCall
    {
        while ($expression instanceof MethodCall) {
            if ($expression->var instanceof Node\Expr\Variable
                && $expression->var->name === 'this'
                && $expression->name instanceof Node\Identifier
                && in_array(strtolower($expression->name->toString()), self::RELATIONS, true)) {
                return $expression;
            }

            $expression = $expression->var;
        }

        return null;
    }

    /**
     * A relation built out of another relation: `return $this->children()->with(...)`.
     *
     * No relation factory is called anywhere in the body, so nothing here can name the target
     * class — but the method says it returns a relation type, and that is enough to know the
     * attribute exists. Recording it as declined says both halves: the relation is real, and
     * what it points at is not readable from this method.
     *
     * Left out entirely, these read as annotations that nothing backs, which is a much worse
     * answer than an honest gap.
     */
    private function recordDerivedRelation(LoadedClass $class, Node\Stmt\ClassMethod $method, string $name): void
    {
        $returns = $method->getReturnType();

        if (! $returns instanceof Node\Name || isset($this->claimed['relation:'.strtolower($name)])) {
            return;
        }

        $kind = Analyser::resolvedName($returns);

        if (! str_starts_with($kind, self::RELATION_NAMESPACE)) {
            return;
        }

        $this->claimed['relation:'.strtolower($name)] = true;

        $this->declined[] = new ModelFact(
            'relation',
            $name,
            lcfirst(substr($kind, strlen(self::RELATION_NAMESPACE))),
            Confidence::Inferred,
            $class->path,
            $method->getStartLine(),
            'derived from another relation, so the related class is not named in this method',
            $class->fqn,
        );
    }

    private function recordRelation(LoadedClass $class, string $name, MethodCall $call, int $line): void
    {
        $kind = $call->name instanceof Node\Identifier ? $call->name->toString() : 'relation';
        $arguments = $call->getArgs();

        if (strcasecmp($kind, 'morphTo') === 0) {
            $this->declined[] = new ModelFact(
                'relation',
                $name,
                $kind,
                Confidence::Textual,
                $class->path,
                $line,
                'polymorphic — what it points at is decided by the morph map, not by this file',
                $class->fqn,
            );

            return;
        }

        $target = $arguments === [] ? null : LiteralValue::className($arguments[0]->value, $class->fqn);

        if ($target === null) {
            $this->declined[] = new ModelFact('relation', $name, $kind, Confidence::Textual, $class->path, $line, 'the related class is not named literally', $class->fqn);

            return;
        }

        // `X::class` is checkable and a class-string is not, so they are not equally known.
        $literal = $arguments[0]->value instanceof Node\Expr\ClassConstFetch;

        $this->facts[] = new ModelFact(
            'relation',
            $name,
            $kind.'  '.$target,
            $literal ? Confidence::Resolved : Confidence::Inferred,
            $class->path,
            $line,
            $literal ? null : 'named by a string rather than ::class',
            $class->fqn,
        );
    }

    /**
     * Global scopes, wherever they are registered from — a `booted()` method on the model, or a
     * `bootX()` method on a trait it uses. The second is the common spelling for tenant
     * scoping, and it is invisible in the model's own file.
     */
    private function readScopeCalls(LoadedClass $class): void
    {
        foreach (new NodeFinder()->findInstanceOf([$class->node], Node\Expr\StaticCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'addGlobalScope') !== 0) {
                continue;
            }

            $this->recordScope($class, $call->getArgs(), $call->getStartLine());
        }

        foreach (new NodeFinder()->findInstanceOf([$class->node], MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier
                && strcasecmp($call->name->toString(), 'addGlobalScope') === 0
                && $call->var instanceof Node\Expr\Variable
                && $call->var->name === 'this') {
                $this->recordScope($class, $call->getArgs(), $call->getStartLine());
            }
        }
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function recordScope(LoadedClass $class, array $arguments, int $line): void
    {
        $first = $arguments[0]->value ?? null;

        if ($first === null) {
            return;
        }

        $target = $first instanceof Node\Expr\New_ && $first->class instanceof Node\Name
            ? Analyser::resolvedName($first->class)
            : LiteralValue::className($first);

        if ($target === null) {
            // `addGlobalScope('order', fn (Builder $q) => …)` — a named closure. The name is
            // worth reporting even though the closure is not readable: a scope silently absent
            // from the answer is how a cross-tenant audit comes back clean.
            $named = LiteralValue::scalar($first);

            $this->declined[] = new ModelFact(
                'scope',
                is_string($named) && $named !== '' ? $named : 'addGlobalScope',
                null,
                Confidence::Textual,
                $class->path,
                $line,
                'applied by a closure, so what it does is not readable from here',
                $class->fqn,
            );

            return;
        }

        $this->facts[] = new ModelFact('scope', $target, null, Confidence::Resolved, $class->path, $line, 'registered by '.$class->fqn, $class->fqn);
    }

    private function readScopedByAttribute(LoadedClass $class): void
    {
        foreach ($class->node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strcasecmp($attribute->name->getLast(), 'ScopedBy') !== 0) {
                    continue;
                }

                foreach ($attribute->args as $argument) {
                    foreach ($this->attributeTargets($argument->value) as $target) {
                        $this->facts[] = new ModelFact('scope', $target, null, Confidence::Resolved, $class->path, $attribute->getStartLine(), '#[ScopedBy]', $class->fqn);
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function attributeTargets(Node\Expr $expression): array
    {
        $single = LiteralValue::className($expression);

        if ($single !== null) {
            return [$single];
        }

        if (! $expression instanceof Node\Expr\Array_) {
            return [];
        }

        $targets = [];

        foreach ($expression->items as $item) {
            $target = LiteralValue::className($item->value);

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Traits come from the graph rather than from the file, because a trait used by a parent is
     * as present on the model as one used directly — and `SoftDeletes` in particular changes
     * every query the model makes without appearing in its body at all.
     */
    /**
     * The attributes a model exposes that are declared as methods.
     *
     * Both spellings: `getDisplayNameAttribute()` and, since the framework grew a second way to
     * say it, `displayName(): Attribute`. The newer one is recognised by its return type and
     * never by its name alone — without the type every ordinary method on the model would look
     * like an attribute.
     *
     * There were 304 of these in one measured application and not one of them appeared in this
     * command's output before, which made `model` and `members` disagree about the same class.
     */
    private function readAccessors(LoadedClass $class): void
    {
        foreach ($class->node->getMethods() as $method) {
            $name = $method->name->toString();
            $returns = $method->getReturnType();
            $isAttribute = $returns instanceof Node\Name
                && Analyser::resolvedName($returns) === EloquentConventions::ATTRIBUTE;

            // Two unrelated mechanisms, and the reason has to say which.
            // `getDisplayNameAttribute()` is found by its name; `shortCode(): Attribute` is found
            // by its return type and follows no naming convention at all. One sentence covering
            // both teaches a reader the wrong rule — that a method named `shortCode` cannot be an
            // accessor, or that renaming it to `getShortCodeAttribute()` would change nothing.
            [$attribute, $reason] = match (true) {
                preg_match('/^get(.+)Attribute$/', $name, $matches) === 1 => [
                    $this->snake($matches[1]),
                    'read as an attribute by the framework\'s naming convention',
                ],
                $isAttribute => [
                    $this->snake($name),
                    'read as an attribute — declared with an Attribute return type, not by its name',
                ],
                default => [null, ''],
            };

            if ($attribute === null || isset($this->claimed['accessor:'.$attribute])) {
                continue;
            }

            $this->claimed['accessor:'.$attribute] = true;

            $this->facts[] = new ModelFact(
                'accessor',
                $attribute,
                $name.'()',
                Confidence::Inferred,
                $class->path,
                $method->getStartLine(),
                $reason,
                $class->fqn,
            );
        }
    }

    /**
     * The attributes the class *says* it has, in `@property` and its read-only and write-only
     * variants.
     *
     * These are the commonest form magic takes by a wide margin — 4,155 of them against ten
     * `__get` bodies in one measured application — and almost all of them are generated. So they
     * are recorded as claims, with the tag and the class that carried it, and never mixed in
     * with what the body actually says.
     */
    private function readAnnotations(LoadedClass $class): void
    {
        $docblock = $class->node->getDocComment()?->getText() ?? '';

        foreach ($this->docblocks->members($docblock)['properties'] as $name => $claim) {
            if (isset($this->claimed['annotated:'.$name])) {
                continue;
            }

            $this->claimed['annotated:'.$name] = true;

            $this->facts[] = new ModelFact(
                'annotated',
                $name,
                $claim['type'],
                Confidence::Inferred,
                $class->path,
                $class->node->getStartLine(),
                sprintf('%s on %s — a claim about the model, checked by nothing', $claim['tag'], $class->fqn),
                $class->fqn,
            );
        }
    }

    /**
     * What the annotations claim, against what the rest of the source accounts for.
     *
     * After the passes above there are four independent accounts of a model's attributes: the
     * migration history, `$casts`, the accessors, and the `@property` block. Generated
     * annotation blocks go stale silently — a column is dropped, the block is not regenerated,
     * and the claim outlives the thing it described. Nothing else in the toolchain will say so,
     * and the two halves needed to notice are already in hand here.
     */
    /**
     * The parents and traits in a model's chain that could not be read.
     *
     * A relation declared in a package trait is invisible here, and an annotation naming it
     * would look like drift. Which of those two it is changes what a reader should do, so the
     * answer has to be able to say.
     *
     * @param  list<LoadedClass>  $chain
     * @return list<string>
     */
    private function unreadableAncestry(array $chain): array
    {
        $missing = [];

        foreach ($chain as $class) {
            foreach ($this->source->parentsOf($class) as $parent) {
                if ($parent !== LaravelProfile::MODEL && ! $this->source->load($parent) instanceof LoadedClass) {
                    $missing[$parent] = true;
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * @param  list<string>  $unreadable
     */
    private function reconcile(LoadedClass $own, ?callable $schema, array $unreadable): void
    {
        $claimed = [];
        $accounted = [];

        foreach ($this->facts as $fact) {
            match ($fact->group) {
                'annotated' => $claimed[$fact->name] = true,
                'accessor', 'relation', 'casts', 'fillable', 'guarded', 'hidden', 'visible', 'appends', 'dates' => $accounted[$this->normalise($fact->name)] = true,
                default => null,
            };
        }

        if ($claimed === []) {
            return;
        }

        foreach ($own->node->getProperties() as $property) {
            foreach ($property->props as $item) {
                $accounted[$this->normalise($item->name->toString())] = true;
            }
        }

        // A relation the reader declined is still a relation. `morphTo` names no target class,
        // which is why it is declined — but the attribute is there, and counting it as missing
        // would report the commonest polymorphic shape in Laravel as a stale annotation.
        foreach ($this->declined as $fact) {
            if ($fact->group === 'relation') {
                $accounted[$this->normalise($fact->name)] = true;
            }
        }

        $table = $this->tableName();
        $tables = $schema === null ? [] : $schema();
        $found = $table === null ? null : ($tables[$table] ?? null);

        // Without a migration history there is nothing to check the claims against, and a report
        // that every annotated property is unaccounted for would be noise rather than a finding.
        //
        // The same goes for a table whose history the replay could not read to the end: its
        // column list is a best reading rather than a fact, and a claim cannot be contradicted
        // by a list that is knowingly incomplete.
        if ($found === null || $found->columns === [] || $found->unread !== []) {
            return;
        }

        $columns = $found->columns;

        foreach ($columns as $column) {
            $accounted[$this->normalise($column->name)] = true;
        }

        $unaccounted = array_values(array_filter(
            array_keys($claimed),
            fn (string $name): bool => ! isset($accounted[$this->normalise($name)]) && ! $this->isRelationAggregate($name),
        ));

        if ($unaccounted === []) {
            return;
        }

        $this->notes[] = sprintf(
            '%d of %d annotated properties are accounted for by no column, cast, accessor or relation — %s%s%s',
            count($unaccounted),
            count($claimed),
            implode(', ', array_slice($unaccounted, 0, 8)),
            count($unaccounted) > 8 ? sprintf(' and %d more', count($unaccounted) - 8) : '',
            $unreadable === []
                ? '; the model was read to the end of its ancestry, so these are claims nothing backs'
                : sprintf('; %s could not be read, so a relation or accessor declared there would look the same', implode(', ', array_slice($unreadable, 0, 3))),
        );
    }

    /**
     * Whether an attribute is the aggregate of a relation rather than a thing in its own right.
     *
     * `withCount('lines')` puts `lines_count` on the model, and the generated annotation block
     * records it. Nothing in the source declares it and nothing ever will, so without this every
     * model using an aggregate would be reported as carrying a stale claim — which is how a
     * report that is right most of the time gets switched off.
     */
    private function isRelationAggregate(string $name): bool
    {
        // Declined relations count. A `morphTo` names no target class and is declined for that
        // reason, but the aggregate it puts on the model is there either way.
        foreach ([...$this->facts, ...$this->declined] as $fact) {
            if ($fact->group !== 'relation') {
                continue;
            }

            $relation = self::snake($fact->name);

            foreach (['_count', '_exists', '_sum_', '_avg_', '_min_', '_max_'] as $aggregate) {
                if (str_starts_with($this->normalise($name), $this->normalise($relation.$aggregate))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function tableName(): ?string
    {
        foreach ($this->facts as $fact) {
            if ($fact->group === 'attribute' && $fact->name === 'table') {
                return $fact->value;
            }
        }

        return null;
    }

    /**
     * The form two spellings of the same attribute share.
     *
     * `getDisplayNameAttribute()` answers to `displayName` and to `display_name`,
     * because the framework studlies whatever key it is given. Comparing literal spellings
     * reported a declared accessor as a stale annotation, which is exactly the false positive
     * that gets a report like this switched off.
     */
    private function normalise(string $name): string
    {
        return strtolower(str_replace('_', '', $name));
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function readTraits(LoadedClass $own): void
    {
        foreach ($this->graph->ancestorsOf($own->fqn) as $ancestor => $origin) {
            if ($origin !== MemberOrigin::Trait_) {
                continue;
            }

            $record = $this->graph->classLike($ancestor);

            // A trait reached through the graph may not itself be indexed — a framework trait
            // usually is not — so the model's own file stands in as the address.
            $path = $record instanceof SymbolRecord ? $record->path : $own->path;
            $line = $record instanceof SymbolRecord ? $record->line : null;

            $this->facts[] = new ModelFact(
                'trait',
                $ancestor,
                null,
                Confidence::Resolved,
                $path,
                $line,
                null,
                $own->fqn,
            );

            if (str_ends_with($ancestor, '\\SoftDeletes')) {
                $this->facts[] = new ModelFact(
                    'scope',
                    'Illuminate\Database\Eloquent\SoftDeletingScope',
                    null,
                    Confidence::Inferred,
                    $path,
                    $line,
                    'the SoftDeletes trait registers this scope; it appears in no body here',
                    $own->fqn,
                );
            }
        }
    }

    /**
     * @param  ?callable(): array<string, Table>  $schema
     */
    private function resolveTable(LoadedClass $own, ?callable $schema): void
    {
        foreach ($this->facts as $fact) {
            if ($fact->group === 'attribute' && $fact->name === 'table') {
                return;
            }
        }

        $derived = TableName::forClass($own->fqn);
        $tables = $schema === null ? [] : $schema();
        $known = isset($tables[$derived]);

        // Derived from a convention, then checked against what the migrations actually create.
        // "The convention says `people` and a table called `people` exists" is a much stronger
        // claim than the convention alone, and the two must not read the same.
        $this->facts[] = new ModelFact(
            'attribute',
            'table',
            $derived,
            Confidence::Inferred,
            $own->path,
            $own->node->getStartLine(),
            $tables === []
                ? 'derived from the class name; no migration history was read to check it against'
                : ($known
                    ? 'derived from the class name, and the migrations do create this table'
                    : 'derived from the class name, but no migration creates a table of this name'),
            $own->fqn,
        );
    }
}
