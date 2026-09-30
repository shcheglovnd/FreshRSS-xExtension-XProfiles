<?php

declare(strict_types=1);

/**
 * Manual check of the parser without FreshRSS: prints what a profile would look like as a feed.
 *
 *   php tests/preview.php Meta               # fetches https://x.com/Meta
 *   php tests/preview.php page.html Meta     # a saved copy of that page
 *   php tests/preview.php Meta --rss         # the generated RSS instead of a summary
 */

require dirname(__DIR__) . '/lib/XProfilesPage.php';
require dirname(__DIR__) . '/lib/XProfilesRss.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn(string $a): bool => $a !== '--rss'));
$rss = in_array('--rss', $argv, true);
if ($args === []) {
	fwrite(STDERR, "usage: php tests/preview.php <user> | <saved-page.html> <user> [--rss]\n");
	exit(2);
}
if (is_file($args[0])) {
	$html = (string)file_get_contents($args[0]);
	$channel = $args[1] ?? '';
} else {
	$channel = ltrim($args[0], '@');
	$context = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0 (FreshRSS X Profiles test)\r\n", 'timeout' => 20]]);
	$html = (string)@file_get_contents(XProfilesPage::profileUrl($channel), false, $context);
}

$data = XProfilesPage::parse($html, $channel);
if ($data === null) {
	fwrite(STDERR, "no timeline found on the page\n");
	exit(1);
}
if ($rss) {
	echo XProfilesRss::build($data);
	exit(0);
}
printf("%s — %s\n%s\n\n", $data['title'], $data['link'], $data['description']);
foreach ($data['posts'] as $post) {
	printf("%s  %s\n  %s | %d image(s) | %d bytes of HTML\n", $post['date'] > 0 ? date('Y-m-d H:i', $post['date']) : '?', $post['link'],
		$post['title'], substr_count($post['html'], '<img'), strlen($post['html']));
}
