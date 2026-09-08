<?php

/*
 * Composer's installed autoload map, as it looks in a real checkout. It is what makes the
 * framework's own classes findable without indexing them: the tool reads this file, places
 * `Illuminate\Database\Eloquent\Model` exactly, and parses that one file.
 *
 * The directory it points at is deliberately outside the project's own autoload roots, so a
 * class in it is reachable through the map and absent from the index — which is the shape every
 * real vendor class has, and the shape that made `model` answer "not an Eloquent model" for
 * every model extending a framework base class.
 */

return [
    'Illuminate\\' => [__DIR__.'/../illuminate/src'],
];
