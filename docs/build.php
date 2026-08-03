<?php

declare(strict_types=1);

use League\CommonMark\GithubFlavoredMarkdownConverter;

$docs_path = __DIR__;
$root_path = dirname(__DIR__);
$autoload = $root_path . '/vendor/autoload.php';
$manifest_file = $docs_path . '/manifest.json';

if ( !is_file($autoload) )
{
    throw new RuntimeException('Composer dependencies are missing. Run composer install first.');
}

require $autoload;

$manifest = json_decode(
    (string) file_get_contents($manifest_file),
    true,
    512,
    JSON_THROW_ON_ERROR
);

validate_manifest($manifest, $docs_path);
reset_output_directory($docs_path . '/site');

$converter = new GithubFlavoredMarkdownConverter([
    'html_input' => 'strip',
    'allow_unsafe_links' => false,
]);

$page_total = 0;

foreach ( $manifest['locales'] as $locale => &$settings )
{
    foreach ( $settings['pages'] as $index => &$page )
    {
        $source_file = $docs_path . '/' . $page['source'];
        $target_file = $docs_path . '/' . $page['page'];
        $markdown = (string) file_get_contents($source_file);
        $article = (string) $converter->convert($markdown);
        $article = convert_markdown_links($article);

        write_atomic(
            $target_file,
            render_page($manifest, $locale, $index, $article)
        );

        $page['sha256'] = hash('sha256', $markdown);
        $page_total++;
    }
    unset($page);

    $settings['page_count'] = count($settings['pages']);
}
unset($settings);

$manifest['generated_at'] = gmdate('Y-m-d\TH:i:s.000\Z');

write_atomic(
    $manifest_file,
    json_encode(
        $manifest,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    ) . PHP_EOL
);

write_atomic($docs_path . '/site/index.html', render_language_index($manifest));
copy_assets($docs_path . '/assets', $docs_path . '/site/assets');
write_llms_indexes($manifest, $docs_path);
validate_build($manifest, $docs_path);

echo "Generated {$page_total} localized PlatoPHP documentation pages." . PHP_EOL;

/**
 * Validate the bilingual documentation manifest before writing output.
 */
function validate_manifest(array $manifest, string $docs_path): void
{
    $default = (string) ($manifest['default_locale'] ?? '');
    $locales = $manifest['locales'] ?? null;

    if ( !is_array($locales) || !isset($locales[$default]) )
    {
        throw new RuntimeException('manifest.json must define a valid default_locale.');
    }

    $expected_slugs = null;

    foreach ( $locales as $locale => $settings )
    {
        $pages = $settings['pages'] ?? null;

        if ( !is_array($pages) || $pages === [] )
        {
            throw new RuntimeException("Locale {$locale} has no documentation pages.");
        }

        $slugs = [];

        foreach ( $pages as $page )
        {
            $slug = (string) ($page['slug'] ?? '');
            $source = $docs_path . '/' . ($page['source'] ?? '');

            if ( $slug === '' || isset($slugs[$slug]) )
            {
                throw new RuntimeException("Locale {$locale} has an empty or duplicate slug: {$slug}");
            }

            if ( !is_file($source) )
            {
                throw new RuntimeException("Documentation source does not exist: {$source}");
            }

            $slugs[$slug] = true;
        }

        $current_slugs = array_keys($slugs);

        if ( $expected_slugs !== null && $current_slugs !== $expected_slugs )
        {
            throw new RuntimeException("Locale {$locale} does not have the same ordered pages as the default locale.");
        }

        $expected_slugs = $current_slugs;
    }
}

/**
 * Remove generated output so deleted pages cannot survive a later build.
 */
function reset_output_directory(string $target): void
{
    if ( basename($target) !== 'site' || realpath(dirname($target)) !== realpath(__DIR__) )
    {
        throw new RuntimeException("Refusing to clean an unexpected documentation path: {$target}");
    }

    if ( is_dir($target) )
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $items as $item )
        {
            if ( $item->isDir() && !$item->isLink() )
            {
                if ( !rmdir($item->getPathname()) )
                {
                    throw new RuntimeException('Cannot remove generated directory: ' . $item->getPathname());
                }

                continue;
            }

            if ( !unlink($item->getPathname()) )
            {
                throw new RuntimeException('Cannot remove generated file: ' . $item->getPathname());
            }
        }

        if ( !rmdir($target) )
        {
            throw new RuntimeException("Cannot remove generated directory: {$target}");
        }
    }

    if ( !mkdir($target, 0777, true) && !is_dir($target) )
    {
        throw new RuntimeException("Cannot create generated directory: {$target}");
    }
}

/**
 * Convert links between Markdown sources to links between generated pages.
 */
function convert_markdown_links(string $html): string
{
    return (string) preg_replace_callback(
        '/href="((?![a-z]+:)[^"]+)\.md(#[^"]*)?"/i',
        static function (array $matches): string {
            return 'href="' . $matches[1] . '.html' . ($matches[2] ?? '') . '"';
        },
        $html
    );
}

