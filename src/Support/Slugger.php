<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Support;

use Illuminate\Support\Str;

/**
 * Builds URL slugs that survive non-Latin scripts.
 *
 * Why not Str::slug(): it forces the result down to ASCII, and that fails in two
 * different ways depending on the script.
 *
 *     Str::slug('مرحبا بالعالم')    // => 'mrhba-balaaalm'   mangled
 *     Str::slug('نصائح النوم')      // => 'nsayh-alnom'      mangled
 *     Str::slug('שלום עולם')        // => ''                 dropped
 *     Str::slug('你好世界')          // => ''                 dropped
 *
 * Transliterated Arabic is unreadable to an Arabic speaker and worthless for
 * Arabic search; Hebrew and CJK vanish outright, so every such post would
 * collide on the empty string. Neither is acceptable in a package whose premise
 * is first-class RTL support.
 *
 * Unicode letters and digits are preserved instead. Non-ASCII characters are
 * legal in a URL path, browsers display them decoded, and it is what Arabic and
 * CJK publishers actually do.
 *
 *     Slugger::make('مرحبا بالعالم')   // => 'مرحبا-بالعالم'
 *     Slugger::make('שלום עולם')       // => 'שלום-עולם'
 *
 * Latin-script input still goes through Str::slug, so accents fold as Laravel
 * users expect:
 *
 *     Slugger::make('Café Münster')    // => 'cafe-munster'
 */
final class Slugger
{
    /**
     * Slugify a string, preserving letters and digits in any script.
     */
    public static function make(string $value, string $separator = '-'): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Normalise first, in every script: collapse each run of non-letter,
        // non-digit characters into one separator. Doing this before the Latin
        // branch is what keeps the two paths consistent — a slash or colon reads
        // as a word boundary either way. Str::slug on its own would delete them
        // ('Health/Wellness' => 'healthwellness').
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', $separator, self::lower($value)) ?? '';
        $slug = trim($slug, $separator);

        // Latin-script input then goes through Str::slug, which folds accents
        // properly ('café' => 'cafe'). Anything containing a non-Latin letter is
        // returned as is, because folding it would mangle or erase it.
        return self::isLatinScript($slug) ? Str::slug($slug, $separator) : $slug;
    }

    /**
     * Slugify, then make it unique by appending -2, -3, and so on.
     *
     * $exists receives each candidate and reports whether it is taken. The
     * caller owns that check, so uniqueness can be scoped however the table
     * requires — per owner and locale, in Blogify's case.
     *
     * @param  callable(string): bool  $exists
     */
    public static function unique(string $value, callable $exists, string $separator = '-'): string
    {
        $base = self::make($value, $separator);

        if ($base === '') {
            $base = 'n'.$separator.'a';
        }

        if (! $exists($base)) {
            return $base;
        }

        $suffix = 2;

        while ($exists($candidate = $base.$separator.$suffix)) {
            $suffix++;

            // Defensive: a caller whose $exists always returns true would
            // otherwise spin forever.
            if ($suffix > 10000) {
                return $base.$separator.Str::lower(Str::random(8));
            }
        }

        return $candidate;
    }

    /**
     * Whether every letter in the value belongs to the Latin script.
     *
     * The test is on script rather than on ASCII-ness, so accented Latin such as
     * 'café' still qualifies and gets folded. Matches a position that is a letter
     * but not a Latin letter; no such position means the value is safe to fold.
     */
    private static function isLatinScript(string $value): bool
    {
        return preg_match('/(?=\p{L})(?!\p{Latin})./u', $value) !== 1;
    }

    /**
     * Lowercase without mangling multibyte input.
     *
     * Scripts without case (Arabic, Hebrew, CJK) pass through unchanged.
     */
    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
