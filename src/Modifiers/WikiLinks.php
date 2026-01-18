<?php

namespace Chrisvasey\StatamicBacklinks\Modifiers;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Statamic\Modifiers\Modifier;

class WikiLinks extends Modifier
{
    protected static $handle = 'wiki_links';

    public function index(mixed $value, array $params, array $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $indexer = app(BacklinkIndexer::class);

        $linkClass = config('backlinks.css.link', 'wiki-link');
        $missingClass = config('backlinks.css.missing', 'wiki-link-missing');

        // Match [[Page Title]] and [[Page Title|Display Text]]
        $pattern = '/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/';

        return preg_replace_callback($pattern, function ($matches) use ($indexer, $linkClass, $missingClass) {
            $title = trim($matches[1]);
            $display = isset($matches[2]) ? trim($matches[2]) : $title;

            $entry = $indexer->resolveTitle($title);

            if ($entry) {
                $url = $entry->url();

                return sprintf(
                    '<a href="%s" class="%s">%s</a>',
                    e($url),
                    e($linkClass),
                    e($display)
                );
            }

            return sprintf(
                '<span class="%s" data-title="%s">%s</span>',
                e($missingClass),
                e($title),
                e($display)
            );
        }, $value);
    }
}
