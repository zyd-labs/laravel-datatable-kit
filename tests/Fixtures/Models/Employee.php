<?php

declare(strict_types=1);

namespace ZydLabs\LaravelDataTableKit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    /** @var int|null Active tenant scope for tests (null disables the scope). */
    public static ?int $tenantScopeId = 1;

    protected $table = 'employees';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (static::$tenantScopeId === null) {
                return;
            }

            $builder->where('employees.tenant_id', static::$tenantScopeId);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function healthDocuments(): HasMany
    {
        return $this->hasMany(HealthDocument::class);
    }
}