/**
 * Render one localized documentation page.
 */
function render_page(array $manifest, string $locale, int $current_index, string $article): string
{
    $settings = $manifest['locales'][$locale];
    $page = $settings['pages'][$current_index];
    $ui = $settings['ui'];
    $title = escape_html((string) $page['title']);
    $slug = (string) $page['slug'];
    $alternate = alternate_locale($manifest, $locale);
    $alternate_page = find_page($manifest['locales'][$alternate]['pages'], $slug);
    $alternate_href = '../' . escape_html($alternate) . '/' . escape_html((string) $alternate_page['slug']) . '.html';
    $navigation = render_navigation($settings['pages'], $slug);
    $pager = render_pager($settings['pages'], $current_index, $ui);
    $page_count = count($settings['pages']);
    $description = escape_html((string) $settings['description'] . ': ' . $title);
    $html_lang = escape_html((string) $settings['html_lang']);
    $document_title = escape_html((string) $settings['document_title']);
    $tagline = escape_html((string) $settings['tagline']);
    $menu_label = escape_html((string) $ui['menu']);
    $home_label = escape_html((string) $ui['home']);
    $search_label = escape_html((string) $ui['search']);
    $search_placeholder = escape_html((string) $ui['search_placeholder']);
    $toc_label = escape_html((string) $ui['toc']);
    $language_label = escape_html((string) $ui['language']);
    $alternate_label = escape_html((string) $manifest['locales'][$alternate]['label']);
    $page_label = escape_html(str_replace('{count}', (string) $page_count, (string) $ui['page_count']));

    return <<<HTML
<!doctype html>
<html lang="{$html_lang}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{$description}">
    <title>{$title} | {$document_title}</title>
    <link rel="icon" href="../assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <header class="mobile-header">
        <button type="button" class="menu-button" aria-label="{$menu_label}" aria-expanded="false">☰</button>
        <a href="index.html" class="mobile-brand">PlatoPHP</a>
    </header>
    <div class="layout">
        <aside class="sidebar">
            <a class="brand" href="index.html" aria-label="{$home_label}">
                <span class="brand-mark">P</span>
                <span><strong>PlatoPHP</strong><small>{$tagline}</small></span>
            </a>
            <a class="language-switch" href="{$alternate_href}" hreflang="{$alternate}" aria-label="{$language_label}">{$alternate_label}</a>
            <label class="search-label" for="nav-search">{$search_label}</label>
            <input id="nav-search" class="nav-search" type="search" placeholder="{$search_placeholder}" autocomplete="off">
            <nav class="navigation" aria-label="{$toc_label}">
{$navigation}
            </nav>
            <p class="sidebar-meta">{$page_label}</p>
        </aside>
        <main class="main">
            <article class="document">
                {$article}
            </article>
            <nav class="pager" aria-label="{$toc_label}">
{$pager}
            </nav>
            <footer>PlatoPHP · <code>lumnd/platophp</code> · MIT</footer>
        </main>
    </div>
    <div class="sidebar-backdrop" hidden></div>
    <script src="../assets/app.js"></script>
</body>
</html>
HTML;
}

/**
 * Render the navigation, including localized section headings.
 */
function render_navigation(array $pages, string $current_slug): string
{
    $links = [];
    $section = null;

    foreach ( $pages as $page )
    {
        if ( $section !== $page['section'] )
        {
            $section = $page['section'];
            $links[] = '                <span class="nav-section">' . escape_html((string) $section) . '</span>';
        }

        $slug = escape_html((string) $page['slug']);
        $title = escape_html((string) $page['title']);
        $active = $page['slug'] === $current_slug
            ? ' aria-current="page" class="active"'
            : '';
        $links[] = "                <a href=\"{$slug}.html\"{$active} data-title=\"{$title}\">{$title}</a>";
    }

    return implode(PHP_EOL, $links);
}

/**
 * Render previous and next links for a locale.
 */
function render_pager(array $pages, int $current_index, array $ui): string
{
    $links = [];

    if ( isset($pages[$current_index - 1]) )
    {
        $previous = $pages[$current_index - 1];
        $slug = escape_html((string) $previous['slug']);
        $title = escape_html((string) $previous['title']);
        $label = escape_html((string) $ui['previous']);
        $links[] = "                <a class=\"pager-link previous\" href=\"{$slug}.html\"><span>{$label}</span><strong>{$title}</strong></a>";
    }

    if ( isset($pages[$current_index + 1]) )
    {
        $next = $pages[$current_index + 1];
        $slug = escape_html((string) $next['slug']);
        $title = escape_html((string) $next['title']);
        $label = escape_html((string) $ui['next']);
        $links[] = "                <a class=\"pager-link next\" href=\"{$slug}.html\"><span>{$label}</span><strong>{$title}</strong></a>";
    }

    return implode(PHP_EOL, $links);
}

/**
 * Render the root language chooser with a safe default redirect.
 */
