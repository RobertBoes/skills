<?php

namespace Skills\ClaimChecks\Stubs;

/** Extends nothing, to prove a base controller class is not required. */
class PlainInvokableController
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
