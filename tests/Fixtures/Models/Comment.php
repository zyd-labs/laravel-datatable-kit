<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $table = 'comments';

    public $timestamps = false;

    protected $guarded = [];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