function render_language_index(array $manifest): string
{
    $default = escape_html((string) $manifest['default_locale']);
    $links = [];

    foreach ( $manifest['locales'] as $locale => $settings )
    {
        $href = escape_html($locale . '/index.html');
        $label = escape_html((string) $settings['label']);
        $links[] = "            <a href=\"{$href}\">{$label}</a>";
    }

    $language_links = implode(PHP_EOL, $links);

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0; url={$default}/index.html">
    <title>PlatoPHP Documentation</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="language-index">
    <main>
        <h1>PlatoPHP</h1>
        <p>Choose a documentation language</p>
        <nav>
{$language_links}
        </nav>
    </main>
</body>
</html>
HTML;
}

/**
 * Write one llms index per locale and a root language index.
 */
function write_llms_indexes(array $manifest, string $docs_path): void
{
    $root = ['# PlatoPHP', '', '## Documentation languages', ''];

    foreach ( $manifest['locales'] as $locale => $settings )
    {
        $root[] = '- [' . $settings['label'] . '](llms-' . $locale . '.txt)';
        $lines = [
            '# PlatoPHP',
            '',
            '> ' . $settings['description'],
            '',
            '## ' . $settings['ui']['toc'],
            '',
        ];

        foreach ( $settings['pages'] as $page )
        {
            $lines[] = '- [' . $page['title'] . '](' . $page['source'] . ')';
        }

        write_atomic($docs_path . '/llms-' . $locale . '.txt', implode(PHP_EOL, $lines) . PHP_EOL);
    }

    write_atomic($docs_path . '/llms.txt', implode(PHP_EOL, $root) . PHP_EOL);
}

/**
 * Return the other configured locale.
 */
function alternate_locale(array $manifest, string $locale): string
{
    foreach ( array_keys($manifest['locales']) as $candidate )
    {
        if ( $candidate !== $locale )
        {
            return (string) $candidate;
        }
    }

    return $locale;
}

/**
 * Find the matching page in another locale.
 */
function find_page(array $pages, string $slug): array
{
    foreach ( $pages as $page )
    {
        if ( $page['slug'] === $slug )
        {
            return $page;
        }
    }

    throw new RuntimeException("Missing translated page: {$slug}");
}

/**
 * Copy documentation assets into the self-contained generated site.
 */
function copy_assets(string $source, string $target): void
{
    if ( !is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target) )
    {
        throw new RuntimeException("Cannot create directory: {$target}");
    }

    foreach ( new DirectoryIterator($source) as $item )
    {
        if ( $item->isDot() || !$item->isFile() )
        {
            continue;
        }

        if ( !copy($item->getPathname(), $target . '/' . $item->getFilename()) )
        {
            throw new RuntimeException('Cannot copy documentation asset: ' . $item->getPathname());
        }
    }
}

/**
 * Validate generated pages, language switches, links and assets.
 */
function validate_build(array $manifest, string $docs_path): void
{
    foreach ( $manifest['locales'] as $locale => $settings )
    {
        foreach ( $settings['pages'] as $page )
        {
            $target = $docs_path . '/' . $page['page'];
            $html = (string) file_get_contents($target);

            if ( !str_contains($html, '<html lang="' . $settings['html_lang'] . '">') )
            {
                throw new RuntimeException("Generated page has the wrong language: {$target}");
            }

            if ( !str_contains($html, '../assets/styles.css') || !str_contains($html, '../assets/app.js') )
            {
                throw new RuntimeException("Generated page has broken asset paths: {$target}");
            }

            if ( !str_contains($html, 'hreflang=') )
            {
                throw new RuntimeException("Generated page has no language switch: {$target}");
            }

            foreach ( extract_local_links($html) as $link )
            {
                $path = parse_url($link, PHP_URL_PATH);

                if ( !is_string($path) || $path === '' )
                {
                    continue;
                }

                $resolved = dirname($target) . '/' . $path;

                if ( !is_file($resolved) )
                {
                    throw new RuntimeException("Broken link in {$target}: {$link}");
                }
            }
        }
    }
}

/**
 * Extract local href and src values from generated HTML.
 *
 * @return array<int, string>
 */
function extract_local_links(string $html): array
{
    preg_match_all('/(?:href|src)="([^"]+)"/', $html, $matches);

    return array_values(array_filter(
        $matches[1] ?? [],
        static fn (string $link): bool => !preg_match('/^(?:[a-z]+:|#)/i', $link)
    ));
}

/**
 * Write a file through a temporary sibling and rename it into place.
 */
function write_atomic(string $file, string $contents): void
{
    $dir = dirname($file);

    if ( !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir) )
    {
        throw new RuntimeException("Cannot create directory: {$dir}");
    }

    $temporary = $file . '.tmp.' . getmypid();

    if ( file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $file) )
    {
        @unlink($temporary);
        throw new RuntimeException("Cannot write file: {$file}");
    }
}

/**
 * Escape text used in generated HTML.
 */
function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
