#!/usr/bin/env python3
"""Read the GTM4WP wordpress.org support forum as JSON.

This is the `gh` stand-in for the wordpress.org side of issue triage: it exists so
`/wporg-forum-review` reasons over stable, structured data instead of re-reading web
pages every run. Read-only by design — wordpress.org has no write API, and its forum
guidelines prohibit unvetted automated replies, so posting stays a human step.

Two sources, each chosen for what only it provides:

* Topic **lists** are parsed from the bbPress HTML. It is the only view carrying
  absolute timestamps (in the freshness link's `title` attribute), topic ids and the
  last reply's post id.
* Topic **detail** uses `?output_format=md` — wordpress.org's own agent-facing render,
  advertised in every page's `data-llm-hint` attribute. It returns the whole thread in
  a few KB instead of ~150 KB of HTML.

Usage:
    wporg_forum.py list  --view unresolved [--pages N]
    wporg_forum.py list  --view active     [--pages N]
    wporg_forum.py list  --view reviews    [--stars 1,2] [--pages N]
    wporg_forum.py topic <permalink-or-slug>

Never build a topic URL by slugifying a title — take the permalink from `list`.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from html import unescape

PLUGIN_SLUG = "duracelltomi-google-tag-manager"
BASE = "https://wordpress.org/support"

# Identify the tool honestly; this is a maintainer reading their own plugin's forum.
USER_AGENT = (
	"gtm4wp-forum-triage/1.0 "
	"(+https://github.com/duracelltomi/gtm4wp; plugin maintainer support triage)"
)

# wordpress.org closes a topic to new replies after ~6 months without activity.
# Verified at the boundary: 6 months = open, 6 months 1 week = closed.
REPLY_WINDOW_DAYS = 183

# Flagged as "closing window" — still answerable, but not for much longer.
CLOSING_WINDOW_DAYS = 150

REQUEST_DELAY_SECONDS = 1.0

_last_request_at = 0.0


# --------------------------------------------------------------------------- fetch


class NotFound(Exception):
	"""wordpress.org served its 404 page (usually a guessed, non-existent slug)."""


def fetch(url: str) -> str:
	"""GET a wordpress.org page, politely spaced out, and reject 404 pages."""
	global _last_request_at

	elapsed = time.monotonic() - _last_request_at
	if elapsed < REQUEST_DELAY_SECONDS:
		time.sleep(REQUEST_DELAY_SECONDS - elapsed)

	request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
	try:
		with urllib.request.urlopen(request, timeout=30) as response:
			body = response.read().decode("utf-8", errors="replace")
	except urllib.error.HTTPError as error:
		if error.code == 404:
			raise NotFound(url) from error
		raise
	finally:
		_last_request_at = time.monotonic()

	# wordpress.org answers unknown slugs with a 200 "Page not found" document.
	if "<title>Page not found" in body or body.lstrip().startswith("Title: Page not found"):
		raise NotFound(url)

	return body


# ------------------------------------------------------------------------ time


_RELATIVE_UNIT_DAYS = {
	"year": 365.0,
	"month": 30.0,
	"week": 7.0,
	"day": 1.0,
	"hour": 1.0 / 24.0,
	"minute": 1.0 / 1440.0,
	"second": 0.0,
}


def relative_to_days(text: str) -> float | None:
	"""'2 months, 1 week ago' -> 67.0. Returns None when nothing parses."""
	if not text:
		return None

	total = 0.0
	found = False
	for amount, unit in re.findall(r"(\d+)\s+(year|month|week|day|hour|minute|second)s?", text):
		total += int(amount) * _RELATIVE_UNIT_DAYS[unit]
		found = True

	return round(total, 2) if found else None


def absolute_to_iso(text: str) -> str | None:
	"""'July 16, 2026 at 11:30 am' -> '2026-07-16T11:30:00Z' (wordpress.org renders UTC)."""
	if not text:
		return None
	try:
		parsed = datetime.strptime(text.strip(), "%B %d, %Y at %I:%M %p")
	except ValueError:
		return None
	return parsed.replace(tzinfo=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def days_since(iso: str | None) -> float | None:
	if not iso:
		return None
	moment = datetime.strptime(iso, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
	return round((datetime.now(timezone.utc) - moment).total_seconds() / 86400.0, 2)


# ------------------------------------------------------------------ list parsing


_ROW_RE = re.compile(r'<ul id="bbp-topic-(\d+)".*?</ul><!-- #bbp-topic-\1 -->', re.S)
_PERMALINK_RE = re.compile(r'class="bbp-topic-permalink"\s+href="([^"]+)">(.*?)</a>', re.S)
_STARTED_BY_RE = re.compile(
	r'bbp-topic-started-by">Started by:.*?href="([^"]*/users/([^/"]+)/)".*?'
	r'class="bbp-author-name">(.*?)</span>',
	re.S,
)
_VOICES_RE = re.compile(r'bbp-topic-voice-count">(\d+)')
_REPLIES_RE = re.compile(r'bbp-topic-reply-count">(\d+)')
_FRESHNESS_RE = re.compile(
	r'bbp-topic-freshness">\s*<a href="([^"]+)"\s+title="([^"]*)">(.*?)</a>', re.S
)
_FRESHNESS_AUTHOR_RE = re.compile(
	r'bbp-topic-freshness-author">.*?href="([^"]*/users/([^/"]+)/)".*?'
	r'class="bbp-author-name">(.*?)</span>',
	re.S,
)
_RATING_RE = re.compile(r"aria-label='(\d+) out of 5 stars'")
_TAG_RE = re.compile(r"<[^>]+>")


def _text(fragment: str) -> str:
	"""Strip tags/entities from an HTML fragment and collapse whitespace."""
	return re.sub(r"\s+", " ", unescape(_TAG_RE.sub("", fragment))).strip()


def _slug_of(url: str) -> str:
	return url.rstrip("/").rsplit("/", 1)[-1]


def parse_list_row(block: str) -> dict:
	topic_id = int(re.search(r"bbp-topic-(\d+)", block).group(1))

	permalink = _PERMALINK_RE.search(block)
	url = permalink.group(1) if permalink else ""
	raw_title = permalink.group(2) if permalink else ""

	stars = _RATING_RE.search(raw_title)

	freshness = _FRESHNESS_RE.search(block)
	freshness_url = freshness.group(1) if freshness else ""
	last_activity_iso = absolute_to_iso(freshness.group(2)) if freshness else None
	freshness_relative = _text(freshness.group(3)) if freshness else ""

	# The freshness link points at the newest post; no fragment means no replies yet.
	last_post = re.search(r"#post-(\d+)", freshness_url)

	started_by = _STARTED_BY_RE.search(block)
	last_author = _FRESHNESS_AUTHOR_RE.search(block)
	voices = _VOICES_RE.search(block)
	replies = _REPLIES_RE.search(block)

	# Prefer the absolute timestamp; fall back to the relative string if the markup
	# ever drops the title attribute.
	age_days = days_since(last_activity_iso)
	if age_days is None:
		age_days = relative_to_days(freshness_relative)

	if not url and freshness_url:
		url = freshness_url.split("#", 1)[0]

	return {
		"id": topic_id,
		"slug": _slug_of(url.split("#", 1)[0]),
		"url": url.split("#", 1)[0],
		"title": _text(_RATING_RE.sub("", raw_title)),
		"author": started_by.group(3) if started_by else None,
		"author_login": started_by.group(2) if started_by else None,
		"voices": int(voices.group(1)) if voices else None,
		"replies": int(replies.group(1)) if replies else None,
		"last_author": _text(last_author.group(3)) if last_author else None,
		"last_author_login": last_author.group(2) if last_author else None,
		"last_post_id": int(last_post.group(1)) if last_post else None,
		"last_activity_iso": last_activity_iso,
		"last_activity_relative": freshness_relative,
		"age_days": age_days,
		"in_reply_window": bool(age_days is not None and age_days < REPLY_WINDOW_DAYS),
		"closing_window": bool(
			age_days is not None and CLOSING_WINDOW_DAYS <= age_days < REPLY_WINDOW_DAYS
		),
		"stars": int(stars.group(1)) if stars else None,
	}


def list_view_url(view: str, page: int, star_filter: int | None) -> str:
	path = {
		"unresolved": f"{BASE}/plugin/{PLUGIN_SLUG}/unresolved/",
		"active": f"{BASE}/plugin/{PLUGIN_SLUG}/active/",
		"reviews": f"{BASE}/plugin/{PLUGIN_SLUG}/reviews/",
	}[view]

	if page > 1:
		path += f"page/{page}/"
	if star_filter:
		path += f"?filter={star_filter}"

	return path


def fetch_list(view: str, pages: int, stars: list[int] | None) -> list[dict]:
	buckets: list[int | None] = [s for s in stars] if stars else [None]
	rows: list[dict] = []
	seen: set[int] = set()

	for star_filter in buckets:
		for page in range(1, pages + 1):
			try:
				html = fetch(list_view_url(view, page, star_filter))
			except NotFound:
				break

			# finditer, not findall — the pattern's backreference makes findall
			# return the captured id rather than the whole row.
			blocks = [match.group(0) for match in _ROW_RE.finditer(html)]
			if not blocks:
				break

			for block in blocks:
				row = parse_list_row(block)
				if row["id"] in seen:
					continue
				seen.add(row["id"])
				row["view"] = view
				if star_filter and row["stars"] is None:
					row["stars"] = star_filter
				rows.append(row)

			# Short page means we reached the end of the view.
			if len(blocks) < 30:
				break

	return rows


# ----------------------------------------------------------------- topic parsing


_POST_HEADER_RE = re.compile(
	r"^ \*\s+\[(?P<name>[^\]]+)\]\((?P<profile>https://wordpress\.org/support/users/[^)]*)\)\n"
	r" \* \(@(?P<login>[^)]*)\)\n"
	r" \* \[(?P<when>[^\]]+)\]\((?P<link>[^)]+)\)\n",
	re.M,
)

_BODY_STOP_RE = re.compile(
	r"^(?:Viewing \d+|You must be \[logged in|The topic .* is closed to new replies\.)"
	r"|^ \* !\[\]\(https://ps\.w\.org/",
	re.M,
)


def _post_body(markdown: str, start: int, end: int) -> str:
	chunk = markdown[start:end]
	stop = _BODY_STOP_RE.search(chunk)
	if stop:
		chunk = chunk[: stop.start()]

	# The md render prefixes every body line with " * " / "   "; strip that framing.
	lines = [re.sub(r"^ \* ?| {3}", "", line, count=1) for line in chunk.splitlines()]
	return "\n".join(lines).strip()


def fetch_topic(reference: str) -> dict:
	if reference.startswith("http"):
		url = reference.split("#", 1)[0].rstrip("/") + "/"
	else:
		url = f"{BASE}/topic/{reference.strip('/')}/"

	markdown = fetch(url + "?output_format=md")

	posts = []
	matches = list(_POST_HEADER_RE.finditer(markdown))
	for index, match in enumerate(matches):
		end = matches[index + 1].start() if index + 1 < len(matches) else len(markdown)
		post_id = re.search(r"#post-(\d+)", match.group("link"))
		when = match.group("when")
		posts.append(
			{
				"author": match.group("name").strip(),
				"author_login": match.group("login").strip(),
				"post_id": int(post_id.group(1)) if post_id else None,
				"relative_time": when,
				"age_days": relative_to_days(when),
				"body": _post_body(markdown, match.end(), end),
			}
		)

	title = re.search(r"^# (.+)$", markdown, re.M)
	status = re.search(r"Status:\s*(.+)$", markdown, re.M)
	replies = re.search(r"^ \* (\d+) repl(?:y|ies)$", markdown, re.M)
	participants = re.search(r"^ \* (\d+) participants?$", markdown, re.M)
	last_reply_from = re.search(r"Last reply from:\s*\[([^\]]+)\]", markdown)
	last_activity = re.search(r"Last activity:\s*\[([^\]]+)\]\(([^)]*)\)", markdown)
	last_post_id = re.search(r"#post-(\d+)", last_activity.group(2)) if last_activity else None

	opening_age = posts[0]["age_days"] if posts else None

	return {
		"url": url,
		"slug": _slug_of(url),
		"title": title.group(1).strip() if title else None,
		"status": status.group(1).strip() if status else None,
		"closed": "is closed to new replies" in markdown,
		"replies": int(replies.group(1)) if replies else None,
		"participants": int(participants.group(1)) if participants else None,
		"last_reply_from": last_reply_from.group(1).strip() if last_reply_from else None,
		"last_activity_relative": last_activity.group(1).strip() if last_activity else None,
		"last_post_id": int(last_post_id.group(1)) if last_post_id else None,
		"opened_age_days": opening_age,
		"in_reply_window": not ("is closed to new replies" in markdown),
		"posts": posts,
		"raw_md": markdown,
	}


# ------------------------------------------------------------------------- cli


def main(argv: list[str]) -> int:
	# Forum posts are full of smart quotes and non-Latin text; on Windows the console
	# default (cp1252) would mangle them into invalid JSON when redirected to a file.
	sys.stdout.reconfigure(encoding="utf-8", newline="\n")
	sys.stderr.reconfigure(encoding="utf-8")

	parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
	sub = parser.add_subparsers(dest="command", required=True)

	list_parser = sub.add_parser("list", help="list topics from a forum view")
	list_parser.add_argument("--view", choices=["unresolved", "active", "reviews"], default="unresolved")
	list_parser.add_argument("--pages", type=int, default=1, help="pages to walk (30 rows each)")
	list_parser.add_argument("--stars", help="reviews only: comma-separated star buckets, e.g. 1,2")
	list_parser.add_argument(
		"--window-only",
		action="store_true",
		help="drop topics past the 6-month reply window",
	)

	topic_parser = sub.add_parser("topic", help="read one topic and its replies")
	topic_parser.add_argument("reference", help="permalink from `list`, or a bare slug")

	args = parser.parse_args(argv)

	try:
		if args.command == "list":
			stars = [int(s) for s in args.stars.split(",")] if args.stars else None
			if stars and args.view != "reviews":
				print("--stars only applies to --view reviews", file=sys.stderr)
				return 2
			rows = fetch_list(args.view, args.pages, stars)
			if args.window_only:
				rows = [row for row in rows if row["in_reply_window"]]
			payload: object = rows
		else:
			payload = fetch_topic(args.reference)
	except NotFound as error:
		print(
			f"not found: {error}\n"
			"Topic URLs must come from `list` output — slugs cannot be guessed from titles.",
			file=sys.stderr,
		)
		return 1

	json.dump(payload, sys.stdout, indent=2, ensure_ascii=False)
	sys.stdout.write("\n")
	return 0


if __name__ == "__main__":
	sys.exit(main(sys.argv[1:]))
