<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Vusys\Exocomp\Analysis\Analyser;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Source\ParseFailure;
use Vusys\Exocomp\Source\SourceFile;

/**
 * Replays the migration directory to reconstruct what each table currently looks like.
 *
 * This answers a question with no other cheap answer: the schema lives in the database, the
 * database is not always reachable, and the migrations are a hundred files deep. Reading them
 * in order is what a person does by hand, and it is exactly the kind of mechanical replay a
 * tool should do instead.
 *
 * Three things it deliberately does not pretend about. Migrations guarded by
 * `Schema::hasColumn()` are replayed as though the guard passed, because whether it did is a
 * property of a database rather than of the source. Raw SQL is not interpreted at all. And a
 * `down()` method is never replayed, because a rollback that happened is not visible from here.
 * Every one of those leaves a mark on the table it touched, so a shape assembled over an
 * unreadable migration is reported as incomplete rather than as fact.
 */
final class SchemaReplay
{
    /**
     * Blueprint calls that create several columns at once. The framework's names for them are
     * short and their expansions are not guessable from the call.
     *
     * @var array<string, list<string>>
     */
    private const array EXPANSIONS = [
        'timestamps' => ['created_at', 'updated_at'],
        'nullabletimestamps' => ['created_at', 'updated_at'],
        'timestampstz' => ['created_at', 'updated_at'],
        'datetimes' => ['created_at', 'updated_at'],
        'remembertoken' => ['remember_token'],
    ];

    /** @var array<string, string> */
    private const array SUFFIXED = [
        'morphs' => 'type,id',
        'nullablemorphs' => 'type,id',
        'uuidmorphs' => 'type,id',
        'nullableuuidmorphs' => 'type,id',
        'ulidmorphs' => 'type,id',
        'nullableulidmorphs' => 'type,id',
        'numericmorphs' => 'type,id',
        'nullablenumericmorphs' => 'type,id',
    ];

    /** @var list<string> */
    private const array SOFT_DELETES = ['softdeletes', 'softdeletestz', 'softdeletesdatetime'];

    /**
     * Helpers whose column name is optional, and what it is when left out.
     *
     * @var array<string, string>
     */
    private const array DEFAULT_NAMES = [
        'uuid' => 'uuid', 'ulid' => 'ulid', 'ipaddress' => 'ip_address', 'macaddress' => 'mac_address',
    ];

    /**
     * Helpers that drop columns they do not name, or name only a prefix of.
     *
     * @var array<string, list<string>>
     */
    private const array DROPS = [
        'dropsoftdeletes' => ['deleted_at'], 'dropsoftdeletestz' => ['deleted_at'],
        'droptimestamps' => ['created_at', 'updated_at'], 'droptimestampstz' => ['created_at', 'updated_at'],
        'dropremembertoken' => ['remember_token'],
    ];

    /** @var array<string, string> the index each drop helper removes */
    private const array INDEX_DROPS = [
        'dropindex' => 'index', 'dropunique' => 'unique', 'dropprimary' => 'primary',
        'dropfulltext' => 'fulltext', 'dropspatialindex' => 'spatialindex',
    ];

    /** @var list<string> */
    private const array INDEXES = ['index', 'unique', 'primary', 'fulltext', 'spatialindex', 'rawindex'];

    /** @var list<string> */
    private const array NOT_COLUMNS = [
        'dropcolumn', 'dropcolumnifexists', 'renamecolumn', 'dropindex', 'dropunique', 'dropprimary',
        'dropforeign', 'dropfulltext', 'dropspatialindex', 'droptimestamps', 'droptimestampstz',
        'dropsoftdeletes', 'dropsoftdeletestz', 'dropmorphs', 'dropremembertoken', 'dropconstrainedforeignid',
        'engine', 'charset', 'collation', 'temporary', 'comment', 'foreign', 'references', 'on',
    ];

    /** @var array<string, Table> */
    private array $tables = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $dumps = [];

    private int $migrationsRead = 0;

    private int $unattributed = 0;

    private int $unreadableNames = 0;

