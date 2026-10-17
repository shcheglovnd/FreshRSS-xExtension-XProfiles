<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/XProfilesPage.php';
require dirname(__DIR__) . '/lib/XProfilesRss.php';

// Match FreshRSS's PCRE limit. Script bodies can be much larger than this.
ini_set('pcre.backtrack_limit', '10000');

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function page(mixed $value): string {
	$encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG);
	return '<script>unrelated()</script><script>($R=>$R[19].next($R[21]={kind:"GraphQLRequestStream.Completed",'
		. 'requestId:1,result:$R[22]={kind:"Result.Ok",value:' . $encoded . '}}))($R["tsr"])</script>';
}

$author = ['core' => ['name' => 'Example', 'screen_name' => 'example'],
	'avatar' => ['image_url' => 'https://pbs.twimg.com/example_normal.jpg']];
$tweet = ['__typename' => 'Tweet', 'rest_id' => '1234567890123456789',
	'core' => ['user_results' => ['result' => $author]],
	'details' => ['created_at_ms' => 1792226154000, 'full_text' => "We’re testing 🚀\n你好 <script>alert(1)</script> & \"quotes\""],
	'media_entities2' => [['type' => 'photo', 'media_url_https' => 'https://pbs.twimg.com/photo.jpg']]];
$entry = ['content' => ['content' => ['tweet_results' => ['result' => $tweet]]]];
$payload = ['data' => ['user_result_by_screen_name' => ['result' => ['profile_user_originals_timeline' => ['timeline' => [
	'instructions' => [
		['__typename' => 'TimelineAddEntries', 'entries' => [$entry]],
		['__typename' => 'TimelinePinEntry', 'entry' => $entry],
	],
]]]]]];
// Also exercise a large script body, without keeping scraped personal data as a fixture.
$payload['padding'] = str_repeat('x', 20000);

$object = XProfilesPage::parse(page($payload), 'example');
check($object !== null, 'Original object transport must still parse');
check(count($object['posts']) === 1, 'Pinned posts must be deduplicated');
check($object['title'] === 'Example (@example)', 'Feed title must use the profile author');
check($object['image'] === 'https://pbs.twimg.com/example_400x400.jpg', 'Profile image must be preserved');
$post = $object['posts'][0];
check($post['date'] === 1792226154, 'Post date must be preserved');
check(str_contains($post['html'], 'We’re testing 🚀'), 'Unicode text must survive decoding');
check(str_contains($post['html'], '你好 &lt;script&gt;'), 'Post HTML must be escaped');
check(str_contains($post['html'], 'https://pbs.twimg.com/photo.jpg?name=large'), 'Photos must be preserved');

$json = XProfilesPage::parse(page(json_encode($payload, JSON_THROW_ON_ERROR)), 'example');
check($json === $object, 'JSON-string transport must produce the same feed as object transport');
// Literal Unicode, quotes, newlines and backslashes pass through two encoding layers.
$unicode = XProfilesPage::parse(page(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'example');
check($unicode === $object, 'Literal Unicode in JSON transport must survive decoding');

$xml = new DOMDocument();
check($xml->loadXML(XProfilesRss::build($json), LIBXML_NONET), 'Generated RSS must be valid XML');
check($xml->getElementsByTagName('item')->length === 1, 'RSS must contain the decoded post');
check(XProfilesPage::parse(page('{broken JSON'), 'example') === null, 'Malformed JSON must fail cleanly');
check(XProfilesPage::parse(page('{"data":null}'), 'example') === null, 'Missing timeline must fail cleanly');
check(XProfilesPage::parse(page('null'), 'example') === null, 'Scalar JSON must fail cleanly');
check(XProfilesPage::parse(page('"{}"'), 'example') === null, 'Nested strings must not be decoded recursively');
check(XProfilesPage::parse('<html>Log in</html>', 'example') === null, 'Login pages must fail cleanly');
check(XProfilesPage::parse(str_replace('value:[]', 'value:(function(){throw new Error("executed")})()', page([])), 'example') === null,
	'Unsupported JavaScript must never be evaluated');

echo "Parser transport and RSS regression checks passed\n";
