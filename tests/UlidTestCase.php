<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Tests;

/**
 * Runs the suite with Blogify's own tables keyed by ULID.
 *
 * key_type is read by the migrations, so it has to be set before the schema is
 * built — which means a separate TestCase rather than a config change inside a
 * test body.
 */
abstract class UlidTestCase extends TestCase
{
    protected function keyType(): string
    {
        return 'ulid';
    }
}
