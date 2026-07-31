<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for an application's tenant model.
 *
 * ULID-keyed on purpose: real tenant models in the applications this package
 * targets are, and a bigint morph column would silently fail to store the key.
 */
class TestTenant extends Model
{
    use HasUlids;

    protected $table = 'test_tenants';

    protected $guarded = [];
}
