<?php

declare(strict_types=1);

use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Models\Post;
use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Model;

/*
 * Database-per-tenant.
 *
 * The connection has already isolated the rows, so the global scope would only
 * add a redundant predicate to every query. Owner columns are still stamped,
 * which is what keeps platform content distinguishable from tenant content
 * inside a single tenant database.
 */

beforeEach(function () {
    config()->set('blogify.tenancy.mode', 'isolated');
});

it('does not scope queries', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    Post::query()->create(['type' => 'post'])->assignOwner($a)->save();
    Post::query()->create(['type' => 'post'])->assignOwner($b)->save();

    expect(Post::query()->count())->toBe(2);
});

it('still stamps the owner', function () {
    $tenant = $this->makeTenant('Clinic A');

    $this->app->instance(
        OwnerResolver::class,
        new class($tenant) implements OwnerResolver
        {
            public function __construct(private readonly object $tenant) {}

            public function resolve(): ?Model
            {
                return $this->tenant;
            }

            public function hasOwner(): bool
            {
                return true;
            }
        }
    );

    $post = Post::query()->create(['type' => 'post']);

    expect($post->owner_key)->toBe(OwnerKey::for($tenant));
});

it('can still separate platform content from tenant content', function () {
    $tenant = $this->makeTenant('Clinic A');

    Post::query()->create(['type' => 'post']);
    Post::query()->create(['type' => 'post'])->assignOwner($tenant)->save();

    expect(Post::query()->platform()->count())->toBe(1)
        ->and(Post::query()->ownedByAnyone()->count())->toBe(1);
});
