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

    public function activeAuthor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id')->where('authors.is_active', true);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_id');
    }

    public function activeEditor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_id')->where('authors.is_active', true);
    }

    public function authorWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id')->withTrashed();
    }

    public function authorOnlyTrashed(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id')->onlyTrashed();
    }

    public function editorWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'editor_id')->withTrashed();
    }

    public function legacyAuthor(): BelongsTo
    {
        return $this->belongsTo(LegacyAuthor::class, 'legacy_author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function activeTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->wherePivot('is_active', true);
    }

    public function publishedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->where('tags.is_published', true);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
