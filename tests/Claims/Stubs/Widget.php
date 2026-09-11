<?php

namespace Skills\ClaimChecks\Stubs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Widget extends Model
{
    protected $table = 'widgets';

    protected $guarded = [];

    public function parts(): HasMany
    {
        return $this->hasMany(Widget::class, 'parent_id');
    }
}