    /**
     * Constants declared by the migration currently being read.
     *
     * `Schema::table(self::TABLE, …)` is an ordinary way to write a migration, and reading the
     * name as "not a literal" would drop the whole migration — silently losing every column it
     * adds. Measured on a large application, one such migration hid a column that the answer
     * then reported as absent.
     *
     * @var array<string, string>
     */
    private array $constants = [];

    public function __construct(private readonly Analyser $analyser = new Analyser) {}

    /**
     * Loads a squashed dump as the state the migrations then build on.
     *
     * Order matters and is not negotiable: the framework loads the dump first and replays only
     * what came after it, so anything that runs the other way round would let a long-since
     * superseded migration overwrite the current shape.
     */
    public function withDump(string $absolute, string $path): self
    {
        foreach (SchemaDump::read($absolute, $path) as $name => $table) {
            $this->tables[$name] = $table;
        }

        $this->dumps[] = $path;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function dumps(): array
    {
        return $this->dumps;
    }

    /**
     * @param  string  $directory  absolute path to the migrations directory
     * @param  callable(string): string  $displayPath
     */
    public function replay(string $directory, callable $displayPath): self
    {
        foreach ($this->migrationFiles($directory) as $absolute) {
            $this->readMigration($absolute, $displayPath($absolute));
        }

        return $this;
    }

    /**
     * @return array<string, Table>
     */
    public function tables(): array
    {
        ksort($this->tables);

        return $this->tables;
    }

    public function table(string $name): ?Table
    {
        return $this->tables[$name] ?? null;
    }

    public function migrationsRead(): int
    {
        return $this->migrationsRead;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        if ($this->unattributed === 0 && $this->unreadableNames === 0) {
            return $this->notes;
        }

        // Collapsed to a count on purpose. These are migrations that change the schema with raw
        // SQL against a table nothing in the file names, so they belong to no table's answer —
        // listing one line each would put a page of irrelevance under every table description.
        $notes = $this->notes;

        if ($this->unreadableNames > 0) {
            $notes[] = sprintf(
                '%d migration statement%s name their table with something this replay could not read, so their changes are missing',
                $this->unreadableNames,
                $this->unreadableNames === 1 ? '' : 's',
            );
        }

        return [...$notes, sprintf(
            '%d migration statement%s run raw SQL against a table this replay could not name',
            $this->unattributed,
            $this->unattributed === 1 ? '' : 's',
        )];
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            $this->notes[] = sprintf('%s is not there, so no migration history could be read', $directory);

            return [];
        }

        $found = glob(rtrim($directory, '/').'/{,*/}*.php', GLOB_BRACE);

        if ($found === false) {
            return [];
        }

        // Filename order is migration order — that is the framework's own rule, and it is why
        // the timestamps are in the names at all.
        sort($found);

        return $found;
    }

    private function readMigration(string $absolute, string $path): void
    {
        try {
            [, $statements] = $this->analyser->analyseWithAst(SourceFile::fromPath($absolute, $path));
        } catch (ParseFailure $failure) {
            $this->notes[] = sprintf('%s:%d could not be parsed, so its changes are missing: %s', $path, $failure->startLine, $failure->getMessage());

            return;
        }

        $this->migrationsRead++;
        $this->constants = $this->constantsIn($statements);
        $up = $this->upMethod($statements);

        if (! $up instanceof Node\Stmt\ClassMethod) {
            return;
        }

        $mentioned = [];

        foreach (new NodeFinder()->findInstanceOf([$up], Node\Expr\StaticCall::class) as $call) {
            $this->applyStaticCall($call, $path);

            $named = $this->tableMentionedBy($call);

            if ($named !== null) {
                $mentioned[$named] = true;
            }
        }

        $this->recordRawSql($up, $path, array_keys($mentioned));
    }

