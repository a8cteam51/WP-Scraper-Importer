# WordPress Content Scraper & Importer

A flexible WordPress plugin for scraping content from external websites and importing it as WordPress posts. This tool provides a robust foundation for building custom content importers.

**Created by:** Glynn Quelch & WordPress Special Projects Team

---

## ⚠️ Important Notice

**This plugin is a starting point and will NOT work out of the box.** It requires customization for your specific scraping needs. Each website has different HTML structures, content layouts, and data extraction requirements.

---

## 🚀 What This Plugin Can Do

- **Scrape content** from any publicly accessible website
- **Extract titles, content, authors, and metadata** from HTML pages
- **Import content** as WordPress posts, pages, or custom post types
- **Handle bulk imports** with progress tracking and error handling
- **Respect server limits** with configurable delays between requests
- **Resume interrupted imports** with built-in progress tracking
- **Dry-run mode** for testing before actual import
- **Extensible architecture** for custom content mapping
- **WP-CLI integration** for command-line batch processing

---

## 📋 Requirements

- WordPress 6.7+
- PHP 8.3+
- WP-CLI (for running import commands)
- Composer (for dependency management)

---

## 🛠️ Installation & Setup

### 1. Install Dependencies

```bash
# Navigate to the plugin directory
cd wp-content/plugins/WP-Scraper-Importer

# Install PHP dependencies
composer install
```

> **Note:** NPM is not required for this plugin.

### 2. Activate the Plugin

Activate the plugin through the WordPress admin or via WP-CLI:

```bash
wp plugin activate WP-Scraper-Importer
```

### 3. Prepare Your URL List

Create a CSV file containing the URLs you want to scrape:

```csv
https://example.com/article-1
https://example.com/article-2
https://example.com/article-3
```

Save this file (e.g., as `data/urls.csv` in the plugin directory).

---

## 📖 Usage

### WP-CLI Commands

The plugin provides a single WP-CLI command with various options:

```bash
wp scrapper-to-wp [URL...] [--options]
```

#### Basic Usage

```bash
# Import from CSV file (default: data/urls.csv)
wp scrapper-to-wp --url-list=/path/to/urls.csv

# Dry run to test without importing
wp scrapper-to-wp --dry-run --url-list=/path/to/urls.csv
```

#### Command Options

| Option | Description | Default |
|--------|-------------|---------|
| `--dry-run` | Test mode - shows what would be imported without creating posts | `false` |
| `--delay=<seconds>` | Delay between requests (be respectful to target servers) | `30` |
| `--per=<number>` | Number of URLs to process per batch | `25` |
| `--url-list=<path>` | Path to CSV file containing URLs | `data/urls.csv` |
| `--silent` | Run without output (useful for cron jobs) | `false` |
| `--reset-progress` | Clear progress tracker and start fresh | `false` |

#### Examples

```bash
# Respectful import with longer delays
wp scrapper-to-wp --delay=60 --per=10 --url-list=urls.csv

# Quick test run
wp scrapper-to-wp --dry-run --delay=5 --per=5 --url-list=test-urls.csv

# Silent mode for automation
wp scrapper-to-wp --silent --url-list=urls.csv

# Reset and restart import
wp scrapper-to-wp --reset-progress --url-list=urls.csv
```

---

## 🔧 Customization (Required!)

### Why Customization is Needed

Every website is different:
- **HTML structures** vary (some use `<main>`, others use `<article>` or `<div class="content">`)
- **Content selectors** are site-specific
- **Metadata extraction** requires different approaches
- **Post configuration** depends on your WordPress setup

### ⚠️ You MUST Edit the Default Content Mapper

**The `src/Mapper/Default_Content_Mapper.php` file is essentially useless as-is and MUST be modified for your specific scraping needs.**

This file contains placeholder code that won't work with real websites. You need to:

1. **Open `src/Mapper/Default_Content_Mapper.php`**
2. **Read the extensive comments and examples**
3. **Replace the placeholder code with your specific logic**
4. **Test with `--dry-run` until it works correctly**

### Key Methods You Must Customize

#### 1. `get_content()` - Extract Main Content
```php
// BEFORE (won't work on most sites):
$body = $dom->getElementsByTagName( 'main' )->item( 0 );

// AFTER (customize for your target site):
$body = $dom->getElementsByClassName('article-content')[0];
// OR
$body = $dom->getElementById('post-content');
// OR use XPath for complex selectors
$xpath = new \DOMXPath($dom);
$body = $xpath->query('//div[@class="content-area"]//article')->item(0);
```

#### 2. `get_title()` - Extract Page Title
```php
// BEFORE (basic title tag extraction):
// Works for some sites but may include site name

// AFTER (customize extraction):
// Extract from H1 instead
$processor = new \WP_HTML_Tag_Processor($this->content_scrapper->get_content());
if ($processor->next_tag('h1')) {
    return trim($processor->get_modifiable_text());
}

// OR extract from Open Graph meta
if (preg_match('/<meta property="og:title" content="([^"]+)"/', $content, $matches)) {
    return $matches[1];
}
```

#### 3. `get_author()` - Extract Author Information
```php
// BEFORE (always returns admin user):
return 1;

// AFTER (extract real author):
if (preg_match('/<meta name="author" content="([^"]+)"/', $content, $matches)) {
    $author_name = $matches[1];
    $user = get_user_by('display_name', $author_name);
    return $user ? $user->ID : 1;
}
```

#### 4. `get_terms()` - Extract Categories/Tags
```php
// BEFORE (returns empty arrays):
return array('tag' => array(), 'category' => array());

// AFTER (extract real categories):
$categories = [];
$xpath = new \DOMXPath($dom);
$cat_nodes = $xpath->query('//div[@class="categories"]//a');
foreach ($cat_nodes as $node) {
    $categories[] = trim($node->textContent);
}
return ['category' => $categories, 'post_tag' => []];
```

