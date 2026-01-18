# Statamic Backlinks

Obsidian-style backlinks for Statamic's Bard and Markdown fields. Create wiki-links between entries, track which pages link to each other, and create new pages from missing links.

Learn more about [Obsidian backlinks](https://help.obsidian.md/plugins/backlinks).

## Features

- **Wiki-link syntax**: Use `[[Page Title]]` or `[[Page Title|Display Text]]` in your content
- **Backlink tracking**: Automatically tracks which entries link to each other
- **Missing link detection**: Visually distinguish links to non-existent pages
- **Page creation**: Create new entries directly from missing wiki-links
- **Dashboard widget**: See backlink activity at a glance
- **Entry sidebar**: View backlinks when editing an entry
- **Antlers tag**: Display backlinks in your templates

## Installation

```bash
composer require chrisvasey/statamic-backlinks
```

## Usage

### Wiki-Link Syntax

Use double-bracket syntax in your content fields:

```
Check out [[Getting Started]] for the basics.

You might also like [[Advanced Topics|our advanced guide]].
```

### Rendering Wiki-Links

Use the `wiki_links` modifier to render wiki-links as HTML:

```antlers
{{ content | wiki_links }}
```

This transforms:
- `[[Existing Page]]` → `<a href="/existing-page" class="wiki-link">Existing Page</a>`
- `[[Missing Page]]` → `<span class="wiki-link-missing" data-title="Missing Page">Missing Page</span>`

### Displaying Backlinks

Use the `backlinks` tag to show pages that link to the current entry:

```antlers
{{ backlinks }}
    <a href="{{ url }}">{{ title }}</a>
{{ /backlinks }}

{{# Or just get the count #}}
{{ backlinks:count }}

{{# For a specific entry #}}
{{ backlinks from="entry-id-here" }}
    {{ title }}
{{ /backlinks }}
```

### Dashboard Widget

Add the backlinks widget to your dashboard in `config/statamic/cp.php`:

```php
'widgets' => [
    [
        'type' => 'backlinks',
        'title' => 'Recent Backlinks',
        'limit' => 10,
    ],
],
```

### Rebuilding the Index

If you need to rebuild the backlink index (e.g., after importing content):

```bash
php please backlinks:rebuild
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=statamic-backlinks-config
```

### Options

```php
return [
    // Collections to scan for wiki-links
    'scan_collections' => ['*'], // or ['pages', 'blog']

    // Collections that can be linked to
    'linkable_collections' => ['*'],

    // Fields to scan for wiki-links
    'scan_fields' => ['content', 'body', 'text', '*'],

    // Page creation settings
    'creation' => [
        'collection_strategy' => 'same', // 'same' or 'default'
        'default_collection' => 'pages',
        'default_blueprint' => null,
        'default_published' => false,
    ],

    // Index storage
    'storage' => 'file', // 'file' or 'cache'
    'storage_path' => storage_path('statamic/backlinks.json'),

    // CSS classes for rendered links
    'css' => [
        'link' => 'wiki-link',
        'missing' => 'wiki-link-missing',
    ],
];
```

## Styling

The addon includes basic CSS for wiki-links. You can customize the appearance:

```css
.wiki-link {
    color: blue;
    text-decoration: underline dotted;
}

.wiki-link-missing {
    color: red;
    text-decoration: underline dashed;
    cursor: pointer;
}
```

## Development

### Building Assets

```bash
cd addons/chrisvasey/statamic-backlinks
npm install
npm run build
```

For development with hot reloading:

```bash
npm run dev
```

### Running Tests

```bash
cd addons/chrisvasey/statamic-backlinks
./vendor/bin/phpunit
```

## License

MIT License
