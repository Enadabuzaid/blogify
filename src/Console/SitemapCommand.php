<?php

declare(strict_types=1);

namespace Enadstack\Blogify\Console;

use Enadstack\Blogify\Seo\SitemapBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Writes a sitemap XML file for platform-level content.
 *
 * Deliberately limited to the platform's own blog. Per-tenant sitemaps need the
 * application's URL scheme — subdomain, custom domain, path prefix — which the
 * package cannot know, so those are built in application code from
 * Blogify::sitemap($owner, $urlBuilder). This command exists for the common
 * single-site case where a base URL is enough.
 */
class SitemapCommand extends Command
{
    protected $signature = 'blogify:sitemap
        {--base-url= : Absolute base URL, e.g. https://example.com}
        {--path=sitemap-blog.xml : Path to write on the configured disk}
        {--disk= : Filesystem disk to write to}';

    protected $description = 'Write a sitemap for platform-level blog content';

    public function handle(): int
    {
        if (! config('blogify.seo.sitemap.enabled', true)) {
            $this->components->warn('blogify.seo.sitemap.enabled is false; nothing written.');

            return self::SUCCESS;
        }

        $baseUrl = $this->option('base-url') ?: config('app.url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            $this->components->error('No base URL. Pass --base-url or set app.url.');

            return self::FAILURE;
        }

        $baseUrl = rtrim($baseUrl, '/');
        $prefix = trim((string) config('blogify.routes.prefix', 'blog'), '/');

        $urlBuilder = function (string $locale, string $slug) use ($baseUrl, $prefix): string {
            // Percent-encode the slug so non-Latin characters are valid in XML
            // and in a Location header, while leaving the separators readable.
            $segments = array_filter([$locale, $prefix, rawurlencode($slug)]);

            return $baseUrl.'/'.implode('/', $segments);
        };

        $entries = iterator_to_array(app(SitemapBuilder::class)->forOwner(null, $urlBuilder));

        $disk = $this->option('disk') ?: config('blogify.media.disk', 'public');
        $path = (string) $this->option('path');

        Storage::disk($disk)->put($path, $this->toXml($entries));

        $this->components->info(
            count($entries) === 0
                ? "Wrote an empty sitemap to [{$path}] — no published content."
                : 'Wrote '.count($entries)." URL(s) to [{$path}] on disk [{$disk}]."
        );

        return self::SUCCESS;
    }

    /**
     * Render entries as a sitemap document with hreflang alternates.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    protected function toXml(array $entries): string
    {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
                .' xmlns:xhtml="http://www.w3.org/1999/xhtml">',
        ];

        foreach ($entries as $entry) {
            $lines[] = '    <url>';
            $lines[] = '        <loc>'.$this->escape((string) $entry['loc']).'</loc>';

            if (! empty($entry['lastmod'])) {
                $lines[] = '        <lastmod>'.$this->escape((string) $entry['lastmod']).'</lastmod>';
            }

            $lines[] = '        <changefreq>'.$this->escape((string) $entry['changefreq']).'</changefreq>';
            $lines[] = '        <priority>'.$this->escape((string) $entry['priority']).'</priority>';

            // These are what tell a crawler the locale variants are translations
            // of one another rather than duplicate content.
            foreach ($entry['alternates'] ?? [] as $locale => $href) {
                $lines[] = sprintf(
                    '        <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                    $this->escape((string) $locale),
                    $this->escape((string) $href)
                );
            }

            $lines[] = '    </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