### Real-World Customization Process

1. **Inspect the target website's HTML structure**
2. **Find the CSS selectors for content, title, author, etc.**
3. **Edit each method in `Default_Content_Mapper.php`**
4. **Test with a single URL using `--dry-run`**
5. **Refine until the extraction is accurate**
6. **Run full import**

**Example for a typical blog:**
```php
// If scraping a WordPress blog with standard structure
public function get_content(): string {
    if ($this->content_scrapper->is_scraped()) {
        $dom = new \DOMDocument();
        $dom->loadHTML($this->content_scrapper->get_content());
        
        // Try common WordPress content selectors
        $selectors = ['.entry-content', '.post-content', 'article .content'];
        foreach ($selectors as $selector) {
            // Use your preferred method to find elements by class
            $xpath = new \DOMXPath($dom);
            $body = $xpath->query("//*[contains(@class, '" . trim($selector, '.') . "')]")->item(0);
            if ($body) {
                return trim($dom->saveHTML($body));
            }
        }
    }
    return '';
}
```

### Advanced: Creating Multiple Mappers (Optional)

For complex projects with multiple source sites, you can create additional mappers that extend the base class:

```php
// src/Mapper/News_Site_Mapper.php
class News_Site_Mapper extends Default_Content_Mapper {
    public function get_content(): string {
        // Custom logic for news site structure
        $dom = new \DOMDocument();
        $dom->loadHTML($this->content_scrapper->get_content());
        $article = $dom->getElementsByClassName('news-article')[0];
        return $dom->saveHTML($article);
    }
    
    public function get_post_type(): string {
        return 'news_article'; // Custom post type
    }
}
```

Then use it in the Import Command:

```php
// In process_as_import method
if (strpos($content_scrapper->get_url(), 'news-site.com') !== false) {
    $mapper = new News_Site_Mapper($content_scrapper);
} else {
    $mapper = new Default_Content_Mapper($content_scrapper);
}
```

**But for most users: just edit `Default_Content_Mapper.php` directly!**

---

## 🏗️ Architecture

### Plugin Structure

```
WP-Scraper-Importer/
├── src/
│   ├── Command/
│   │   └── Import_Command.php      # WP-CLI command handler
│   ├── Action/
│   │   ├── Content_Scrapper.php    # Handles URL fetching
│   │   └── Post_Inserter.php       # Handles WordPress post creation
│   ├── Mapper/
│   │   ├── Abstract_Content_Mapper.php    # Base class for content mapping
│   │   └── Default_Content_Mapper.php     # Default implementation (customize this!)
│   └── Service/
│       └── Progress_Tracker.php    # Tracks import progress
├── data/
│   └── urls.csv                    # Your URL list (create this)
└── plugin.php                     # Main plugin file
```

### Flow Overview

1. **Content_Scrapper** fetches HTML from URLs
2. **Content_Mapper** extracts title, content, meta data
3. **Post_Inserter** creates WordPress posts
4. **Progress_Tracker** saves state for resuming

---

## 🎯 Common Use Cases

### Blog Migration
```php
// Extract from WordPress exports
$body = $dom->getElementsByClassName('entry-content')[0];
```

### E-commerce Product Import
```php
public function get_post_type(): string {
    return 'product';
}

public function get_post_meta(): array {
    return [
        '_regular_price' => $this->extract_price(),
        '_stock_status' => 'instock'
    ];
}
```

### News Aggregation
```php
public function get_terms(): array {
    $url = $this->content_scrapper->get_url();
    if (strpos($url, '/sports/') !== false) {
        return ['category' => ['Sports']];
    }
    return ['category' => ['General News']];
}
```

---

## ⚡ Performance & Best Practices

### Server Respect
- **Always use delays** between requests (`--delay=30` or higher)
- **Process in small batches** (`--per=25` or less)
- **Test with dry-run** first
- **Check robots.txt** before scraping

### WordPress Performance
- **Use object caching** for large imports
- **Consider post status** (`draft` for review, `publish` for live)
- **Handle duplicates** properly
- **Clean up after errors**

### Error Handling
- **Monitor the logs** for import errors
- **Use progress tracking** for resumable imports
- **Test thoroughly** with dry-run mode

---

## 🐛 Troubleshooting

### Common Issues

**"The content mapper returns empty content"**
- Check the HTML selector in `get_content()` method
- Inspect the source website's HTML structure
- Try different selectors (`main`, `article`, `.content`, etc.)

**"Import creates posts but they're empty"**
- Verify the content extraction logic
- Check if the target site requires JavaScript rendering
- Test with `--dry-run` to see what's being extracted

**"Too many server errors"**
- Increase the `--delay` between requests
- Reduce the `--per` batch size
- Check if the site blocks automated requests

**"Progress tracker errors"**
- Ensure uploads directory is writable
- Check file permissions
- Use `--reset-progress` to clear corrupted state

---

## 🤝 Contributing

This plugin is designed to be extended and customized. Common improvements:

- Additional content mappers for popular sites
- Better error handling and retry logic
- Support for JavaScript-rendered content
- Integration with popular page builders
- Custom field mapping for ACF/Meta Box

---

## 📄 License

GPL v3 or later - same as WordPress core.

---

## 📞 Support

This is a development tool requiring customization. For implementation help:

- Review the extensive comments in `Default_Content_Mapper.php`
- Check the examples in `Import_Command.php`
- Test thoroughly with `--dry-run` before live imports
- Always respect target website terms of service

**Created by the WordPress Special Projects Team**
