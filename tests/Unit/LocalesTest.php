<?php

declare(strict_types=1);

use Enadstack\Blogify\Support\Locales;

beforeEach(function () {
    config()->set('blogify.locales.supported', ['en', 'ar']);
    config()->set('blogify.locales.default', 'en');
    config()->set('blogify.locales.fallback', 'en');
});

it('reports RTL for right-to-left languages', function (string $locale, string $direction) {
    expect(Locales::direction($locale))->toBe($direction);
})->with([
    ['ar', 'rtl'],
    ['he', 'rtl'],
    ['fa', 'rtl'],
    ['ur', 'rtl'],
    ['en', 'ltr'],
    ['fr', 'ltr'],
    ['zh', 'ltr'],
]);

/*
 * Regional variants have to resolve the same as their base language — an
 * 'ar-JO' locale is still right to left.
 */
it('matches on the primary subtag', function (string $locale) {
    expect(Locales::direction($locale))->toBe('rtl')
        ->and(Locales::isRtl($locale))->toBeTrue();
})->with([
    'hyphenated' => ['ar-JO'],
    'underscored' => ['ar_SA'],
    'uppercased' => ['AR'],
]);

it('narrows an unsupported locale to the fallback', function () {
    expect(Locales::resolve('de'))->toBe('en')
        ->and(Locales::resolve(null))->toBe('en')
        ->and(Locales::resolve(''))->toBe('en');
});

it('keeps a supported locale as given', function () {
    expect(Locales::resolve('ar'))->toBe('ar');
});

it('prefers the application locale when none is given', function () {
    app()->setLocale('ar');

    expect(Locales::resolve(null))->toBe('ar');
});

it('builds a fallback chain without duplicates', function () {
    config()->set('blogify.locales.fallback', 'ar');
    app()->setLocale('en');

    expect(Locales::fallbackChain('en'))->toBe(['en', 'ar']);
});

it('collapses the chain when every step agrees', function () {
    app()->setLocale('en');

    expect(Locales::fallbackChain('en'))->toBe(['en']);
});

it('reports which locales are supported', function () {
    expect(Locales::supported())->toBe(['en', 'ar'])
        ->and(Locales::isSupported('ar'))->toBeTrue()
        ->and(Locales::isSupported('de'))->toBeFalse();
});
