# WordPress Content Scraper & Importer

A flexible base plugin for scraping content from external websites and importing it as WordPress posts. It is a **scaffold you clone and build on** — out of the box it does nothing; you configure it in code for the site you're importing.

**Created by:** Glynn Quelch & WordPress Special Projects Team

---

## ⚠️ Important Notice

**This plugin does nothing on its own.** With no configuration it uses a no-op URL provider (zero URLs) and the default content mapper (placeholder extraction). You make it work by registering your own **URL provider** and **content mapper** through the `WP_Scraper` config facade — all in code, no admin UI.

---

## 📋 Requirements

- WordPress 6.9+ (tested up to 7.0)
- PHP 8.3+
- WP-CLI (the importer is a WP-CLI command)
- Composer (PHP dependencies)
- Node + Docker (only for running the test suite via `wp-env`)

---

## 🏗️ How it works

The pipeline, end to end:

1. **`WP_Scraper`** (`src/WP_Scraper.php`) — a static config facade. A clone registers everything here, in code.
2. **URL provider** (`src/Provider/URL_Provider.php`) — supplies the list of URLs to import. Default is `Noop_URL_Provider` (nothing). `CSV_URL_Provider` is a ready example.
3. **`Content_Scrapper`** (`src/Action/Content_Scrapper.php`) — fetches a URL (following redirects) and exposes the raw HTML.
4. **Content mapper** (`src/Mapper/`) — turns the scraped HTML into post fields (title, content, author, date, terms, meta…). Default is `Default_Content_Mapper`; register your own subclass.
5. **`Post_Inserter`** (`src/Action/Post_Inserter.php`) — creates/updates the WordPress post (supports `post_date`, returns the new ID via `get_post_id()`).
6. **`Media` + `Media_Store`** (`src/Service/`) — `Media::from_url()` imports an image and **dedupes** it: the URL→attachment-ID map is persisted (SQLite if `pdo_sqlite` is available, else CSV) under `uploads/wp-scraper-importer/`, so the same image is never re-imported.
7. **`Progress_Tracker`** (`src/Service/Progress_Tracker.php`) — records scraped URLs so imports can resume.
8. **`Import_Command`** (`src/Command/Import_Command.php`) — the `wp scrapper-to-wp` command that drives all of the above.

---

## 🚀 How to use it (clone & configure)

### 1. Install dependencies

```bash
cd wp-content/plugins/WP-Scraper-Importer
composer install
```

### 2. Write a URL provider

Add a class under `src/Provider/` in the plugin's namespace (PSR-4 autoloaded) implementing `URL_Provider`. Everything it needs (a file path, a DB query, an API endpoint) lives **inside the class** — it is registered by class name, so it must work with no constructor arguments. Use `setup()` / `teardown()` for any resources.

```php
// src/Provider/My_URL_Provider.php
namespace A8C\SpecialProjects\ScrapperToWP\Provider;

class My_URL_Provider implements URL_Provider {
    public function setup(): void {}
    public function get_urls( int $limit = 0, int $offset = 0, int $per_page = 0, ?callable $filter = null ): array {
        return [ 'https://example.com/a', 'https://example.com/b' ];
    }
    public function teardown(): void {}
}
```

Or just point the bundled `CSV_URL_Provider` at a CSV (column A = URL) by subclassing and overriding the `FILE_PATH` constant:

```php
class My_CSV_Provider extends \A8C\SpecialProjects\ScrapperToWP\Provider\CSV_URL_Provider {
    const FILE_PATH = __DIR__ . '/urls.csv';
}
```

### 3. Write a content mapper

Add a class under `src/Mapper/` in the plugin's namespace. Extend `Default_Content_Mapper` and override only what you need. Read the raw HTML with `$this->content_scrapper->get_content()`.

```php
// src/Mapper/My_Mapper.php
namespace A8C\SpecialProjects\ScrapperToWP\Mapper;

class My_Mapper extends Default_Content_Mapper {
    public function get_post_type(): string { return 'article'; }
    public function get_post_status(): string { return 'draft'; }
}
```

### 4. Register everything via `WP_Scraper`

In `plugin.php`, right after the `vendor/autoload.php` require (so the autoloader is live), add the `use` lines at the top and these calls after the require:

```php
use A8C\SpecialProjects\ScrapperToWP\WP_Scraper;
use A8C\SpecialProjects\ScrapperToWP\Provider\My_URL_Provider;
use A8C\SpecialProjects\ScrapperToWP\Mapper\My_Mapper;

WP_Scraper::set_url_provider( My_URL_Provider::class );   // a CLASS NAME — required
WP_Scraper::set_content_mapper( My_Mapper::class );        // optional; defaults to Default_Content_Mapper
WP_Scraper::set_default_user( 5 );                         // fallback author when the mapper returns 0
WP_Scraper::set_post_type( 'post' );                       // fallback when the mapper returns ''
WP_Scraper::set_post_status( 'publish' );                  // fallback when the mapper returns ''
WP_Scraper::set_batch_size( 25 );                          // default for --per
```

### 5. Run the import

```bash
wp scrapper-to-wp
```

---

## ⚙️ `WP_Scraper` configuration reference

