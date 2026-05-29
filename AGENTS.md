# AGENTS.md — WP Scraper Importer

Guidance for AI agents (and humans) working on this plugin. Read this before changing code.

## What this is

A **base/scaffold plugin** for scraping external pages and importing them as WordPress posts. It is meant to be **cloned and configured in code** — it does nothing out of the box. There is **no admin UI and no settings stored in the database**; all configuration is static, set at bootstrap via the `WP_Scraper` facade. (It was itself forked from the a8cteam51 team51-plugin-scaffold; a real-world descendant is `creating-passionate-importer`.)

## How it works (the pipeline)

`Import_Command` (`wp scraper-to-wp`) orchestrates:

1. `WP_Scraper::get_url_provider()` → a `URL_Provider`; `setup()` → `get_urls(limit, offset, per_page, filter)` → `teardown()`.
2. For each URL: `new Content_Scraper($url)` → `process()` (fetches HTML, follows redirects via `wp_remote_get`).
3. `WP_Scraper::get_content_mapper($scraper)` → an `Abstract_Content_Mapper` that extracts `get_title()/get_content()/get_author()/get_terms()/get_post_*()/get_post_date()`.
4. `Import_Command` applies `WP_Scraper` fallbacks (author/post_type/post_status) only when the mapper returns nothing.
5. `new Post_Inserter(... post_date ...)` → `create()` writes the post; `get_post_id()` returns the new ID.
6. `Media::from_url($url)` imports images and dedupes via `Media_Store` (URL→ID map, persisted under `uploads/wp-scraper-importer/`).
7. `Progress_Tracker` records scraped URLs for resumable runs.

### Key files
- `src/WP_Scraper.php` — static config facade. The single place a clone wires things up.
- `src/Provider/URL_Provider.php` — interface: `setup()`, `get_urls()`, `teardown()`. `Noop_URL_Provider` (default), `CSV_URL_Provider` (example, `FILE_PATH` const, uses `static::` so subclasses can override).
- `src/Mapper/{Abstract,Default}_Content_Mapper.php` — extraction. `get_post_date()` is concrete on the abstract (default `''`) so it's an optional override, not a BC break.
- `src/Action/{Content_Scraper,Post_Inserter}.php`
- `src/Service/{Media,Media_Store,Progress_Tracker}.php`
- `bin/wp-env-after-start.sh` — wp-env `afterStart`: installs `pdo_mysql` into *this* instance's container.

## Using it: adding a custom URL provider

This is the main extension point — it's how you tell the importer *what to import*. A provider is any class implementing `URL_Provider`; you register it by **class name**, so it must be constructable with no arguments.

### The interface contract

```php
interface URL_Provider {
    public function setup(): void;     // open resources (called once, before get_urls)
    public function get_urls(
        int $limit = 0,                // hard cap on total returned (0 = no cap)
        int $offset = 0,               // skip this many from the start
        int $per_page = 0,             // return at most this many this call (0 = all remaining)
        ?callable $filter = null       // fn(string $url): bool — return true to keep
    ): array;                          // returns string[] of URLs
    public function teardown(): void;  // release resources (called once, after consumption)
}
```

`Import_Command` calls `setup()`, then `get_urls(0, 0, 0, …)`, then `teardown()`. The pagination args exist so a large/remote source can page at the source (push `limit`/`offset`/`per_page` into SQL `LIMIT/OFFSET`, an API `?page=`, etc.) — honour them if your source is big; ignore them (defaults) if it's a small in-memory list.

### Step 1 — write the provider

You've cloned this repo as your scaffold, so add the class straight into `src/` — e.g. `src/Provider/Sitemap_URL_Provider.php`, in the plugin's own namespace. It is then PSR-4 autoloaded like every other `src/` class (no `require`, no extra plugin). Keep everything it needs *inside the class*; open/close resources in `setup()`/`teardown()`, never the constructor.

```php
<?php
// src/Provider/Sitemap_URL_Provider.php
namespace A8C\SpecialProjects\ScraperToWP\Provider;

/**
 * Reads URLs from an XML sitemap. (URL_Provider is in this same namespace, so no `use`.)
 */
class Sitemap_URL_Provider implements URL_Provider {

    private const SITEMAP_URL = 'https://example.com/sitemap.xml';

    /** @var array<int, string> */
    private array $urls = array();

    public function setup(): void {
        // Fetch the source once. Runs before get_urls().
        $body = wp_remote_retrieve_body( wp_remote_get( self::SITEMAP_URL, array( 'timeout' => 30 ) ) );
        $xml  = '' !== $body ? simplexml_load_string( $body ) : false;

        if ( false !== $xml ) {
            foreach ( $xml->url as $entry ) {
                $this->urls[] = (string) $entry->loc;
            }
        }
    }

    public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
        $urls = $this->urls;

        if ( null !== $filter ) {
            $urls = array_values( array_filter( $urls, $filter ) );
        }
        if ( $limit > 0 ) {
            $urls = array_slice( $urls, 0, $limit );
        }
        if ( $offset > 0 || $per_page > 0 ) {
            $urls = array_slice( $urls, $offset, $per_page > 0 ? $per_page : null );
        }

        return $urls;
    }

    public function teardown(): void {
        $this->urls = array();
    }
}
```

