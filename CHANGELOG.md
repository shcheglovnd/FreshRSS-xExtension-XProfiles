# Changelog

## 0.1.2 — 2026-09-30

* Fixed: typographic characters and emoji in posts appeared as HTML entities, e.g. “We&rsquo;re” instead of “We’re”. FreshRSS’s HTTP client re-serialised the profile page as HTML, which encoded every non-ASCII character of the posts embedded in it; the page is now fetched as it is.
* FreshRSS now records the time of each refresh reliably. The feed is handed to SimplePie with a Content-Type instead of `force_feed`, which made SimplePie cache it under another name.

## 0.1.1 — 2026-09-29

* A page without posts is no longer kept in FreshRSS’s cache, so the next refresh fetches afresh instead of reusing it for up to ~13 minutes.

## 0.1.0 — 2026-09-29

First release.

* Follow a public X profile with “Add a feed” and its link (`https://x.com/<user>`, `twitter.com/<user>`, a post link, `@<user>`, a Nitter / xcancel URL).
* Posts are read from the page X renders for logged-out visitors; no Nitter, RSS-Bridge or X account.
* Content: text with expanded links, mentions and hashtags; full text of long posts; large photos; playable videos and GIFs; quoted posts; link cards.
* Nitter / xcancel subscriptions can be read directly as well, and converted to x.com in one click.
* Default refresh interval for new profiles (1 hour).
* English and Russian translations.
