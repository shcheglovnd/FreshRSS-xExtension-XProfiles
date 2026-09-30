<?php

declare(strict_types=1);

/**
 * Reads the posts of an X (Twitter) profile from its logged-out page, https://x.com/<user>.
 *
 * X renders that page on the server and embeds the GraphQL response for the profile timeline (the
 * latest original posts, currently about five) in a <script>, serialised by seroval as a JavaScript
 * object literal: `($R=>$R[43].next($R[45]={kind:"GraphQLRequestStream.Completed",…}))($R["tsr"])`.
 * This class decodes that literal and turns each post into plain data with ready-to-use HTML.
 *
 * Independent of FreshRSS, so it can be tested on saved pages.
 */
final class XProfilesPage {
	/** Labels used in generated content; replaced with translations by the extension. */
	public const LABELS = [
		'photo' => 'Photo',
		'video' => 'Video',
		'gif' => 'GIF',
		'post' => 'Post',
		'quoting' => 'Quoting',
		'watch' => 'Watch on X',
	];

	/** An X username: 1-15 letters, digits and underscores. */
	public const USER_PATTERN = '[A-Za-z0-9_]{1,15}';

	private const TITLE_MAX = 140;
	private const TITLE_MIN_FIRST_LINE = 20;
	private const MAX_DEPTH = 256;

	/** @var array<string,string> */
	private array $labels;

	private string $src = '';
	private int $pos = 0;
	private int $depth = 0;

	/** @param array<string,string> $labels */
	private function __construct(array $labels) {
		$this->labels = array_merge(self::LABELS, $labels);
	}

	/** @return non-empty-string */
	public static function profileUrl(string $user): string {
		return 'https://x.com/' . $user;
	}

	/**
	 * @param array<string,string> $labels overrides for {@see LABELS}
	 * @return array{title:string,description:string,image:string,link:string,
	 *   posts:list<array{link:string,title:string,author:string,date:int,html:string,image:string}>}|null
	 *   null when the page has no embedded timeline (unknown, suspended or protected account, or X changed the page)
	 */
	public static function parse(string $html, string $user, array $labels = []): ?array {
		return (new self($labels))->read($html, $user);
	}

	/**
	 * @return array{title:string,description:string,image:string,link:string,
	 *   posts:list<array{link:string,title:string,author:string,date:int,html:string,image:string}>}|null
	 */
	private function read(string $html, string $user): ?array {
		$instructions = null;
		foreach (self::scripts($html) as $script) {
			if (!str_contains($script, 'GraphQLRequestStream.Completed')
				|| preg_match('/^\(\$R=>\$R\[\d+\]\.next\(/', $script, $start) !== 1) {
				continue;
			}
			$message = $this->decode($script, strlen($start[0]));
			$instructions = is_array($message) ? self::findTimeline($message) : null;
			if ($instructions !== null) {
				break;
			}
		}
		if ($instructions === null) {
			return null;
		}

		$tweets = [];
		foreach ($instructions as $instruction) {
			if (!is_array($instruction)) {
				continue;
			}
			$entries = is_array($instruction['entries'] ?? null) ? $instruction['entries'] : [];
			if (is_array($instruction['entry'] ?? null)) {   // TimelinePinEntry
				$entries[] = $instruction['entry'];
			}
			foreach ($entries as $entry) {
				$tweet = is_array($entry) ? self::path($entry, ['content', 'content', 'tweet_results', 'result']) : null;
				if (is_array($tweet)) {
					$tweets[] = $tweet;
				}
			}
		}

		$posts = [];
		$name = '';
		$avatar = '';
		foreach ($tweets as $tweet) {
			$post = $this->post($tweet, nested: false);
			if ($post === null || isset($posts[$post['link']])) {
				continue;
			}
			$posts[$post['link']] = $post;
			$author = self::path(self::unwrap($tweet) ?? [], ['core', 'user_results', 'result']);
			if ($name === '' && is_array($author) && strcasecmp(self::str($author, ['core', 'screen_name']), $user) === 0) {
				$name = self::str($author, ['core', 'name']);
				$avatar = str_replace('_normal.', '_400x400.', self::str($author, ['avatar', 'image_url']));
			}
		}
		$posts = array_values($posts);
		usort($posts, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);

		return [
			'title' => ($name !== '' ? $name . ' ' : '') . '(@' . $user . ')',
			'description' => 'Posts by @' . $user . ' on X',
			'image' => $avatar,
			'link' => self::profileUrl($user),
			'posts' => $posts,
		];
	}

