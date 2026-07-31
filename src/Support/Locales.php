<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Support;

/**
 * Locale helpers driven entirely by config.
 *
 * Text direction is computed here rather than stored on a row: it is a property
 * of the language, not of the content, so a column would only ever be able to
 * disagree with the truth.
 */
final class Locales
{
    /**
     * Every locale the application accepts content in.
     *
     * @return array<int, string>
     */
    public static function supported(): array
    {
        /** @var array<int, string> $locales */
        $locales = config('blogify.locales.supported', ['en']);

        return array_values($locales);
    }

    /**
     * The locale used when none is given.
     */
    public static function default(): string
    {
        return (string) config('blogify.locales.default', 'en');
    }

    /**
     * The locale to fall back to when a translation is missing.
     */
    public static function fallback(): string
    {
        return (string) config('blogify.locales.fallback', self::default());
    }

    /**
     * Whether a locale is accepted.
     */
    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::supported(), true);
    }

    /**
     * Narrow an arbitrary locale to a supported one.
     *
     * Order of preference: the given locale, the application's current locale,
     * then the configured fallback. Anything unsupported is discarded rather
     * than passed through, so a bad Accept-Language header cannot create rows
     * in a locale the application does not know about.
     */
    public static function resolve(?string $locale = null): string
    {
        foreach ([$locale, app()->getLocale(), self::fallback(), self::default()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && self::isSupported($candidate)) {
                return $candidate;
            }
        }

        return self::fallback();
    }

    /**
     * Text direction for a locale: 'rtl' or 'ltr'.
     *
     * Matches on the primary subtag so regional variants work — 'ar-JO'
     * resolves the same as 'ar'.
     */
    public static function direction(string $locale): string
    {
        /** @var array<int, string> $rtl */
        $rtl = config('blogify.locales.rtl', []);

        $primary = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return in_array($primary, array_map('strtolower', $rtl), true) ? 'rtl' : 'ltr';
    }

    /**
     * Whether a locale is written right to left.
     */
    public static function isRtl(string $locale): bool
    {
        return self::direction($locale) === 'rtl';
    }

    /**
     * The order in which locales should be tried when reading a translation.
     *
     * @return array<int, string>
     */
    public static function fallbackChain(?string $locale = null): array
    {
        $chain = [self::resolve($locale), self::fallback(), self::default()];

        return array_values(array_unique(array_filter($chain)));
    }
}
