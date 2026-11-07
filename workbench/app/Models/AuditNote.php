<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditNote extends Model
{
    public $timestamps = false;

    protected $table = 'audit_notes';

    protected $guarded = [];
}
