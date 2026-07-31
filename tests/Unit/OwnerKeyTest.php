<?php

declare(strict_types=1);

use Enadstack\Blogify\Support\OwnerKey;
use Illuminate\Database\Eloquent\Relations\Relation;

it('maps null to the platform sentinel', function () {
    expect(OwnerKey::for(null))->toBe('*')
        ->and(OwnerKey::isPlatform(OwnerKey::for(null)))->toBeTrue();
});

it('builds a key from a model\'s morph class and key', function () {
    $tenant = $this->makeTenant('Clinic A');

    expect(OwnerKey::for($tenant))->toBe($tenant::class.':'.$tenant->getKey())
        ->and(OwnerKey::isPlatform(OwnerKey::for($tenant)))->toBeFalse();
});

/*
 * A morph map keeps the key short and survives class renames, which is why the
 * key is built from getMorphClass() rather than from the class name directly.
 */
it('uses the morph alias when one is registered', function () {
    $tenant = $this->makeTenant('Clinic A');

    Relation::morphMap(['tenant' => $tenant::class]);

    expect(OwnerKey::for($tenant))->toBe('tenant:'.$tenant->getKey())
        ->and(strlen(OwnerKey::for($tenant)))->toBeLessThan(191);

    Relation::morphMap([], false);
});

it('distinguishes two models of different types with the same key', function () {
    $tenant = $this->makeTenant('Clinic A');
    $user = $this->makeUser('Author');

    // Contrived but the point stands: a flat tenant_id could not tell these
    // apart, which is why the owner is polymorphic.
    expect(OwnerKey::for($tenant))->not->toBe(OwnerKey::for($user));
});

it('distinguishes two models of the same type', function () {
    $a = $this->makeTenant('Clinic A');
    $b = $this->makeTenant('Clinic B');

    expect(OwnerKey::for($a))->not->toBe(OwnerKey::for($b));
});

it('handles a ULID key and a bigint key alike', function () {
    $tenant = $this->makeTenant('Clinic A');
    $user = $this->makeUser('Author');

    expect(OwnerKey::for($tenant))->toEndWith((string) $tenant->getKey())
        ->and(strlen((string) $tenant->getKey()))->toBe(26)
        ->and(OwnerKey::for($user))->toEndWith((string) $user->getKey());
});