	/**
	 * @param array<mixed> $tweet
	 * @return array{link:string,title:string,author:string,date:int,html:string,image:string}|null
	 */
	private function post(array $tweet, bool $nested): ?array {
		$tweet = self::unwrap($tweet);
		if ($tweet === null) {
			return null;
		}
		$id = self::str($tweet, ['rest_id']);
		$screenName = self::str($tweet, ['core', 'user_results', 'result', 'core', 'screen_name']);
		if (preg_match('/^\d+$/', $id) !== 1 || $screenName === '') {
			return null;
		}
		$name = self::str($tweet, ['core', 'user_results', 'result', 'core', 'name']);
		$link = 'https://x.com/' . $screenName . '/status/' . $id;
		$date = self::path($tweet, ['details', 'created_at_ms']);
		$date = is_int($date) || is_float($date) ? (int)($date / 1000) : 0;

		// Long posts carry their full text separately, with their own entities
		$note = self::path($tweet, ['note_tweet', 'note_tweet_results', 'result']);
		if (is_array($note) && self::str($note, ['text']) !== '') {
			$text = self::str($note, ['text']);
			$urls = self::list($note, ['entity_set', 'urls']);
		} else {
			$text = self::str($tweet, ['details', 'full_text']);
			$range = self::path($tweet, ['details', 'display_text_range']);
			if (is_array($range) && is_int($range[0] ?? null) && is_int($range[1] ?? null)) {
				$text = mb_substr($text, $range[0], $range[1] - $range[0]);   // drops the trailing media link
			}
			$urls = self::list($tweet, ['url_entities']);
		}

		$html = '';
		$images = [];
		if ($text !== '') {
			$html .= '<p>' . $this->richText($text, $urls) . '</p>';
		}

		$kinds = [];
		foreach (self::list($tweet, ['media_entities2']) as $media) {
			if (!is_array($media)) {
				continue;
			}
			$type = self::str($media, ['type']);
			$picture = self::str($media, ['media_url_https']);
			if ($type === 'photo' && $picture !== '') {
				$images[] = $picture;
				$html .= '<p><a href="' . self::esc(self::str($media, ['expanded_url']) ?: $link) . '"><img src="' . self::esc($picture . '?name=large') . '" alt="" /></a></p>';
				$kinds[] = 'photo';
			} elseif ($type === 'video' || $type === 'animated_gif') {
				$video = self::bestVideo(self::list($media, ['video_info', 'variants']));
				if ($picture !== '') {
					$images[] = $picture;
				}
				$html .= '<p>' . ($video !== ''
					? '<video controls="controls" preload="none"' . ($type === 'animated_gif' ? ' loop="loop" muted="muted"' : '')
						. ($picture !== '' ? ' poster="' . self::esc($picture) . '"' : '') . ' src="' . self::esc($video) . '"></video><br />'
					: ($picture !== '' ? '<a href="' . self::esc($link) . '"><img src="' . self::esc($picture) . '" alt="" /></a><br />' : ''))
					. '<a href="' . self::esc($link) . '">▶️ ' . self::esc($this->labels[$type === 'video' ? 'video' : 'gif']) . ' — ' . self::esc($this->labels['watch']) . '</a></p>';
				$kinds[] = 'video';
			}
		}

		// Link preview card
		$cardTitle = '';
		$card = self::list($tweet, ['card', 'legacy', 'binding_values']);
		if ($card !== []) {
			$values = [];
			foreach ($card as $binding) {
				if (is_array($binding) && is_string($binding['key'] ?? null)) {
					$values[$binding['key']] = self::str($binding, ['value', 'string_value']) ?: self::str($binding, ['value', 'image_value', 'url']);
				}
			}
			$cardUrl = $values['card_url'] ?? '';
			foreach ($urls as $entity) {
				if (is_array($entity) && self::str($entity, ['url']) === $cardUrl) {
					$cardUrl = self::str($entity, ['expanded_url']) ?: $cardUrl;
				}
			}
			$cardTitle = $values['title'] ?? '';
			$cardImage = $values['photo_image_full_size_large'] ?? $values['summary_photo_image_large'] ?? $values['thumbnail_image_large'] ?? $values['thumbnail_image'] ?? '';
			if ($cardTitle !== '' && $cardUrl !== '') {
				$site = $values['vanity_url'] ?? $values['domain'] ?? '';
				$description = $values['description'] ?? '';
				$html .= '<blockquote>'
					. ($site !== '' ? '<p><strong>' . self::esc($site) . '</strong></p>' : '')
					. '<p><a href="' . self::esc($cardUrl) . '">' . self::esc($cardTitle) . '</a></p>'
					. ($description !== '' ? '<p>' . self::esc($description) . '</p>' : '')
					. ($cardImage !== '' ? '<p><a href="' . self::esc($cardUrl) . '"><img src="' . self::esc($cardImage) . '" alt="" /></a></p>' : '')
					. '</blockquote>';
				if ($cardImage !== '') {
					$images[] = $cardImage;
				}
			}
		}

		// Quoted post
		$quoted = self::path($tweet, ['quoted_tweet_results', 'result']);
		if (!$nested && is_array($quoted)) {
			$quote = $this->post($quoted, nested: true);
			if ($quote !== null) {
				$html .= '<blockquote><p>' . self::esc($this->labels['quoting']) . ' <a href="' . self::esc($quote['link']) . '">' . self::esc($quote['author']) . '</a>'
					. ($quote['date'] > 0 ? ' · ' . gmdate('Y-m-d', $quote['date']) : '') . '</p>' . $quote['html'] . '</blockquote>';
				if ($quote['image'] !== '') {
					$images[] = $quote['image'];
				}
			}
		}

		return [
			'link' => $link,
			'title' => $this->title($text, $cardTitle, $kinds),
			'author' => $name !== '' ? $name . ' (@' . $screenName . ')' : '@' . $screenName,
			'date' => $date,
			'html' => $html,
			'image' => $images[0] ?? '',
		];
	}