**Database-backed variant** — `setup()` opens the connection, `get_urls()` pushes paging into SQL, `teardown()` closes it:

```php
public function setup(): void {
    $this->pdo = new \PDO( 'sqlite:' . $this->path );           // open in setup, NOT the constructor
}
public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
    $sql    = 'SELECT url FROM queue WHERE imported = 0';
    $params = array();
    $take   = $per_page > 0 ? $per_page : $limit;              // page at the source
    if ( $take > 0 ) { $sql .= ' LIMIT :take OFFSET :off'; $params = array( ':take' => $take, ':off' => $offset ); }
    $stmt = $this->pdo->prepare( $sql );
    $stmt->execute( $params );
    return $stmt->fetchAll( \PDO::FETCH_COLUMN );
}
public function teardown(): void {
    $this->pdo = null;
}
```

For the simplest case — a fixed list or a CSV — just subclass the shipped `CSV_URL_Provider` and override the `FILE_PATH` constant (it uses `static::`, so the override is honoured), or see `tests/Support/Test_URL_Provider.php` for a hard-coded-array example.

### Step 2 — register it

This repo *is* the plugin, so register inline in **`plugin.php`**, right after the `vendor/autoload.php` require (around line 67). The autoloader is live by then, so `WP_Scraper` and your `src/` classes resolve. No hook, no separate file — the provider isn't instantiated until the `wp scraper-to-wp` command runs, so storing the class names here is plenty early.

```php
// plugin.php — immediately after: require_once … '/vendor/autoload.php';
use A8C\SpecialProjects\ScraperToWP\WP_Scraper;
use A8C\SpecialProjects\ScraperToWP\Provider\Sitemap_URL_Provider;
use A8C\SpecialProjects\ScraperToWP\Mapper\Article_Mapper;

WP_Scraper::set_url_provider( Sitemap_URL_Provider::class );
WP_Scraper::set_content_mapper( Article_Mapper::class );
```

(The `use` lines go at the top of `plugin.php`; the two calls go after the autoloader require.)

### Step 3 — run

```bash
wp scraper-to-wp --dry-run   # verify the URLs/extraction first
wp scraper-to-wp             # real import
```

That's the whole loop. `get_url_provider()` resolves your class (falling back to `Noop_URL_Provider` if none is set), and the command scrapes + maps + inserts each URL.

## Using it: adding a custom content mapper

Add it under `src/Mapper/` in the plugin's namespace (PSR-4 autoloaded). Extend `Default_Content_Mapper` and override only the methods you need; read the raw HTML with `$this->content_scraper->get_content()`.

```php
<?php
// src/Mapper/Article_Mapper.php
namespace A8C\SpecialProjects\ScraperToWP\Mapper;

// Default_Content_Mapper is in this same namespace, so no `use`.
class Article_Mapper extends Default_Content_Mapper {

    public function get_title(): string {
        // Read inner text with DOMDocument (Tag_Processor can't — see Gotchas).
        libxml_use_internal_errors( true );
        $dom = new \DOMDocument();
        $dom->loadHTML( $this->content_scraper->get_content() );
        libxml_clear_errors();
        $h1 = $dom->getElementsByTagName( 'h1' )->item( 0 );
        return $h1 ? trim( $h1->textContent ) : parent::get_title();
    }

    public function get_post_type(): string   { return 'article'; }
    public function get_post_status(): string { return 'draft'; }

    // Preserve the source publish date (empty string = WP uses current time).
    public function get_post_date(): string {
        // …parse a date out of the markup…
        return '';
    }
}
```

Register it in `plugin.php` the same way as the provider: `WP_Scraper::set_content_mapper( Article_Mapper::class );`. See `tests/Support/Sample_Content_Mapper.php` for a worked example that demonstrates **both** extraction styles — `get_title()` via the WP HTML API (`WP_HTML_Processor`) and `get_content()` via `DOMDocument`.

For images inside the content, call `Media::from_url( $image_url )` — it returns the attachment ID and only downloads each URL once.

### Content mapper — overridable methods

All are defined on `Abstract_Content_Mapper` and implemented (as placeholders) by `Default_Content_Mapper`. Override only what you need. The mapper is constructed with the `Content_Scraper`, available as `$this->content_scraper`.

