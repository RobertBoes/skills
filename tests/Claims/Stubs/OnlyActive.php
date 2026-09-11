<?php

namespace Skills\ClaimChecks\Stubs;

use Illuminate\Contracts\Database\Query\Builder;

/** A tappable scope in the shape `eloquent-queries` pins. */
class OnlyActive
{
    public function __invoke(Builder $builder): void
    {
        $builder->whereNull('deactivated_at');
    }
}
