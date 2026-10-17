# X Profiles — FreshRSS extension

Follow **X (Twitter) profiles** in [FreshRSS](https://freshrss.org) directly: no Nitter,
RSS-Bridge or other proxy, no X account, no API keys. Paste a profile link into *Add a feed*.

X renders every public profile page (`https://x.com/<user>`) for logged-out visitors on its server,
and embeds the data of the latest posts in the page. Whenever FreshRSS refreshes such a
subscription, this extension downloads that page, decodes the embedded posts, and hands FreshRSS
a proper RSS document built from them.

## Why

X has no RSS. The usual workarounds depend on something else: public Nitter / xcancel mirrors are
frequently down or rate-limited (HTTP 429/503), and self-hosted Nitter or RSS-Bridge now needs real
X accounts. This extension needs nothing but FreshRSS itself.

## What you get

* **Each refresh sees the latest original posts of the profile**: currently about five, plus the
  pinned post. Replies and reposts are not included. FreshRSS keeps every post it has seen, so
  the feed grows over time; for accounts that post a lot, use a shorter refresh interval.
* **Text** with expanded links, `@mentions` and `#hashtags` linked, and line breaks kept. Long
  posts come in full.
* **Photos** at large size.
* **Videos and GIFs** as playable `<video>` with a poster image, plus a link to the post.
* **Quoted posts**, with their text and media, and **link cards** (site, title, description,
  image).
* **Titles** from the post’s first line. The author is shown as *Name (@user)*, the post date is
  exact, and the profile picture becomes the feed icon.

## Install

1. Download this repository into a folder named `xExtension-XProfiles`, either
   `git clone https://github.com/shcheglovnd/FreshRSS-xExtension-XProfiles.git xExtension-XProfiles`, or
   *Code → Download ZIP* and rename the extracted folder. FreshRSS accepts any folder name;
   `xExtension-…` is just the convention.
2. Put that folder into the `extensions/` directory of your FreshRSS. With the official Docker
   image that is the volume mounted at `/var/www/FreshRSS/extensions`.
3. In FreshRSS: *Settings → Extensions*, then enable **X Profiles**. It is a per-user extension.

Requires FreshRSS **1.28 or newer**.

## Use

*Subscription management → Add a feed*, then paste any of these:

* `https://x.com/Meta`
* `https://twitter.com/Meta`
* a link to a post, e.g. `https://x.com/Meta/status/123…`
* `@Meta` (where the form accepts it)
* a Nitter / xcancel URL such as `https://nitter.net/Meta/rss`

All of them are stored as `https://x.com/Meta`.

### Settings

*Settings → Extensions → X Profiles ⚙*:

* **Refresh interval for new profiles.** The default is 1 hour.
* **Read Nitter / xcancel feeds directly as well.** On by default. Subscriptions pointing at such
  mirrors are then read from x.com.
* **Convert existing feeds.** Rewrites your Nitter / xcancel subscriptions, and other forms of X
  profile URLs, into `https://x.com/<user>` subscriptions handled by this extension. Names and
  categories are kept. Posts already fetched through a mirror may appear once more, because
  mirrors use their own post links as IDs.

## Limitations

* **Public profiles only.** Protected and suspended accounts don’t work.
* **A handful of posts per refresh**, as explained above.
* **Tied to X’s logged-out page.** If X changes that page, or stops rendering posts for
  logged-out visitors, the extension needs an update. Feeds then show an error instead of silently
  going empty.
* **Media lives on X’s CDN** (`pbs.twimg.com`, `video.twimg.com`), and is loaded from there when you
  read.
* **Disabling the extension breaks these feeds.** `x.com/<user>` subscriptions stop working,
  because the page is not RSS on its own.
* **X’s terms.** Reading public pages this way is for personal use. Keep refresh intervals
  moderate.

## How it works

* `check_url_before_add` normalises every supported URL form to `https://x.com/<user>`.
* `simplepie_before_init` downloads the profile page with FreshRSS’s own HTTP client, so proxy
  settings, timeouts, caching and Retry-After handling are respected.
  * The page embeds the GraphQL response for the profile timeline, serialised by
    [seroval](https://github.com/lxsmnsyc/seroval) as a JavaScript literal.
    `XProfilesPage` decodes it with a small strict reader: no `eval`, and anything unexpected fails
    cleanly. Both embedded objects and JSON strings in `Result.Ok.value` are supported.
  * `XProfilesRss` renders RSS 2.0, and the result is given to SimplePie as the feed body, so FreshRSS
    then handles it like any other feed.
* `feed_before_insert` applies the refresh interval to newly added profiles.

## Development

Run the offline parser and RSS regression checks with `php tests/parser.php`.

`tests/preview.php` runs the parser without FreshRSS:

```sh
php tests/preview.php Meta                  # live https://x.com/Meta
php tests/preview.php saved-page.html Meta
php tests/preview.php Meta --rss            # print the generated RSS
```

## Automated checks

[Feed checks](https://github.com/shcheglovnd/FreshRSS-xExtension-XProfiles/actions/workflows/checks.yml)
runs every day at **13:23 UTC**, on pushes to `main`, and manually through **Run workflow**.
It checks the real logged-out pages for **deepseek_ai** and **Meta**. Each must produce at least
one post with content, author, a valid URL and date, and
valid RSS. No recent-post cutoff is used, so quiet accounts do not trigger false alarms.
Failed requests or parsing checks are retried twice. On failure, the run saves public profile
pages, response metadata and generated RSS as diagnostic artifacts for 7 days.

Offline regression checks run in the FreshRSS 1.30.0 image on GitHub-hosted runners, including
on pull requests. X challenges GitHub-hosted IP addresses, so live requests run on the dedicated
`xprofiles-live` runner on minis. GitHub still schedules the job and records its results. That
runner is isolated in a container without access to the host's files or Docker socket. Live jobs
accept only this repository's `main` branch on pushes, schedules and manual dispatches; a runner
hook also rejects other events and refs before steps run. All external contributors require
approval before their workflows can run.

To run the live check locally, use `php tests/live.php` (requires cURL, DOM and mbstring),
or diagnose a saved response with `php tests/live.php --file page.html deepseek_ai`.

Failed runs appear in Actions. Enable failure notifications for Actions in your
[GitHub notification settings](https://github.com/settings/notifications) for email alerts.
GitHub can delay scheduled runs and automatically disables schedules in public repositories
after 60 days without repository activity; see the
[schedule documentation](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule).

## License

[MIT](LICENSE)
