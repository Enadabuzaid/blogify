<?php

declare(strict_types=1);

use Enadstack\Blogify\Blogify;
use Enadstack\Blogify\Contracts\OwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\AuthOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\CallbackOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\ContainerOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\NullOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\SpatieOwnerResolver;
use Enadstack\Blogify\Resolvers\Owners\StanclOwnerResolver;

it('binds the resolver named in config', function () {
    config()->set('blogify.tenancy.resolver', ContainerOwnerResolver::class);

    // Rebind so the singleton is rebuilt against the new config value.
    $this->app->forgetInstance(OwnerResolver::class);

    expect(app(OwnerResolver::class))->toBeInstanceOf(ContainerOwnerResolver::class);
});

describe('NullOwnerResolver', function () {
    it('always reports platform-level', function () {
        $resolver = new NullOwnerResolver;

        expect($resolver->resolve())->toBeNull()
            ->and($resolver->hasOwner())->toBeFalse();
    });
});

describe('ContainerOwnerResolver', function () {
    it('reads the configured container binding', function () {
        $tenant = $this->makeTenant('Clinic A');
        app()->instance('currentTenant', $tenant);

        $resolver = new ContainerOwnerResolver;

        expect($resolver->resolve()->is($tenant))->toBeTrue()
            ->and($resolver->hasOwner())->toBeTrue();
    });

    it('honours a custom binding key', function () {
        $tenant = $this->makeTenant('Clinic A');
        config()->set('blogify.tenancy.container_key', 'activeProvider');
        app()->instance('activeProvider', $tenant);

        expect((new ContainerOwnerResolver)->resolve()->is($tenant))->toBeTrue();
    });

    /*
     * The platform's own pages legitimately run with no tenant bound, so an
     * absent binding is platform-level content rather than an error.
     */
    it('reports platform-level when nothing is bound', function () {
        expect((new ContainerOwnerResolver)->resolve())->toBeNull();
    });

    it('ignores a binding that is not a model', function () {
        app()->instance('currentTenant', 'not-a-model');

        expect((new ContainerOwnerResolver)->resolve())->toBeNull();
    });
});

describe('AuthOwnerResolver', function () {
    it('reads the configured relation off the authenticated user', function () {
        $tenant = $this->makeTenant('Clinic A');
        $user = $this->makeUser('Staffer');
        $user->test_tenant_id = $tenant->getKey();
        $user->save();

        $this->actingAs($user);
        config()->set('blogify.tenancy.user_relation', 'tenant');

        expect((new AuthOwnerResolver)->resolve()->is($tenant))->toBeTrue();
    });

    it('reports platform-level for a guest', function () {
        expect((new AuthOwnerResolver)->resolve())->toBeNull();
    });

    it('reports platform-level when the relation is empty', function () {
        $this->actingAs($this->makeUser('Orphan'));
        config()->set('blogify.tenancy.user_relation', 'tenant');

        expect((new AuthOwnerResolver)->resolve())->toBeNull();
    });

    it('falls back to the user itself when configured to', function () {
        $user = $this->makeUser('Solo Author');
        $this->actingAs($user);

        config()->set('blogify.tenancy.user_relation', 'tenant');
        config()->set('blogify.tenancy.fallback_to_user', true);

        expect((new AuthOwnerResolver)->resolve()->is($user))->toBeTrue();
    });

    /*
     * A relation the user model does not define must not raise
     * BadMethodCallException — a package cannot assume the host's schema.
     */
    it('survives a relation the user model does not have', function () {
        $this->actingAs($this->makeUser('Staffer'));
        config()->set('blogify.tenancy.user_relation', 'nonexistentRelation');

        expect((new AuthOwnerResolver)->resolve())->toBeNull();
    });
});

describe('CallbackOwnerResolver', function () {
    it('defers to the registered callback', function () {
        $tenant = $this->makeTenant('Clinic A');

        Blogify::resolveOwnerUsing(fn () => $tenant);

        expect((new CallbackOwnerResolver)->resolve()->is($tenant))->toBeTrue();
    });

    it('reports platform-level with no callback registered', function () {
        expect((new CallbackOwnerResolver)->resolve())->toBeNull();
    });

    /*
     * The callback lives on the class rather than in config precisely so that
     * config:cache keeps working. A closure in a config file cannot be
     * serialised, so this guards the reason for that design.
     */
    it('keeps the config free of closures', function () {
        Blogify::resolveOwnerUsing(fn () => null);

        expect(config('blogify.tenancy'))->not->toContain(fn () => null);
        expect(serialize(config('blogify')))->toBeString();
    });
});

describe('third-party resolvers', function () {
    /*
     * Neither package is a dependency, so both resolvers degrade to
     * platform-level rather than exploding when the package is absent.
     */
    it('degrades gracefully without stancl/tenancy', function () {
        expect(StanclOwnerResolver::isAvailable())->toBeFalse()
            ->and((new StanclOwnerResolver)->resolve())->toBeNull()
            ->and((new StanclOwnerResolver)->hasOwner())->toBeFalse();
    });

    it('degrades gracefully without spatie/laravel-multitenancy', function () {
        expect(SpatieOwnerResolver::isAvailable())->toBeFalse()
            ->and((new SpatieOwnerResolver)->resolve())->toBeNull()
            ->and((new SpatieOwnerResolver)->hasOwner())->toBeFalse();
    });
});