	/**
	 * Escaped text with t.co links expanded, @mentions and #hashtags linked, and line breaks kept.
	 * @param list<mixed> $urls
	 */
	private function richText(string $text, array $urls): string {
		$html = self::esc($text);
		foreach ($urls as $entity) {
			if (!is_array($entity) || ($short = self::str($entity, ['url'])) === '') {
				continue;
			}
			$target = self::str($entity, ['expanded_url']) ?: $short;
			$shown = self::str($entity, ['display_url']) ?: $target;
			$html = str_replace(self::esc($short), '<a href="' . self::esc($target) . '">' . self::esc($shown) . '</a>', $html);
		}
		// Bare t.co links left over (e.g. media links inside long posts) point back to the post itself
		$html = preg_replace('#(?<!["=>])https://t\.co/[A-Za-z0-9]+#', '', $html) ?? $html;
		$html = preg_replace_callback('/(?<![\w\/&@])@(' . self::USER_PATTERN . ')\b/', static fn(array $m): string =>
			'<a href="https://x.com/' . $m[1] . '">@' . $m[1] . '</a>', $html) ?? $html;
		$html = preg_replace_callback('/(?<![\w\/&#])#(\p{L}[\p{L}\p{N}_]*)/u', static fn(array $m): string =>
			'<a href="https://x.com/hashtag/' . rawurlencode($m[1]) . '">#' . $m[1] . '</a>', $html) ?? $html;
		return nl2br(trim($html), false);
	}

	/**
	 * Contents of the page's <script> elements. Plain string scanning, not a regex: FreshRSS lowers
	 * pcre.backtrack_limit to 10000, which a lazy `<script>(.*?)</script>` exhausts on these pages.
	 * @return list<string>
	 */
	private static function scripts(string $html): array {
		$scripts = [];
		$offset = 0;
		while (($open = stripos($html, '<script', $offset)) !== false) {
			$start = strpos($html, '>', $open);
			$close = $start === false ? false : stripos($html, '</script>', $start);
			if ($start === false || $close === false) {
				break;
			}
			$scripts[] = substr($html, $start + 1, $close - $start - 1);
			$offset = $close + strlen('</script>');
		}
		return $scripts;
	}

	/** @param list<string> $kinds */
	private function title(string $text, string $fallback, array $kinds): string {
		$text = preg_replace('#https?://\S+#', '', $text) ?? $text;
		$lines = array_values(array_filter(array_map(static fn(string $line): string => self::clean($line), explode("\n", $text)),
			static fn(string $line): bool => $line !== ''));
		if ($lines !== []) {
			$first = $lines[0];
			$title = (mb_strlen($first) >= self::TITLE_MIN_FIRST_LINE || count($lines) === 1) ? $first : implode(' ', $lines);
			return self::shorten($title, self::TITLE_MAX);
		}
		if ($fallback !== '') {
			return self::shorten($fallback, self::TITLE_MAX);
		}
		return match (true) {
			in_array('video', $kinds, true) => $this->labels['video'],
			in_array('photo', $kinds, true) => $this->labels['photo'],
			default => $this->labels['post'],
		};
	}