| Method | Returns | Default (`Default_Content_Mapper`) | Notes |
|--------|---------|-----------------------------------|-------|
| `get_title()` | `string` | `<title>` text, else `'Imported Content'` | |
| `get_content()` | `string` | inner HTML of `<main>`, else `''` | runs each child through `map_content_child_node()` |
| `get_author()` | `int` | `1` | `<= 0` → falls back to `WP_Scraper::get_default_user()` |
| `get_terms()` | `array<string,string[]>` | `[]` | taxonomy slug => term names |
| `map_content_child_node( string $html )` | `string` | passthrough | hook to transform each top-level content node |
| `get_post_status()` | `string` | `'publish'` | `''` → falls back to `WP_Scraper::get_post_status()` |
| `get_post_type()` | `string` | `'post'` | `''` → falls back to `WP_Scraper::get_post_type()` |
| `get_post_parent()` | `int` | `0` | |
| `get_featured_image()` | `string` | `''` | image URL; imported via `Media` when set |
| `get_post_meta()` | `array<string,mixed>` | `[]` | meta key => value |
| `get_post_date()` | `string` | `''` | concrete on the abstract (optional override); `''` = current time |

Convenience aggregators (don't usually override): `compile_content()`, `compile_post_config()`, `compile_all()`.

### Post_Inserter API (what the command calls)

`new Post_Inserter( $title, $content, $status, $author, $post_date, $taxonomies, $meta )` then:
- `->set_post_type( string )`, `->set_parent_id( int )`, `->set_featured_image( string $url, array $args = [] )` (fluent)
- `->create()` / `->update( int $post_id )` — runs `wp_insert_post`/`wp_update_post`
- `->get_post_id(): ?int`, `->has_post_instance(): bool`
- `->add_to_import_log()`, `->log_error()` for diagnostics

### Where data is written

- Posts → the WordPress DB (normal `wp_insert_post`).
- Media dedupe map → `uploads/wp-scraper-importer/media-map.sqlite` (or `.csv` if `pdo_sqlite` is unavailable).
- Scrape progress → `uploads/scraper_progress.json` (resumable; clear with `--reset-progress`).

## How it SHOULD work — conventions

- **Configure in code, by class name.** `WP_Scraper::set_url_provider(My::class)` / `set_content_mapper(My::class)`. Providers are instantiated with **no constructor arguments** — anything a provider needs goes inside the class (constants/properties) and is opened in `setup()`, closed in `teardown()`. **No side effects in constructors** (this applies across the codebase — e.g. `Media_Store` lazy-inits in `boot()` on first use, not in `__construct`).
- **Extend, don't hand-edit.** A clone registers its own provider and a `Default_Content_Mapper` subclass via `WP_Scraper` — it should not edit the base classes in place.
- **Fallbacks are fallbacks.** `default_user`/`post_type`/`post_status` only fill in when the mapper returns empty/`0`; the mapper is authoritative.
- **Media is deduped.** Always go through `Media::from_url()`; never re-upload. The store auto-selects SQLite (`pdo_sqlite`) or CSV.
- **No JS/CSS build.** This plugin is PHP-only. `_blocks/`, `assets/`, `@wordpress/scripts`, postcss, etc. were intentionally removed. `package.json` exists **only** for `@wordpress/env` (tests). Don't reintroduce front-end build tooling.

## Gotchas (things that have already bitten us)

- **`WP_HTML_Tag_Processor` can't read inner text.** It stops only on tags, so `get_modifiable_text()` on `<h1>` returns `''`. Use `DOMDocument`→`textContent`, or `WP_HTML_Processor` with a `next_token()` loop accumulating `#text` (see `tests/Support/Sample_Content_Mapper.php`). It works for `<title>` only because that's RCDATA.
- **wp-browser needs `pdo_mysql`**, which the wp-env image lacks → "could not find driver". Installed automatically by `bin/wp-env-after-start.sh`.
- **More than one wp-env instance can exist on a host** (CI matrices, other checkouts), so `docker ps | grep tests-wordpress` can match several containers. `bin/wp-env-after-start.sh` targets *this* project's container by the wp-env instance hash (`wp-env install-path`), never a bare grep.
- **Pinned ports.** `.wp-env.json` uses `57897`/`57898` to avoid the default 8888/8889 clashing with other instances.

## Tests

Codeception via wp-browser, provisioned by wp-env.

```bash
npm install
npm run wp-env:start            # afterStart installs pdo_mysql into our instance
npm run tests:run:integration   # Integration suite (in-process WPLoader)
```

- Integration tests mock HTTP with the `pre_http_request` filter — no live network.
- `tests/Support/mock-plugin.php` is the "set it up as expected" harness: registers `Test_URL_Provider` + `Sample_Content_Mapper` on `WP_Scraper` and mocks the page response, used by `tests/Integration/Import_E2E_Test.php`.
- The integration suite must load the plugin via `plugins: ['./plugin.php']` in `tests/Integration.suite.yml` (not the old scaffold filename).
- A true CLI e2e (running the actual `wp scraper-to-wp` via wp-browser's WPCLI module) is not yet wired; the current e2e drives the pipeline at the PHP level.

## Working rules

- When refactoring, **comment out first, confirm green, then delete** on cleanup.
- Don't reintroduce removed scaffold cruft (JS/CSS, `includes/`, `models/`, `templates/`, the block build).
- `composer`, `npm`, and `wp` run inside the wp-env containers, not on the host.