| Setter | Getter | Default | Purpose |
|--------|--------|---------|---------|
| `set_url_provider( string $class )` | `get_url_provider(): URL_Provider` | `Noop_URL_Provider` | The URL source (by class name). |
| `set_content_mapper( string $class )` | `get_content_mapper( Content_Scrapper ): Abstract_Content_Mapper` | `Default_Content_Mapper` | The HTML→post mapper (by class name). |
| `set_default_user( int )` | `get_default_user(): int` | `0` | Fallback author when the mapper supplies none. |
| `set_post_type( string )` | `get_post_type(): string` | `post` | Fallback post type. |
| `set_post_status( string )` | `get_post_status(): string` | `publish` | Fallback post status. |
| `set_batch_size( int )` | `get_batch_size(): int` | `25` | Default batch size (`--per`). |

The `default_user` / `post_type` / `post_status` values are applied **only as fallbacks** in `Import_Command`, when the mapper returns nothing — the mapper always wins.

---

## 📖 WP-CLI options

```bash
wp scrapper-to-wp [--dry-run] [--delay=<seconds>] [--per=<number>] [--silent] [--reset-progress]
```

| Option | Description | Default |
|--------|-------------|---------|
| `--dry-run` | Show what would be imported without creating posts | `false` |
| `--delay=<seconds>` | Delay between requests | `30` |
| `--per=<number>` | URLs processed per batch | `WP_Scraper::get_batch_size()` (25) |
| `--silent` | Suppress output (cron) | `false` |
| `--reset-progress` | Clear the progress tracker | `false` |

> URLs are **not** passed on the CLI — they come from the registered URL provider (set in code).

---

## 🔧 Customising the content mapper

Override the methods you need on your mapper subclass. To read an element's text you can use **DOMDocument** or the **WP HTML API**; the `Default_Content_Mapper` uses `DOMDocument` for content and `WP_HTML_Tag_Processor` for the `<title>`.

**Title from the `<h1>` (DOMDocument):**
```php
public function get_title(): string {
    libxml_use_internal_errors( true );
    $dom = new \DOMDocument();
    $dom->loadHTML( $this->content_scrapper->get_content() );
    libxml_clear_errors();
    $h1 = $dom->getElementsByTagName( 'h1' )->item( 0 );
    return $h1 ? trim( $h1->textContent ) : parent::get_title();
}
```

> ⚠️ Note: `WP_HTML_Tag_Processor` only stops on **tags**, not text — `get_modifiable_text()` returns `''` for a normal element like `<h1>`. To read inner text with the WP HTML API use `WP_HTML_Processor` and accumulate `#text` tokens between the open/close tags (see `tests/Support/Sample_Content_Mapper.php` for a worked example), or use `DOMDocument` as above.

**Preserve the original publish date** — override `get_post_date()` (return `''` to let WordPress use the current time):
```php
public function get_post_date(): string {
    // parse a date out of the scraped markup…
    return '2024-01-31 12:00:00';
}
```

**Featured images / inline media** — call `Media::from_url( $image_url )`; it returns the attachment ID and only downloads each URL once (subsequent calls return the stored ID).

---

## 🧪 Tests

Tests run against WordPress provisioned by [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env). The integration suite (Codeception + wp-browser) loads WordPress in-process and mocks the network via the `pre_http_request` filter, so no live sites are hit.

```bash
npm install              # installs wp-env (no JS build tooling)
npm run wp-env:start     # starts WP on a pinned port; afterStart installs pdo_mysql
npm run tests:run:integration
```

Notes:
- **Pinned ports** — `.wp-env.json` uses `port: 57897` / `testsPort: 57898` (not the default 8888/8889) so multiple projects can run wp-env at once.
- **pdo_mysql** — wp-browser connects via PDO, which the wp-env image lacks. `bin/wp-env-after-start.sh` (wired as the `afterStart` lifecycle script) installs it into *this project's* `tests-wordpress` container, resolved by the wp-env instance hash so it can't target another project's container.
- The suite covers: scraper + redirects, default & custom mappers, `WP_Scraper` resolution, `Media_Store` persistence + `Media::from_url` dedup, and a full end-to-end import (`tests/Integration/Import_E2E_Test.php`) driven by the `tests/Support/mock-plugin.php` harness.

---

## 📁 Structure

```
WP-Scraper-Importer/
├── src/
│   ├── WP_Scraper.php               # config facade (providers, mapper, defaults)
│   ├── Command/Import_Command.php   # `wp scrapper-to-wp`
│   ├── Provider/
│   │   ├── URL_Provider.php         # interface (setup/get_urls/teardown)
│   │   ├── Noop_URL_Provider.php    # default — returns nothing
│   │   └── CSV_URL_Provider.php     # example — reads column A of a CSV
│   ├── Action/
│   │   ├── Content_Scrapper.php     # fetch + follow redirects
│   │   └── Post_Inserter.php        # create/update posts (post_date, get_post_id)
│   ├── Mapper/
│   │   ├── Abstract_Content_Mapper.php
│   │   └── Default_Content_Mapper.php
│   └── Service/
│       ├── Media.php                # from_url(): import + dedupe
│       ├── Media_Store.php          # URL→ID store (SQLite or CSV)
│       └── Progress_Tracker.php     # resumable progress
├── bin/wp-env-after-start.sh        # installs pdo_mysql into our wp-env container
├── tests/                           # Codeception (Integration + EndToEnd suites)
├── data/urls.csv                    # sample URL list (used by CSV_URL_Provider)
└── plugin.php                       # main plugin file
```

---

## ⚡ Best practices

- Be polite to target servers: keep `--delay` high and `--per` low.
- Test with `--dry-run` first; check `robots.txt` and terms of service.
- Prefer `draft` status while validating extraction, then switch to `publish`.
- Ensure the uploads directory is writable (Progress_Tracker + Media_Store write there).

---

## 📄 License

GPL-2.0-or-later — same as WordPress core.
