<?php

declare(strict_types=1);

namespace Vusys\Exocomp\Framework\Laravel;

use PhpParser\Node;
use Vusys\Exocomp\Analysis\LiteralValue;
use Vusys\Exocomp\Index\SymbolGraph;
use Vusys\Exocomp\Index\SymbolRecord;
use Vusys\Exocomp\Project\ClassSource;
use Vusys\Exocomp\Project\LoadedClass;

/**
 * The namespace a string-named controller is relative to.
 *
 * `Route::get('/x', 'OrderController')` names a class that does not exist under that name. The
 * framework resolves it against a root namespace declared on the application's own route
 * provider, and without that root the name is unusable — copy it into any other command and
 * nothing is found. In one measured corpus that is most of the route table: the controllers
 * are written as strings, under group namespaces, and the fully-qualified name appears
 * nowhere in the route files at all.
 *
 * Read from the provider rather than assumed, because it is a per-application decision and
 * recent framework versions default it to nothing. Assuming the historical default would
 * silently qualify every controller in a modern application with a namespace it does not have.
 */
final readonly class RouteNamespace
{
    private const string PROVIDER = 'Illuminate\Foundation\Support\Providers\RouteServiceProvider';

    public function __construct(
        private SymbolGraph $graph,
        private ClassSource $source,
    ) {}

    public function root(): ?string
    {
        foreach ($this->graph->subclasses(self::PROVIDER, true) as $record) {
            $declared = $this->declaredOn($record);

            if ($declared !== null) {
                return $declared;
            }
        }

        return null;
    }

    private function declaredOn(SymbolRecord $record): ?string
    {
        $loaded = $this->source->load($record->fqn);

        if (! $loaded instanceof LoadedClass) {
            return null;
        }

        foreach ($loaded->node->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'namespace' || ! $item->default instanceof Node\Expr) {
                    continue;
                }

                $value = LiteralValue::scalar($item->default);

                if (is_string($value) && trim($value, '\\') !== '') {
                    return trim($value, '\\');
                }
            }
        }

        return null;
    }
}
