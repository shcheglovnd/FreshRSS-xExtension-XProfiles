<?php

return [
	'x_profiles' => [
		'intro' => 'Follow an X (Twitter) profile with “Add a feed” and its link, e.g. <code>https://x.com/Meta</code>. Posts are read from the page X shows to logged-out visitors — no Nitter or other proxy, no X account. Each refresh sees the latest few original posts (no replies or reposts), which your feed then keeps collecting.',
		'ttl' => 'Refresh interval for new profiles',
		'ttl_freshrss' => 'FreshRSS default',
		'minutes' => '%d minutes',
		'hours' => '%d h',
		'ttl_help' => 'Applied when a profile is added. A refresh sees only the latest ~5 posts, so use a shorter interval for accounts that post a lot.',
		'mirrors' => 'Read Nitter / xcancel feeds directly as well',
		'mirrors_help' => 'Subscriptions such as <code>https://nitter.net/Meta/rss</code> then stop depending on those mirrors, which are often down or rate-limited.',
		'convert' => [
			'title' => 'Existing feeds',
			'button' => 'Convert %d feed(s)',
			'help' => 'Points your Nitter / xcancel subscriptions and other X profile URLs at https://x.com/… and hands them to this extension. Names, categories and articles are kept; posts already fetched through a mirror may appear once more.',
			'done' => '%d feed(s) converted',
		],
		'label' => [
			'photo' => 'Photo',
			'video' => 'Video',
			'gif' => 'GIF',
			'post' => 'Post',
			'quoting' => 'Quoting',
			'watch' => 'watch on X',
		],
		'error' => [
			'fetch' => 'X: could not load %s (%s)',
			'no_timeline' => 'X: no posts found for @%s — unknown, suspended or protected account, or X changed its page (%s)',
		],
	],
];
