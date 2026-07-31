<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Support;

/**
 * Estimates reading time in whole minutes.
 *
 * Counts whitespace-delimited words, which works for Arabic and every other
 * space-separated script. Scripts without word spacing (Chinese, Japanese) are
 * counted by character and divided, since a word count of 1 would otherwise be
 * reported for an entire article.
 */
final class ReadingTime
{
    /**
     * Average characters per word for scripts that do not space words.
     */
    private const CJK_CHARS_PER_WORD = 2;

    /**
     * Minutes to read the given content, rounded up, never below 1.
     */
    public static function minutes(?string $content, ?int $wordsPerMinute = null): int
    {
        $words = self::words($content);

        if ($words === 0) {
            return 0;
        }

        $rate = $wordsPerMinute ?? (int) config('blogify.content.words_per_minute', 200);
        $rate = max(1, $rate);

        return max(1, (int) ceil($words / $rate));
    }

    /**
     * Word count of the given content, with markup removed.
     */
    public static function words(?string $content): int
    {
        if ($content === null || trim($content) === '') {
            return 0;
        }

        // Bodies may be HTML or Markdown; neither should have its tags counted.
        //
        // Tags become a space rather than being deleted, because strip_tags on
        // its own concatenates across them: '<p>one two</p><div>three</div>'
        // collapses to 'one twothree', undercounting by a word at every block
        // boundary.
        $text = preg_replace('/<[^>]*>/', ' ', $content) ?? $content;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return 0;
        }

        $spaced = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        // A long body that split into very few "words" is an unspaced script.
        // Fall back to counting characters so the estimate stays meaningful.
        $chars = mb_strlen($text, 'UTF-8');

        if ($spaced > 0 && $chars / $spaced > 20) {
            return (int) ceil($chars / self::CJK_CHARS_PER_WORD);
        }

        return $spaced;
    }
}
