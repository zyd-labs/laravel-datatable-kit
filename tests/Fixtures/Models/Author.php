<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Author extends Model
{
    use SoftDeletes;

    /** @var int|null Active tenant scope for tests (null disables the scope). */
    public static ?int $tenantScopeId = 1;

    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (static::$tenantScopeId === null) {
                return;
            }

            $builder->where('authors.tenant_id', static::$tenantScopeId);
        });
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
