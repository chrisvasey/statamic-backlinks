<?php

namespace Chrisvasey\StatamicBacklinks;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class WikiLinkParser
{
    /** @param callable(DOMDocument, string, string): DOMNode $replacement */
    public function transform(string $html, callable $replacement): string
    {
        if (! str_contains($html, '[[')) {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div id="backlinks-fragment">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->getElementById('backlinks-fragment');
        $groups = [];
        $nodes = [];
        $flush = function () use (&$groups, &$nodes): void {
            if ($nodes) {
                $groups[] = $nodes;
                $nodes = [];
            }
        };
        $walk = function (DOMNode $node) use (&$walk, &$nodes, $flush): void {
            if ($node instanceof DOMText) {
                $nodes[] = $node;

                return;
            }
            if (! $node instanceof DOMElement || in_array(strtolower($node->tagName), ['a', 'code', 'pre', 'script', 'style', 'textarea', 'button', 'svg'])) {
                $flush();

                return;
            }
            $inline = in_array(strtolower($node->tagName), ['span', 'em', 'strong', 'b', 'i', 'u', 's', 'small', 'mark', 'sub', 'sup']);
            if (! $inline) {
                $flush();
            }
            foreach ($node->childNodes as $child) {
                $walk($child);
            }
            if (! $inline) {
                $flush();
            }
        };
        $walk($root);
        $flush();

        foreach ($groups as $group) {
            $text = implode('', array_map(fn (DOMText $node): string => $node->data, $group));
            preg_match_all('/\[\[([^\]\|\n]+)(?:\|([^\]\n]+))?\]\]/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach (array_reverse($matches) as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);
                $title = trim($match[1][0]);
                $display = isset($match[2]) ? trim($match[2][0]) : $title;
                $link = $replacement($document, $title, $display);
                $offset = 0;
                $inserted = false;
                foreach ($group as $node) {
                    $length = strlen($node->data);
                    $nodeEnd = $offset + $length;
                    if ($nodeEnd > $start && $offset < $end) {
                        $before = substr($node->data, 0, max(0, $start - $offset));
                        $after = substr($node->data, min($length, $end - $offset));
                        $node->data = $before;
                        if (! $inserted) {
                            $node->parentNode->insertBefore($link, $node->nextSibling);
                            $inserted = true;
                            if ($after !== '') {
                                $node->parentNode->insertBefore($document->createTextNode($after), $link->nextSibling);
                            }
                        } else {
                            $node->data .= $after;
                        }
                    }
                    $offset = $nodeEnd;
                }
            }
        }

        return implode('', array_map(fn (DOMNode $node): string => $document->saveHTML($node), iterator_to_array($root->childNodes)));
    }
}
