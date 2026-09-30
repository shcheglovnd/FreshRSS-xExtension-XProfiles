<?php

declare(strict_types=1);

/**
 * Follow X (Twitter) profiles in FreshRSS without Nitter, RSS-Bridge or an X account.
 *
 * A profile is subscribed to as https://x.com/<user>. X renders that page for logged-out visitors
 * with the latest original posts embedded; whenever FreshRSS loads such a feed, this extension
 * downloads the page, reads those posts ({@see XProfilesPage}) and hands SimplePie a ready RSS
 * document ({@see XProfilesRss}).
 */
final class XProfilesExtension extends Minz_Extension {
	/** Refresh intervals offered for new profiles, in minutes; 0 keeps the FreshRSS default. */
	public const TTL_CHOICES = [0, 15, 30, 60, 120, 240, 480, 1440];
	private const TTL_DEFAULT_MINUTES = 60;

	/** First path segments on x.com that are not profiles. */
	private const RESERVED = ['about', 'account', 'bookmarks', 'communities', 'compose', 'download', 'explore', 'grok', 'hashtag', 'home',
		'i', 'intent', 'jobs', 'lists', 'login', 'logout', 'messages', 'notifications', 'premium', 'privacy', 'rules', 'search', 'settings',
		'share', 'signup', 'topics', 'tos', 'verified'];

	#[\Override]
	public function init(): void {
		parent::init();
		require_once __DIR__ . '/lib/XProfilesPage.php';
		require_once __DIR__ . '/lib/XProfilesRss.php';
		$this->registerTranslates();
		// Hook names as strings rather than Minz_HookType, to stay compatible with FreshRSS 1.28
		$this->registerHook('check_url_before_add', [$this, 'checkUrlBeforeAdd']);
		$this->registerHook('feed_before_insert', [$this, 'feedBeforeInsert']);
		$this->registerHook('simplepie_before_init', [$this, 'simplepieBeforeInit']);
	}

	/**
	 * “Add a feed” accepts https://x.com/<user>, twitter.com/<user>, a link to one of their posts, @<user>,
	 * or a Nitter / xcancel URL; all become https://x.com/<user>.
	 */
	public function checkUrlBeforeAdd(string $url): string {
		$user = $this->userFromUrl($url, allowHandle: true);
		return $user === null ? $url : XProfilesPage::profileUrl($user);
	}

