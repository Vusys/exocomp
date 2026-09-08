<?php

namespace Illuminate\Foundation\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * The intermediate hop. A model extending this is two classes away from `Model`, and both of
 * them live outside the indexed source roots.
 */
class User extends Model {}
