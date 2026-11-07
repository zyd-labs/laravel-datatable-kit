<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Post extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function authorWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id')->withTrashed();
    }

    public function authorOnlyTrashed(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id')->onlyTrashed();
    }

    public function legacyAuthor(): BelongsTo
    {
        return $this->belongsTo(LegacyAuthor::class, 'legacy_author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
