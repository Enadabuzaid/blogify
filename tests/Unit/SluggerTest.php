<?php

declare(strict_types=1);

use Enadstack\Blogify\Support\Slugger;

it('preserves Arabic', function () {
    expect(Slugger::make('مرحبا بالعالم'))->toBe('مرحبا-بالعالم')
        ->and(Slugger::make('نصائح النوم'))->toBe('نصائح-النوم');
});

it('preserves other non-Latin scripts', function (string $input, string $expected) {
    expect(Slugger::make($input))->toBe($expected);
})->with([
    'Hebrew' => ['שלום עולם', 'שלום-עולם'],
    'Chinese' => ['你好世界', '你好世界'],
    'Russian' => ['Здравствуй мир', 'здравствуй-мир'],
]);

it('folds accents on Latin input', function () {
    expect(Slugger::make('Café Münster'))->toBe('cafe-munster');
});

it('collapses punctuation and whitespace into single separators', function (string $input, string $expected) {
    expect(Slugger::make($input))->toBe($expected);
})->with([
    ['Hello,   World!!', 'hello-world'],
    ['  leading and trailing  ', 'leading-and-trailing'],
    ['كيف — تنام jيدا؟', 'كيف-تنام-jيدا'],
    ['a/b\\c:d', 'a-b-c-d'],
]);

it('keeps digits', function () {
    expect(Slugger::make('Top 10 Tips'))->toBe('top-10-tips')
        ->and(Slugger::make('أفضل 10 نصائح'))->toBe('أفضل-10-نصائح');
});

it('returns empty for input with nothing sluggable', function () {
    expect(Slugger::make(''))->toBe('')
        ->and(Slugger::make('   '))->toBe('')
        ->and(Slugger::make('!!!'))->toBe('');
});

it('honours a custom separator', function () {
    expect(Slugger::make('Hello World', '_'))->toBe('hello_world')
        ->and(Slugger::make('مرحبا بالعالم', '_'))->toBe('مرحبا_بالعالم');
});

describe('unique()', function () {
    it('returns the base slug when it is free', function () {
        expect(Slugger::unique('Hello World', fn () => false))->toBe('hello-world');
    });

    it('appends an incrementing suffix on collision', function () {
        $taken = ['hello-world', 'hello-world-2', 'hello-world-3'];

        expect(Slugger::unique('Hello World', fn (string $s) => in_array($s, $taken, true)))
            ->toBe('hello-world-4');
    });

    it('suffixes Arabic slugs the same way', function () {
        $taken = ['مرحبا-بالعالم'];

        expect(Slugger::unique('مرحبا بالعالم', fn (string $s) => in_array($s, $taken, true)))
            ->toBe('مرحبا-بالعالم-2');
    });

    it('substitutes a placeholder when nothing is sluggable', function () {
        expect(Slugger::unique('!!!', fn () => false))->toBe('n-a');
    });

    /*
     * A caller whose existence check always reports "taken" must not spin
     * forever — it falls back to a random suffix instead.
     */
    it('gives up on an always-colliding check rather than hanging', function () {
        $slug = Slugger::unique('Hello World', fn () => true);

        expect($slug)->toStartWith('hello-world-')
            ->and($slug)->not->toBe('hello-world-2');
    });
});
