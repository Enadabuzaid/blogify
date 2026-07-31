<?php

declare(strict_types=1);

use Enadstack\Blogify\Support\ReadingTime;

it('returns zero for empty content', function () {
    expect(ReadingTime::minutes(null))->toBe(0)
        ->and(ReadingTime::minutes(''))->toBe(0)
        ->and(ReadingTime::minutes('   '))->toBe(0);
});

it('rounds up to whole minutes, never below one', function () {
    expect(ReadingTime::minutes(str_repeat('word ', 10), 200))->toBe(1)
        ->and(ReadingTime::minutes(str_repeat('word ', 200), 200))->toBe(1)
        ->and(ReadingTime::minutes(str_repeat('word ', 201), 200))->toBe(2);
});

/*
 * Arabic is space-separated, so a plain word count is the right measure — this
 * is the case the whole helper exists to get right.
 */
it('counts Arabic words like any other spaced script', function () {
    $arabic = str_repeat('كلمة ', 100);

    expect(ReadingTime::words($arabic))->toBe(100)
        ->and(ReadingTime::minutes($arabic, 50))->toBe(2);
});

it('strips HTML before counting', function () {
    expect(ReadingTime::words('<p>one two</p><div>three</div>'))->toBe(3);
});

it('decodes entities before counting', function () {
    expect(ReadingTime::words('one &amp; two'))->toBe(3);
});

/*
 * Chinese does not space its words, so a naive count would report 1 for an
 * entire article. Falling back to characters keeps the estimate meaningful.
 */
it('counts unspaced scripts by character', function () {
    $chinese = str_repeat('你好世界', 50); // 200 characters, no spaces

    expect(ReadingTime::words($chinese))->toBe(100)
        ->and(ReadingTime::minutes($chinese, 100))->toBe(1);
});

it('reads words per minute from config when not given', function () {
    config()->set('blogify.content.words_per_minute', 10);

    expect(ReadingTime::minutes(str_repeat('word ', 30)))->toBe(3);
});

it('survives a nonsensical rate', function () {
    expect(ReadingTime::minutes(str_repeat('word ', 5), 0))->toBe(5);
});
