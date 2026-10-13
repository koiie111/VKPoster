<?php

declare(strict_types=1);

namespace App\Domain\Automation\Rss;

use SimpleXMLElement;

/**
 * Reads RSS 2.0 and Atom 1.0 into `FeedItem`s. Feeds come from the internet, so the XML is treated as hostile: documents with a
 * DOCTYPE or entity declarations are refused (no entity expansion, no external files), network access is off, and only http(s)
 * links and image addresses are kept. HTML in summaries becomes plain text.
 */
final class FeedParser
{
    public const MAX_ITEMS = 50;
    public const MAX_SUMMARY = 2000;

    /**
     * @return list<FeedItem> newest first as the feed lists them
     * @throws FeedException when the document is not a readable feed
     */
    public static function parse(string $xml): array
    {
        $xml = ltrim($xml, "\xEF\xBB\xBF \t\r\n");
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new FeedException('Это не лента RSS или Atom.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$root instanceof SimpleXMLElement) {
            throw new FeedException('Не удалось прочитать ленту: это не RSS и не Atom.');
        }
        $items = [];
        $name = strtolower($root->getName());
        if ($name === 'rss' || $name === 'rdf') {
            $nodes = $name === 'rss' ? $root->channel->item : $root->item;
            foreach ($nodes as $node) {
                $items[] = self::rssItem($node);
            }
        } elseif ($name === 'feed') {
            foreach ($root->entry as $node) {
                $items[] = self::atomEntry($node);
            }
        } else {
            throw new FeedException('Это не лента RSS или Atom.');
        }

        return array_slice(array_values(array_filter($items, static fn (?FeedItem $i): bool => $i !== null)), 0, self::MAX_ITEMS);
    }

    private static function rssItem(SimpleXMLElement $node): ?FeedItem
    {
        $title = self::text((string) $node->title);
        $link = self::url((string) $node->link) ?? '';
        $html = (string) $node->description;
        $ns = $node->children('http://purl.org/rss/1.0/modules/content/');
        $content = (string) ($ns->encoded ?? '');
        $guid = trim((string) $node->guid);
        $image = null;
        foreach ($node->enclosure ?? [] as $enclosure) {
            if (str_starts_with(strtolower((string) $enclosure['type']), 'image/')) {
                $image = self::url((string) $enclosure['url']);
                break;
            }
        }
        $media = $node->children('http://search.yahoo.com/mrss/');
        if ($image === null) {
            foreach (['content', 'thumbnail'] as $tag) {
                foreach ($media->{$tag} ?? [] as $m) {
                    $type = strtolower((string) $m['type']);
                    if ($type === '' || str_starts_with($type, 'image/')) {
                        $image = self::url((string) $m['url']);
                        if ($image !== null) {
                            break 2;
                        }
                    }
                }
            }
        }

        return self::build($guid, $title, $link, $html !== '' ? $html : $content, $image ?? self::firstImage($content . $html));
    }

    private static function atomEntry(SimpleXMLElement $node): ?FeedItem
    {
        $link = null;
        $image = null;
        foreach ($node->link as $l) {
            $rel = (string) $l['rel'];
            $href = self::url((string) $l['href']);
            if ($href === null) {
                continue;
            }
            if (($rel === '' || $rel === 'alternate') && $link === null) {
                $link = $href;
            } elseif ($rel === 'enclosure' && str_starts_with(strtolower((string) $l['type']), 'image/') && $image === null) {
                $image = $href;
            }
        }
        $html = (string) $node->summary !== '' ? (string) $node->summary : (string) $node->content;

        return self::build(trim((string) $node->id), self::text((string) $node->title), $link ?? '', $html, $image ?? self::firstImage((string) $node->content . (string) $node->summary));
    }

    private static function build(string $guid, string $title, string $link, string $html, ?string $image): ?FeedItem
    {
        $summary = self::text($html);
        if ($guid === '') {
            $guid = $link !== '' ? $link : $title;
        }
        if ($guid === '' || ($title === '' && $summary === '' && $link === '')) {
            return null;
        }

        return new FeedItem(mb_substr($guid, 0, 500), mb_substr($title, 0, 500), mb_substr($link, 0, 2000), mb_substr($summary, 0, self::MAX_SUMMARY), $image);
    }

    private static function url(string $value): ?string
    {
        $value = trim($value);

        return preg_match('~^https?://[^\s<>"\']+$~i', $value) === 1 && strlen($value) <= 2000 ? $value : null;
    }

    private static function firstImage(string $html): ?string
    {
        if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $m) === 1) {
            return self::url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    /**
     * HTML (or text) to plain text: block tags become line breaks, the rest of the tags go, entities are decoded.
     */
    public static function text(string $html): string
    {
        $html = preg_replace('~<\s*br\s*/?>|</\s*(p|div|li|h[1-6])\s*>~i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\xC2\xA0]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/ ?\n ?/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