	/** @param list<mixed> $variants */
	private static function bestVideo(array $variants): string {
		$best = '';
		$bitrate = -1;
		foreach ($variants as $variant) {
			if (is_array($variant) && self::str($variant, ['content_type']) === 'video/mp4') {
				$rate = is_int($variant['bitrate'] ?? null) ? $variant['bitrate'] : 0;
				if ($rate > $bitrate) {
					[$best, $bitrate] = [self::str($variant, ['url']), $rate];
				}
			}
		}
		return $best;
	}

	/**
	 * Timeline instructions from a decoded `GraphQLRequestStream.Completed` message, or null.
	 * @return array<mixed>|null
	 */
	private static function findTimeline(mixed $node, int $depth = 0): ?array {
		if (!is_array($node) || $depth > 12) {
			return null;
		}
		if (is_array($node['timeline'] ?? null) && is_array($node['timeline']['instructions'] ?? null)) {
			return $node['timeline']['instructions'];
		}
		foreach ($node as $child) {
			$found = self::findTimeline($child, $depth + 1);
			if ($found !== null) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * @param array<mixed> $tweet
	 * @return array<mixed>|null the Tweet itself, unwrapped from TweetWithVisibilityResults; null for tombstones
	 */
	private static function unwrap(array $tweet): ?array {
		if (($tweet['__typename'] ?? '') === 'TweetWithVisibilityResults' && is_array($tweet['tweet'] ?? null)) {
			$tweet = $tweet['tweet'];
		}
		return ($tweet['__typename'] ?? '') === 'Tweet' ? $tweet : null;
	}

	// ---------------------------------------------------------------------------------------------
	// A minimal reader for seroval's output: JSON-like object and array literals with unquoted keys,
	// `$R[n]=` labels (dropped), `$R[n]` back-references (read as null), `!0`/`!1`, `void 0`, and
	// JavaScript string escapes. Anything else (functions, `new …`) makes decoding fail.

	private function decode(string $src, int $offset): mixed {
		$this->src = $src;
		$this->pos = $offset;
		$this->depth = 0;
		try {
			return $this->value();
		} catch (UnexpectedValueException) {
			return null;
		}
	}

	/** @throws UnexpectedValueException */
	private function value(): mixed {
		while (preg_match('/\G\s*\$R\[\d+\]=/', $this->src, $label, 0, $this->pos) === 1) {
			$this->pos += strlen($label[0]);
		}
		$this->space();
		$char = $this->src[$this->pos] ?? '';
		if ($char === '{' || $char === '[') {
			if (++$this->depth > self::MAX_DEPTH) {
				throw new UnexpectedValueException('too deep');
			}
			$value = $char === '{' ? $this->object() : $this->array();
			$this->depth--;
			return $value;
		}
		if ($char === '"') {
			return $this->string();
		}
		if (preg_match('/\G-?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $this->src, $number, 0, $this->pos) === 1) {
			$this->pos += strlen($number[0]);
			return preg_match('/[.eE]/', $number[0]) === 1 ? (float)$number[0] : (int)$number[0];
		}
		foreach (['!0' => true, '!1' => false, 'true' => true, 'false' => false, 'null' => null, 'void 0' => null, 'undefined' => null] as $word => $result) {
			if (substr_compare($this->src, $word, $this->pos, strlen($word)) === 0) {
				$this->pos += strlen($word);
				return $result;
			}
		}
		if (preg_match('/\G\$R\[\d+\]/', $this->src, $reference, 0, $this->pos) === 1) {
			$this->pos += strlen($reference[0]);
			return null;
		}
		throw new UnexpectedValueException('unexpected input at ' . $this->pos);
	}

	/**
	 * @return array<mixed>
	 * @throws UnexpectedValueException
	 */
	private function object(): array {
		$this->pos++;   // {
		$object = [];
		$this->space();
		if (($this->src[$this->pos] ?? '') === '}') {
			$this->pos++;
			return $object;
		}
		while (true) {
			$this->space();
			if (($this->src[$this->pos] ?? '') === '"') {
				$key = $this->string();
			} elseif (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*|\G\d+/', $this->src, $name, 0, $this->pos) === 1) {
				$key = $name[0];
				$this->pos += strlen($name[0]);
			} else {
				throw new UnexpectedValueException('bad key at ' . $this->pos);
			}
			$this->expect(':');
			$object[$key] = $this->value();
			$this->space();
			$char = $this->src[$this->pos++] ?? '';
			if ($char === '}') {
				return $object;
			}
			if ($char !== ',') {
				throw new UnexpectedValueException('bad object at ' . $this->pos);
			}
		}
	}

	/**
	 * @return list<mixed>
	 * @throws UnexpectedValueException
	 */
	private function array(): array {
		$this->pos++;   // [
		$array = [];
		$this->space();
		if (($this->src[$this->pos] ?? '') === ']') {
			$this->pos++;
			return $array;
		}
		while (true) {
			$this->space();
			$array[] = ($this->src[$this->pos] ?? '') === ',' ? null : $this->value();   // a hole
			$this->space();
			$char = $this->src[$this->pos++] ?? '';
			if ($char === ']') {
				return $array;
			}
			if ($char !== ',') {
				throw new UnexpectedValueException('bad array at ' . $this->pos);
			}
		}
	}

	/** @throws UnexpectedValueException */
	private function string(): string {
		$this->pos++;   // "
		$out = '';
		$length = strlen($this->src);
		while ($this->pos < $length) {
			$char = $this->src[$this->pos++];
			if ($char === '"') {
				return $out;
			}
			if ($char !== '\\') {
				$out .= $char;
				continue;
			}
			$escape = substr($this->src, $this->pos++, 1);
			switch ($escape) {
				case 'n': $out .= "\n"; break;
				case 't': $out .= "\t"; break;
				case 'r': $out .= "\r"; break;
				case 'b': $out .= "\x08"; break;
				case 'f': $out .= "\f"; break;
				case 'v': $out .= "\v"; break;
				case '0': $out .= "\0"; break;
				case 'x':
					$out .= mb_chr((int)hexdec(substr($this->src, $this->pos, 2)), 'UTF-8');
					$this->pos += 2;
					break;
				case 'u':
					$code = (int)hexdec(substr($this->src, $this->pos, 4));
					$this->pos += 4;
					if ($code >= 0xD800 && $code <= 0xDBFF && substr_compare($this->src, '\\u', $this->pos, 2) === 0) {
						$low = (int)hexdec(substr($this->src, $this->pos + 2, 4));
						if ($low >= 0xDC00 && $low <= 0xDFFF) {
							$code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
							$this->pos += 6;
						}
					}
					$out .= mb_chr($code, 'UTF-8') ?: '';
					break;
				default:
					$out .= $escape;   // \" \\ \/ \' and anything else stand for themselves
			}
		}
		throw new UnexpectedValueException('unterminated string');
	}

	/** @throws UnexpectedValueException */
	private function expect(string $char): void {
		$this->space();
		if (($this->src[$this->pos] ?? '') !== $char) {
			throw new UnexpectedValueException('expected ' . $char . ' at ' . $this->pos);
		}
		$this->pos++;
	}

	private function space(): void {
		$this->pos += strspn($this->src, " \t\r\n", $this->pos);
	}

	// ---------------------------------------------------------------------------------------------

	/**
	 * @param array<mixed> $data
	 * @param list<string> $keys
	 */
	private static function path(array $data, array $keys): mixed {
		$node = $data;
		foreach ($keys as $key) {
			if (!is_array($node) || !array_key_exists($key, $node)) {
				return null;
			}
			$node = $node[$key];
		}
		return $node;
	}

	/**
	 * @param array<mixed> $data
	 * @param list<string> $keys
	 */
	private static function str(array $data, array $keys): string {
		$value = self::path($data, $keys);
		return is_string($value) ? $value : '';
	}

	/**
	 * @param array<mixed> $data
	 * @param list<string> $keys
	 * @return list<mixed>
	 */
	private static function list(array $data, array $keys): array {
		$value = self::path($data, $keys);
		return is_array($value) ? array_values($value) : [];
	}

	private static function clean(string $text): string {
		return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
	}

	private static function shorten(string $text, int $max): string {
		if (mb_strlen($text) <= $max) {
			return $text;
		}
		$cut = mb_substr($text, 0, $max);
		$space = mb_strrpos($cut, ' ');
		if ($space !== false && $space > $max * 0.6) {
			$cut = mb_substr($cut, 0, $space);
		}
		return rtrim($cut, " \t,;:.-–—") . '…';
	}

	private static function esc(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
