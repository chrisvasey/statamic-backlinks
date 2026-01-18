<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Collections to Scan
    |--------------------------------------------------------------------------
    |
    | Define which collections should be scanned for wiki-links. Use ['*']
    | to scan all collections, or specify collection handles like ['pages', 'blog'].
    |
    */

    'scan_collections' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Linkable Collections
    |--------------------------------------------------------------------------
    |
    | Define which collections can be linked to via wiki-links. Use ['*']
    | to allow linking to any collection.
    |
    */

    'linkable_collections' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Fields to Scan
    |--------------------------------------------------------------------------
    |
    | Define which fields should be scanned for wiki-links. Supports wildcards.
    | Common values: 'content', 'body', 'text', or ['*'] for all fields.
    |
    */

    'scan_fields' => ['content', 'body', 'text', '*'],

    /*
    |--------------------------------------------------------------------------
    | Page Creation Settings
    |--------------------------------------------------------------------------
    |
    | Configure how new pages are created when clicking on missing wiki-links.
    |
    */

    'creation' => [
        // 'same' = same collection as source, 'default' = use default_collection
        'collection_strategy' => 'same',

        // Default collection when strategy is 'default'
        'default_collection' => 'pages',

        // Blueprint to use for new entries (null = collection's default)
        'default_blueprint' => null,

        // Whether new entries should be published by default
        'default_published' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the backlink index is stored.
    | Options: 'file' or 'cache'
    |
    */

    'storage' => 'file',

    'storage_path' => storage_path('statamic/backlinks.json'),

    /*
    |--------------------------------------------------------------------------
    | CSS Classes
    |--------------------------------------------------------------------------
    |
    | CSS classes applied to rendered wiki-links.
    |
    */

    'css' => [
        'link' => 'wiki-link',
        'missing' => 'wiki-link-missing',
    ],

];
