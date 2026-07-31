<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Tests\Fixtures;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Stands in for an application's user model.
 *
 * Bigint-keyed, unlike TestTenant. The pairing is the point: a post owned by a
 * ULID-keyed tenant and a post owned by a bigint-keyed user have to coexist in
 * one table, which is why every morph key column is a string.
 *
 * Authenticatable so AuthOwnerResolver can be exercised through actingAs().
 */
class TestUser extends Authenticatable
{
    protected $table = 'test_users';

    protected $guarded = [];

    /**
     * @return BelongsTo<TestTenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TestTenant::class, 'test_tenant_id');
    }
}