	/** New profiles get the refresh interval chosen in this extension’s settings. */
	public function feedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		if ($this->userFromUrl($this->plainUrl($feed)) !== null && $feed->ttl() === FreshRSS_Feed::TTL_DEFAULT) {
			$minutes = $this->ttlMinutes();
			if ($minutes > 0) {
				$feed->_ttl($minutes * 60);
			}
		}
		return $feed;
	}

	/**
	 * Before SimplePie fetches an X profile, give it the RSS built from the profile page.
	 * @throws FreshRSS_Feed_Exception when the page cannot be loaded or has no timeline
	 */
	public function simplepieBeforeInit(FreshRSS_SimplePieCustom $simplePie, FreshRSS_Feed $feed): void {
		if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
			return;
		}
		$url = $this->plainUrl($feed);
		$user = $this->userFromUrl($url);
		if ($user === null) {
			return;
		}

		$profileUrl = XProfilesPage::profileUrl($user);
		// As `json` rather than `html`: FreshRSS would re-serialise an HTML page through DOMDocument, which turns the
		// non-ASCII characters of the posts embedded in it into HTML entities (’ into &rsquo;). X serves the same page.
		$response = FreshRSS_http_Util::httpGet($profileUrl, $feed->cacheFilename($profileUrl), 'json', $feed->attributes(), $feed->curlOptions());
		$body = $response['body'];
		if ($response['fail'] || $body === '') {
			// `status` and `error` are only returned since FreshRSS 1.29
			$status = $response['status'] ?? 0;
			$status = is_int($status) ? abs($status) : 0;
			$error = $response['error'] ?? '';
			throw new FreshRSS_Feed_Exception(_t('ext.x_profiles.error.fetch', $profileUrl,
				$status > 0 ? 'HTTP ' . $status : (is_string($error) && $error !== '' ? $error : '?')), $status);
		}
		$data = XProfilesPage::parse($body, $user, $this->labels());
		if ($data === null) {
			// Do not let FreshRSS’s cache serve this page again at the next refresh
			$cache = $feed->cacheFilename($profileUrl);
			if (is_file($cache)) {
				unlink($cache);
			}
			throw new FreshRSS_Feed_Exception(_t('ext.x_profiles.error.no_timeline', $user, $profileUrl));
		}

		// With a Content-Type rather than force_feed(true), which would make SimplePie cache the feed under another name
		// than the one FreshRSS reads the time of the last refresh from
		$response = (new \SimplePie\HTTP\RawTextResponse(XProfilesRss::build($data), $url))->with_header('content-type', 'application/rss+xml; charset=UTF-8');
		$file = \SimplePie\File::fromResponse($response);
		$simplePie->set_file($file);
	}

	/**
	 * The X username a URL points at, or null.
	 * @param bool $allowHandle also accept a bare `@user`
	 * @param bool|null $mirrors also recognise Nitter / xcancel URLs (null: per the user’s setting)
	 */
	public function userFromUrl(string $url, bool $allowHandle = false, ?bool $mirrors = null): ?string {
		$url = trim($url);
		$name = XProfilesPage::USER_PATTERN;
		$tail = '(?:/(?:status/\d+[^?\#]*|with_replies|media|highlights)(?:/rss)?|/rss)?/?(?:[?\#].*)?$';
		if ($allowHandle && preg_match('/^@(' . $name . ')$/', $url, $matches) === 1) {
			return $matches[1];
		}
		if (preg_match('#^(?:https?://)?(?:www\.|mobile\.)?(?:x|twitter)\.com/(' . $name . ')' . $tail . '#i', $url, $matches) !== 1
			&& (!($mirrors ?? $this->mirrorsEnabled())
				|| preg_match('#^https?://(?:[^/?\#]*nitter[^/?\#]*|xcancel\.com|twiiit\.com)/(' . $name . ')' . $tail . '#i', $url, $matches) !== 1)) {
			return null;
		}
		return in_array(strtolower($matches[1]), self::RESERVED, true) ? null : $matches[1];
	}

	/**
	 * Points this user’s existing X feeds at https://x.com/<user> and hands them to this extension:
	 * Nitter / xcancel subscriptions and other forms of X profile URLs. Names, categories and articles are kept.
	 * @return int number of feeds changed
	 */
	public function convertFeeds(bool $dryRun = false): int {
		$dao = FreshRSS_Factory::createFeedDao();
		$changed = 0;
		foreach ($dao->listFeeds() as $feed) {
			$user = $this->userFromUrl($this->plainUrl($feed), mirrors: true);
			if ($user === null) {
				continue;
			}
			$values = [];
			$profileUrl = XProfilesPage::profileUrl($user);
			if ($this->plainUrl($feed) !== $profileUrl && $dao->searchByUrl($profileUrl) === null) {
				$values['url'] = $profileUrl;
			}
			if (!in_array($feed->kind(), [FreshRSS_Feed::KIND_RSS, FreshRSS_Feed::KIND_RSS_FORCED], true)) {
				$values['kind'] = FreshRSS_Feed::KIND_RSS;
			}
			if ($feed->website() !== $profileUrl) {
				$values['website'] = $profileUrl;
			}
			if ($values !== [] && ($dryRun || $dao->updateFeed($feed->id(), $values))) {
				$changed++;
			}
		}
		return $changed;
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();
		if (!Minz_Request::isPost()) {
			return;
		}
		$minutes = Minz_Request::paramInt('ttl_minutes');
		$this->setUserConfiguration([
			'ttl_minutes' => in_array($minutes, self::TTL_CHOICES, true) ? $minutes : self::TTL_DEFAULT_MINUTES,
			'mirrors' => Minz_Request::paramBoolean('mirrors'),
		]);
		if (Minz_Request::paramString('x_action') === 'convert') {
			$changed = $this->convertFeeds();
			Minz_Request::good(_t('ext.x_profiles.convert.done', $changed),
				['c' => 'extension', 'a' => 'configure', 'params' => ['e' => $this->getName()]]);
		}
	}

	// getUserConfigurationValue() rather than the typed getters, which need FreshRSS 1.29
	public function ttlMinutes(): int {
		$minutes = $this->getUserConfigurationValue('ttl_minutes');
		return is_int($minutes) ? $minutes : self::TTL_DEFAULT_MINUTES;
	}

	public function mirrorsEnabled(): bool {
		$enabled = $this->getUserConfigurationValue('mirrors');
		return is_bool($enabled) ? $enabled : true;
	}

	/** @return array<string,string> labels for generated content, in the user’s language */
	private function labels(): array {
		$labels = [];
		foreach (array_keys(XProfilesPage::LABELS) as $key) {
			$labels[$key] = _t('ext.x_profiles.label.' . $key);
		}
		return $labels;
	}

	private function plainUrl(FreshRSS_Feed $feed): string {
		return htmlspecialchars_decode($feed->url(), ENT_QUOTES);
	}
}
