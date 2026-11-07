<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LegacyAuthor extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'removed_at';

    protected $table = 'legacy_authors';

    public $timestamps = false;

    protected $guarded = [];
}
