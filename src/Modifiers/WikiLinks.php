<?php

namespace Chrisvasey\StatamicBacklinks\Modifiers;

use Chrisvasey\StatamicBacklinks\BacklinkIndexer;
use Chrisvasey\StatamicBacklinks\WikiLinkParser;
use DOMDocument;
use DOMElement;
use Statamic\Modifiers\Modifier;

class WikiLinks extends Modifier
{
    protected static $handle = 'wiki_links';

    public function index(mixed $value, array $params, array $context): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return app(WikiLinkParser::class)->transform($value, function (DOMDocument $document, string $title, string $display): DOMElement {
            $entry = app(BacklinkIndexer::class)->resolveTitle($title);
            $url = $entry?->status() === 'published' ? $entry->url() : null;
            $element = $document->createElement($url ? 'a' : 'span');
            $element->setAttribute('class', $url ? config('backlinks.css.link', 'wiki-link') : config('backlinks.css.missing', 'wiki-link-missing'));
            $element->setAttribute($url ? 'href' : 'data-title', $url ?: $title);
            $element->appendChild($document->createTextNode($display));

            return $element;
        });
    }
}
