<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberNote extends Model
{
    public $timestamps = false;

    protected $table = 'member_notes';

    protected $guarded = [];
}
