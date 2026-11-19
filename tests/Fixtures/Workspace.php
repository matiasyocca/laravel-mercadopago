<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model without the Billable trait.
 */
class Workspace extends Model
{
    protected $table = 'teams';

    protected $guarded = [];
}
