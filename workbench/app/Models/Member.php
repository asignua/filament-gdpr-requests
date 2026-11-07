<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'members';

    protected $keyType = 'string';

    protected $guarded = [];
}