    /**
     * @param  array<int, Node>  $statements
     */
    private function upMethod(array $statements): ?Node\Stmt\ClassMethod
    {
        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\ClassMethod::class) as $method) {
            if (strcasecmp($method->name->toString(), 'up') === 0) {
                return $method;
            }
        }

        return null;
    }

    private function applyStaticCall(Node\Expr\StaticCall $call, string $path): void
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return;
        }

        $class = Analyser::resolvedName($call->class);

        if (! str_ends_with($class, '\\Schema') && $class !== 'Schema') {
            return;
        }

        $method = strtolower($call->name->toString());
        $arguments = Analyser::arguments($call);

        if (in_array($method, ['create', 'table', 'drop', 'dropifexists', 'rename'], true) && $this->tableName($arguments) === null) {
            // The name is computed, so this migration's changes belong to a table that cannot be
            // named from the source. Dropping it silently would report a column as absent.
            $this->unreadableNames++;

            return;
        }

        match ($method) {
            'create' => $this->createTable($arguments, $call, $path),
            'table' => $this->alterTable($arguments, $call, $path),
            'drop', 'dropifexists' => $this->dropTable($arguments),
            'rename' => $this->renameTable($arguments),
            default => null,
        };
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function createTable(array $arguments, Node\Expr\StaticCall $call, string $path): void
    {
        $name = $this->tableName($arguments);

        if ($name === null) {
            return;
        }

        $table = $this->tables[$name] ??= new Table($name);
        $table->createdIn ??= $path;
        $table->createdAtLine = $table->createdAtLine === 0 ? $call->getStartLine() : $table->createdAtLine;

        $this->applyBlueprint($table, $arguments, $path);
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function alterTable(array $arguments, Node\Expr\StaticCall $call, string $path): void
    {
        $name = $this->tableName($arguments);

        if ($name === null) {
            return;
        }

        // A table altered before anything creates it is normal: the create may live in a
        // package's own migrations, or in a directory outside the one being read.
        $table = $this->tables[$name] ??= new Table($name);

        if ($table->createdIn === null) {
            $table->unread[] = [
                'reason' => 'altered here, but nothing in this directory creates it',
                'path' => $path,
                'line' => $call->getStartLine(),
            ];
        }

        $this->applyBlueprint($table, $arguments, $path);
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function dropTable(array $arguments): void
    {
        $name = $this->tableName($arguments);

        if ($name !== null) {
            unset($this->tables[$name]);
        }
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function renameTable(array $arguments): void
    {
        $from = $this->tableName($arguments);
        $to = isset($arguments[1]) ? LiteralValue::scalar($arguments[1]->value) : null;

        if ($from === null || ! is_string($to)) {
            return;
        }

        $table = $this->tables[$from] ?? null;

        if ($table instanceof Table) {
            unset($this->tables[$from]);
            $table->name = $to;
            $this->tables[$to] = $table;
        }
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function tableName(array $arguments): ?string
    {
        if ($arguments === []) {
            return null;
        }

        $expression = $arguments[0]->value;
        $constant = $this->constantValue($expression);

        if ($constant !== null) {
            return $constant;
        }

        $name = LiteralValue::scalar($expression);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The value of a constant the migration declares, for the `self::TABLE` spelling.
     */
    private function constantValue(Node\Expr $expression): ?string
    {
        if (! $expression instanceof Node\Expr\ClassConstFetch || ! $expression->name instanceof Node\Identifier) {
            return null;
        }

        return $this->constants[$expression->name->toString()] ?? null;
    }

    /**
     * @param  array<int, Node>  $statements
     * @return array<string, string>
     */
    private function constantsIn(array $statements): array
    {
        $constants = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\ClassConst::class) as $declaration) {
            foreach ($declaration->consts as $const) {
                $value = LiteralValue::scalar($const->value);

                if (is_string($value)) {
                    $constants[$const->name->toString()] = $value;
                }
            }
        }

        return $constants;
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function applyBlueprint(Table $table, array $arguments, string $path): void
    {
        $closure = $arguments[1]->value ?? null;

        if (! $closure instanceof Node\Expr\Closure && ! $closure instanceof Node\Expr\ArrowFunction) {
            return;
        }

        $parameter = $closure->getParams()[0]->var ?? null;
        $blueprint = $parameter instanceof Node\Expr\Variable && is_string($parameter->name) ? $parameter->name : null;

        if ($blueprint === null) {
            return;
        }

        $table->migrations[] = $path;

        // Only the outermost call of each chain: `$table->string('a')->nullable()` is one
        // column, and walking every MethodCall would count the modifiers as columns too.
        foreach ($this->outermostCalls($closure, $blueprint) as $chain) {
            $this->applyChain($table, $chain, $path);
        }
    }

    /**
     * @return list<Node\Expr\MethodCall>
     */
    private function outermostCalls(Node\FunctionLike $closure, string $blueprint): array
    {
        $calls = new NodeFinder()->findInstanceOf([$closure], Node\Expr\MethodCall::class);
        $inner = [];

        foreach ($calls as $call) {
            if ($call->var instanceof Node\Expr\MethodCall) {
                $inner[spl_object_id($call->var)] = true;
            }
        }

        $outermost = [];

        foreach ($calls as $call) {
            if (! isset($inner[spl_object_id($call)]) && $this->rootedAt($call, $blueprint)) {
                $outermost[] = $call;
            }
        }

        return $outermost;
    }

    private function rootedAt(Node\Expr\MethodCall $call, string $blueprint): bool
    {
        $receiver = $call->var;

        while ($receiver instanceof Node\Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Node\Expr\Variable && $receiver->name === $blueprint;
    }

    private function applyChain(Table $table, Node\Expr\MethodCall $chain, string $path): void
    {
        $links = [];
        $current = $chain;

        while ($current instanceof Node\Expr\MethodCall) {
            array_unshift($links, $current);
            $current = $current->var;
        }

        $base = $links[0];

        if (! $base->name instanceof Node\Identifier) {
            return;
        }

        $spelling = $base->name->toString();
        $method = strtolower($spelling);
        $modifiers = [];

        foreach (array_slice($links, 1) as $link) {
            if ($link->name instanceof Node\Identifier) {
                $modifiers[] = $this->renderModifier($link);
            }
        }

        $this->applyBlueprintMethod($table, $method, $spelling, $base, $modifiers, $path);
    }

    /**
     * @param  string  $spelling  the method as the migration wrote it. Dispatch lowercases, but
     *                            the column's type is what a reader would type into a migration,
     *                            and `unsignedinteger` is not a method the framework has.
     * @param  list<string>  $modifiers
     */
    private function applyBlueprintMethod(Table $table, string $method, string $spelling, Node\Expr\MethodCall $base, array $modifiers, string $path): void
    {
        $arguments = Analyser::arguments($base);
        $line = $base->getStartLine();

        // The columns are the first argument; the second, when there is one, is the index's name.
        if (in_array($method, self::INDEXES, true)) {
            $index = ['kind' => $method, 'columns' => $this->nameList(array_slice($arguments, 0, 1)), 'path' => $path, 'line' => $line];
            $name = isset($arguments[1]) ? LiteralValue::scalar($arguments[1]->value) : null;
            $table->indexes[] = is_string($name) ? $index + ['name' => $name] : $index;

            return;
        }

        if (isset(self::INDEX_DROPS[$method])) {
            $first = $arguments[0]->value ?? null;
            $name = $first instanceof Node\Expr && ! $first instanceof Node\Expr\Array_ ? LiteralValue::scalar($first) : null;
            $table->dropIndex(is_string($name) ? $name : null, $this->nameList(array_slice($arguments, 0, 1)), self::INDEX_DROPS[$method]);

            return;
        }

        if (isset(self::DROPS[$method])) {
            foreach ($this->firstName($arguments) === null ? self::DROPS[$method] : [$this->firstName($arguments)] as $name) {
                $table->drop($name);
            }

            return;
        }

        // `dropMorphs('owner')`: both columns and the index on them.
        if ($method === 'dropmorphs') {
            $prefix = $this->firstName($arguments);

            if ($prefix !== null) {
                $table->drop($prefix.'_type');
                $table->drop($prefix.'_id');
                $table->dropIndex(null, [$prefix.'_type', $prefix.'_id'], 'index');
            }

            return;
        }

        if ($method === 'dropconstrainedforeignid' || $method === 'dropconstrainedforeignidfor') {
            $name = $method === 'dropconstrainedforeignid' ? $this->firstName($arguments) : $this->foreignIdForName($arguments);

            if ($name !== null) {
                $table->drop($name);
            }

            return;
        }

        if (isset(self::DEFAULT_NAMES[$method])) {
            $name = $this->firstName($arguments) ?? self::DEFAULT_NAMES[$method];
            $table->add(new Column($name, $spelling, $this->extraArguments($arguments), $modifiers, $path, $line));
            $this->addInlineIndexes($table, $name, $method, $modifiers, $path, $line);

            return;
        }

        // `foreignIdFor(Invoice::class)` is `invoice_id`, the model's foreign key; a second
        // argument names the column instead.
        if ($method === 'foreignidfor') {
            $name = $this->foreignIdForName($arguments);

            if ($name !== null) {
                $table->add(new Column($name, $spelling, [], $modifiers, $path, $line));
                $this->addInlineIndexes($table, $name, $method, $modifiers, $path, $line);
            }

            return;
        }

        if ($method === 'dropcolumn' || $method === 'dropcolumnifexists') {
            foreach ($this->nameList($arguments) as $name) {
                $table->drop($name);
            }

            return;
        }

        if ($method === 'renamecolumn') {
            $names = $this->nameList($arguments);

            if (count($names) === 2) {
                $table->rename($names[0], $names[1]);
            }

            return;
        }

        if (isset(self::EXPANSIONS[$method])) {
            foreach (self::EXPANSIONS[$method] as $name) {
                $table->add(new Column($name, $spelling, [], $modifiers, $path, $line));
            }

            return;
        }

        if (in_array($method, self::SOFT_DELETES, true)) {
            $name = $this->firstName($arguments) ?? 'deleted_at';
            $table->add(new Column($name, $spelling, [], $modifiers, $path, $line));

            return;
        }

        if (isset(self::SUFFIXED[$method])) {
            $prefix = $this->firstName($arguments);

            if ($prefix !== null) {
                foreach (explode(',', self::SUFFIXED[$method]) as $suffix) {
                    $table->add(new Column($prefix.'_'.$suffix, $spelling, [], $modifiers, $path, $line));
                }

                // The framework indexes the pair.
                $table->indexes[] = ['kind' => 'index', 'columns' => [$prefix.'_type', $prefix.'_id'], 'path' => $path, 'line' => $line];
            }

            return;
        }

        if ($method === 'id') {
            $name = $this->firstName($arguments) ?? 'id';
            $table->add(new Column($name, 'id', [], $modifiers, $path, $line));
            $this->addInlineIndexes($table, $name, 'id', $modifiers, $path, $line);

            return;
        }

        if (in_array($method, self::NOT_COLUMNS, true)) {
            return;
        }

        $name = $this->firstName($arguments);

        if ($name !== null) {
            $table->add(new Column($name, $spelling, $this->extraArguments($arguments), $modifiers, $path, $line));
            $this->addInlineIndexes($table, $name, $method, $modifiers, $path, $line);
        }
    }

    /**
     * The indexes a column definition creates on its own: `->unique()` and its siblings, and the
     * primary key that `id()` and the `*increments` types carry. Left out, a table whose only
     * indexes were written inline reported none, beside columns marked `unique`.
     *
     * A foreign key is not counted: it is a constraint, and only some drivers index it.
     *
     * @param  list<string>  $modifiers
     */
    private function addInlineIndexes(Table $table, string $column, string $method, array $modifiers, string $path, int $line): void
    {
        if ($method === 'id' || str_ends_with($method, 'increments')) {
            $table->indexes[] = ['kind' => 'primary', 'columns' => [$column], 'path' => $path, 'line' => $line];
        }

        foreach ($modifiers as $modifier) {
            $kind = strtolower(explode('(', $modifier, 2)[0]);

            if (in_array($kind, ['unique', 'index', 'primary', 'fulltext', 'spatialindex'], true)
                && ($kind !== 'primary' || ! str_ends_with($method, 'increments'))) {
                $table->indexes[] = ['kind' => $kind, 'columns' => [$column], 'path' => $path, 'line' => $line];
            }
        }
    }

    /**
     * The column `foreignIdFor()` makes: the second argument, or the model's snake-cased short
     * name and `_id`, which is what `Model::getForeignKey()` returns by default.
     *
     * @param  array<int, Node\Arg>  $arguments
     */
    private function foreignIdForName(array $arguments): ?string
    {
        $named = isset($arguments[1]) ? LiteralValue::scalar($arguments[1]->value) : null;

        if (is_string($named) && $named !== '') {
            return $named;
        }

        $class = isset($arguments[0]) ? LiteralValue::className($arguments[0]->value) : null;

        if ($class === null) {
            return null;
        }

        $short = (string) array_slice(explode('\\', $class), -1)[0];

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)).'_id';
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     */
    private function firstName(array $arguments): ?string
    {
        if ($arguments === []) {
            return null;
        }

        $value = LiteralValue::scalar($arguments[0]->value);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Column names as written, whether given one per argument or as a single array.
     *
     * @param  array<int, Node\Arg>  $arguments
     * @return list<string>
     */
    private function nameList(array $arguments): array
    {
        $names = [];

        foreach ($arguments as $argument) {
            if ($argument->value instanceof Node\Expr\Array_) {
                foreach (LiteralValue::items($argument->value) as $item) {
                    if (is_string($item['value'])) {
                        $names[] = $item['value'];
                    }
                }

                continue;
            }

            $value = LiteralValue::scalar($argument->value);

            if (is_string($value)) {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, Node\Arg>  $arguments
     * @return list<string>
     */
    private function extraArguments(array $arguments): array
    {
        $rendered = [];

        foreach (array_slice($arguments, 1) as $argument) {
            $value = LiteralValue::scalar($argument->value);

            if ($value !== null) {
                $rendered[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $rendered;
    }

    private function renderModifier(Node\Expr\MethodCall $link): string
    {
        $name = $link->name instanceof Node\Identifier ? $link->name->toString() : '?';
        $arguments = [];

        foreach (Analyser::arguments($link) as $argument) {
            $value = LiteralValue::scalar($argument->value);
            $arguments[] = match (true) {
                $value === null => '…',
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value) => "'".$value."'",
                default => (string) $value,
            };
        }

        return $arguments === [] ? $name : sprintf('%s(%s)', $name, implode(', ', $arguments));
    }

    /**
     * Every table this migration names, whichever `Schema::` method named it — including the
     * `hasTable` and `hasColumn` guards, which are how a migration that then runs raw SQL
     * usually says what it is about to change.
     */
    private function tableMentionedBy(Node\Expr\StaticCall $call): ?string
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $class = Analyser::resolvedName($call->class);

        if (! str_ends_with($class, '\\Schema') && $class !== 'Schema') {
            return null;
        }

        return in_array(strtolower($call->name->toString()), ['create', 'table', 'hastable', 'hascolumn', 'hasindex', 'drop', 'dropifexists', 'rename'], true)
            ? $this->tableName(Analyser::arguments($call))
            : null;
    }

    /**
     * Raw SQL is the honest limit of a static replay: the reader can see that the schema was
     * changed here and cannot see how. It is recorded against every table the migration names,
     * so the incompleteness surfaces when that table is asked about rather than on every answer
     * the command ever gives.
     *
     * @param  list<string>  $mentioned
     */
    private function recordRawSql(Node\Stmt\ClassMethod $up, string $path, array $mentioned): void
    {
        foreach (new NodeFinder()->findInstanceOf([$up], Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }

            $class = Analyser::resolvedName($call->class);

            if ((! str_ends_with($class, '\\DB') && $class !== 'DB') || ! in_array(strtolower($call->name->toString()), ['statement', 'unprepared'], true)) {
                continue;
            }

            $entry = ['reason' => 'this migration also runs raw SQL, which was not interpreted', 'path' => $path, 'line' => $call->getStartLine()];
            $attributed = false;

            foreach ($mentioned as $name) {
                $table = $this->tables[$name] ?? null;

                if ($table instanceof Table) {
                    $table->unread[] = $entry;
                    $attributed = true;
                }
            }

            if (! $attributed) {
                $this->unattributed++;
            }
        }
    }
}
