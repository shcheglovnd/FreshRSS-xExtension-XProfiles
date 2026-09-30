<?php

declare(strict_types=1);

/**
 * Renders the data read by {@see XProfilesPage} as an RSS 2.0 document.
 */
final class XProfilesRss {
	/**
	 * @param array{title:string,description:string,image:string,link:string,
	 *   posts:list<array{link:string,title:string,author:string,date:int,html:string,image:string}>} $channel
	 */
	public static function build(array $channel): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"'
			. ' xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
			. "<channel>\n"
			. self::element('title', $channel['title'])
			. self::element('link', $channel['link'])
			. self::element('description', $channel['description']);
		if ($channel['image'] !== '') {
			$xml .= self::element('atom:icon', $channel['image']);   // FreshRSS uses it as the feed icon
			$xml .= '<image>' . self::element('url', $channel['image']) . self::element('title', $channel['title'])
				. self::element('link', $channel['link']) . "</image>\n";
		}
		foreach ($channel['posts'] as $post) {   // newest first
			$xml .= "<item>\n"
				. self::element('title', $post['title'])
				. self::element('link', $post['link'])
				. '<guid isPermaLink="true">' . self::escape($post['link']) . "</guid>\n"
				. ($post['date'] > 0 ? self::element('pubDate', gmdate(DATE_RSS, $post['date'])) : '')
				. ($post['author'] !== '' ? self::element('dc:creator', $post['author']) : '')
				. '<description><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', self::xmlChars($post['html'])) . "]]></description>\n"
				. ($post['image'] !== '' ? '<media:thumbnail url="' . self::escape($post['image']) . '" />' . "\n" : '')
				. "</item>\n";
		}
		return $xml . "</channel>\n</rss>\n";
	}

	private static function element(string $name, string $value): string {
		return '<' . $name . '>' . self::escape($value) . '</' . $name . ">\n";
	}

	private static function escape(string $text): string {
		return htmlspecialchars(self::xmlChars($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/** Drops characters XML 1.0 does not allow (control characters that sometimes appear in posts). */
	private static function xmlChars(string $text): string {
		return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';
	}
}
