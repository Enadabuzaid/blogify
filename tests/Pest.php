<?php

declare(strict_types=1);

use Enadstack\Blogify\Tests\TestCase;
use Enadstack\Blogify\Tests\UlidTestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// key_type is read by the migrations, so it has to be set before the schema is
// built. That means a distinct base test case, and Pest binds a base case per
// directory — hence the separate folder rather than a flag inside a test body.
uses(UlidTestCase::class)->in('Ulid');
