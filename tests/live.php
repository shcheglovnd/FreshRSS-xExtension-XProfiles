<?php

declare(strict_types=1);

/** Daily live check; also accepts --file <saved-page.html> <profile> for offline diagnosis. */
require dirname(__DIR__) . '/lib/XProfilesPage.php';
require dirname(__DIR__) . '/lib/XProfilesRss.php';

ini_set('pcre.backtrack_limit', '10000');
libxml_use_internal_errors(true);

/** Check that a page produces usable posts, rather than merely parsing without an exception. */
function validateFeed(string $html, string $profile, ?string $diagnostics, string $prefix): int {
	$data = XProfilesPage::parse($html, $profile);
	if ($data === null) {
		throw new RuntimeException('No embedded timeline found; X may have changed the page or blocked the request');
	}
	if ($data['posts'] === []) {
		throw new RuntimeException('Timeline parsed but contains no usable posts');
	}
	foreach ($data['posts'] as $post) {
		if (preg_match('#^https://x\\.com/' . preg_quote($profile, '#') . '/status/\\d+$#i', $post['link']) !== 1
			|| $post['title'] === '' || $post['author'] === '' || $post['html'] === '') {
			throw new RuntimeException('A post has missing content, author, title, or an unexpected URL');
		}
		if ($post['date'] <= 0 || $post['date'] > time() + 86400) {
			throw new RuntimeException('A post has a missing or invalid publication date');
		}
	}
	$rss = XProfilesRss::build($data);
	if ($diagnostics !== null) {
		file_put_contents($diagnostics . '/' . $prefix . '.rss', $rss);
	}
	$xml = new DOMDocument();
	if (!$xml->loadXML($rss, LIBXML_NONET) || $xml->getElementsByTagName('item')->length !== count($data['posts'])) {
		throw new RuntimeException('Generated RSS is invalid or lost posts');
	}
	libxml_clear_errors();
	return count($data['posts']);
}

$args = array_slice($argv, 1);
$savedPage = null;
if (($args[0] ?? '') === '--file') {
	if (count($args) !== 3 || !is_file($args[1])) {
		fwrite(STDERR, "usage: php tests/live.php --file <saved-page.html> <profile>\n");
		exit(2);
	}
	$savedPage = $args[1];
	$args = [$args[2]];
}
$profiles = array_values(array_unique($args === [] ? ['deepseek_ai', 'Meta'] : $args));
foreach ($profiles as $profile) {
	if (preg_match('/^' . XProfilesPage::USER_PATTERN . '$/', $profile) !== 1) {
		fwrite(STDERR, "Invalid profile name\n");
		exit(2);
	}
}
$directory = getenv('XPROFILES_DIAGNOSTICS_DIR');
$diagnostics = is_string($directory) && $directory !== '' ? $directory : null;
if ($diagnostics !== null && !is_dir($diagnostics) && !mkdir($diagnostics, 0775, true)) {
	fwrite(STDERR, "Cannot create diagnostics directory\n");
	exit(2);
}

$failed = false;
$summary = "## Live X profile checks\n\n| Profile | Result |\n| --- | --- |\n";
foreach ($profiles as $profile) {
	$url = XProfilesPage::profileUrl($profile);
	$attempts = $savedPage === null ? 3 : 1;
	for ($attempt = 1; $attempt <= $attempts; $attempt++) {
		try {
			$prefix = $profile . '-attempt-' . $attempt;
			if ($savedPage !== null) {
				$html = (string)file_get_contents($savedPage);
			} else {
				$curl = curl_init($url);
				curl_setopt_array($curl, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => 3,
					CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
					CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
					CURLOPT_CONNECTTIMEOUT => 10,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_ENCODING => '',
					CURLOPT_USERAGENT => 'FreshRSS/1.30.0 (Linux; https://freshrss.org)',
					CURLOPT_HTTPHEADER => ['Accept: application/json,application/feed+json,application/javascript;q=0.9,text/javascript;q=0.8,*/*;q=0.7'],
				]);
				$body = curl_exec($curl);
				$error = curl_error($curl);
				$info = curl_getinfo($curl);
				curl_close($curl);
				$html = is_string($body) ? $body : '';
				if ($diagnostics !== null) {
					file_put_contents($diagnostics . '/' . $prefix . '.html', $html);
					file_put_contents($diagnostics . '/' . $prefix . '.json', json_encode([
						'checked_at' => gmdate(DATE_ATOM), 'url' => $url,
						'status' => $info['http_code'], 'effective_url' => $info['url'],
						'content_type' => $info['content_type'], 'bytes' => strlen($html), 'curl_error' => $error,
					], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
				}
				if ($body === false || $info['http_code'] !== 200 || $html === '') {
					throw new RuntimeException('Fetch failed: HTTP ' . $info['http_code'] . ($error !== '' ? ', ' . $error : ''));
				}
			}
			$count = validateFeed($html, $profile, $diagnostics, $prefix);
			echo "PASS @$profile: $count posts, valid dates and RSS\n";
			$summary .= "| [@$profile]($url) | Passed: $count posts |\n";
			break;
		} catch (Throwable $error) {
			$message = $error->getMessage();
			fwrite(STDERR, "@$profile attempt $attempt/$attempts: $message\n");
			if ($attempt < $attempts) {
				sleep(10 * $attempt);
				continue;
			}
			$failed = true;
			$summary .= "| [@$profile]($url) | Failed: " . str_replace(["\r", "\n", '|'], [' ', ' ', '\\|'], $message) . " |\n";
			if (getenv('GITHUB_ACTIONS') === 'true') {
				echo '::error title=X profile check failed::@' . $profile . ': '
					. str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $message) . "\n";
			}
		}
	}
}
$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
	file_put_contents($summaryPath, $summary, FILE_APPEND);
}
exit($failed ? 1 : 0);
