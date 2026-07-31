<?php

declare(strict_types=1);

use Enadstack\Blogify\Enums\PostStatus;
use Enadstack\Blogify\Models\Post;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    config()->set('app.url', 'https://example.test');
});

function livePost(): Post
{
    return Post::query()->create([
        'type' => 'post',
        'status' => PostStatus::Published->value,
        'published_at' => now()->subDay(),
    ]);
}

it('writes a sitemap file', function () {
    livePost()->setTranslation('en', ['title' => 'Hello World']);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    Storage::disk('public')->assertExists('sitemap-blog.xml');

    $xml = Storage::disk('public')->get('sitemap-blog.xml');

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($xml)->toContain('https://example.test/en/blog/hello-world')
        ->and($xml)->toContain('<changefreq>weekly</changefreq>');
});

it('produces well-formed XML', function () {
    livePost()->setTranslations([
        'en' => ['title' => 'Hello World'],
        'ar' => ['title' => 'مرحبا بالعالم'],
    ]);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    $xml = simplexml_load_string(Storage::disk('public')->get('sitemap-blog.xml'));

    expect($xml)->not->toBeFalse()
        ->and($xml->url)->toHaveCount(2);
});

/*
 * Non-Latin slugs have to be percent-encoded to be valid in XML and in a
 * Location header, even though the stored slug keeps its Arabic characters.
 */
it('percent-encodes an Arabic slug', function () {
    livePost()->setTranslation('ar', ['title' => 'مرحبا بالعالم']);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap-blog.xml');

    expect($xml)->toContain('%D9%85%D8%B1%D8%AD%D8%A8%D8%A7')
        ->and(simplexml_load_string($xml))->not->toBeFalse();
});

it('emits hreflang alternates for each locale', function () {
    livePost()->setTranslations([
        'en' => ['title' => 'Hello World'],
        'ar' => ['title' => 'مرحبا بالعالم'],
    ]);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap-blog.xml');

    expect($xml)->toContain('hreflang="en"')
        ->and($xml)->toContain('hreflang="ar"')
        ->and($xml)->toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');
});

it('honours an explicit base url and path', function () {
    livePost()->setTranslation('en', ['title' => 'Hello World']);

    $this->artisan('blogify:sitemap', [
        '--base-url' => 'https://clinic.test/',
        '--path' => 'sitemaps/blog.xml',
    ])->assertSuccessful();

    Storage::disk('public')->assertExists('sitemaps/blog.xml');

    expect(Storage::disk('public')->get('sitemaps/blog.xml'))
        ->toContain('https://clinic.test/en/blog/hello-world');
});

it('covers only platform content', function () {
    $tenant = $this->makeTenant('Clinic A');

    $this->actingForOwner($tenant);
    livePost()->setTranslation('en', ['title' => 'Tenant Post']);

    $this->actingForOwner(null);
    livePost()->setTranslation('en', ['title' => 'Platform Post']);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    $xml = Storage::disk('public')->get('sitemap-blog.xml');

    expect($xml)->toContain('platform-post')
        ->and($xml)->not->toContain('tenant-post');
});

it('writes an empty sitemap when there is no content', function () {
    $this->artisan('blogify:sitemap')
        ->expectsOutputToContain('no published content')
        ->assertSuccessful();

    expect(Storage::disk('public')->get('sitemap-blog.xml'))->toContain('</urlset>');
});

it('does nothing when sitemaps are disabled', function () {
    config()->set('blogify.seo.sitemap.enabled', false);

    $this->artisan('blogify:sitemap')->assertSuccessful();

    Storage::disk('public')->assertMissing('sitemap-blog.xml');
});

it('fails without a base url', function () {
    config()->set('app.url', null);

    $this->artisan('blogify:sitemap')->assertFailed();
});
