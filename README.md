# Pulse

Engagement module for ProcessWire: **polls** and **quizzes** embedded into any
text field via shortcodes and rendered as interactive, cache-safe widgets.

![Pulse](assets/Pulse.png)

- **Polls** — voting with live results and duplicate protection.
- **Quizzes** — three modes:
  - `graded` — points, pass/fail threshold, answer review;
  - `personality` — options award points to outcomes; a winning outcome is shown;
  - `exam` — graded plus timer, attempt limit, question bank, certificate.
- **Visual questions** — question-level images and image options for rich quiz
  flows such as one-image-per-slide tests.
- **Admin workspace** — polished Setup → Pulse screens for building, editing,
  importing, cloning, exporting, reviewing analytics, and installing demos.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).

## Requirements

- ProcessWire 3.0+
- PHP 8.2+

## Install

Copy the `Pulse/` folder to `/site/modules/`, then **Modules → Refresh** and
install **Pulse**. It installs the admin process, the `TextformatterPulse` text
formatter, creates the database tables, the `pulse` / `pulse-edit` permissions,
and the admin page at **Setup → Pulse**. See `INSTALLATION.md`.

## Demo

Use **Setup → Pulse → Install demo** to install a complete public demo set:

- Planet mission vote — multi-select poll with Other and live results.
- Car logo recognition quiz — image options and mixed question types.
- Hollywood movie quiz — one real movie still per slide with score-based ranks.
- Spacecraft personality quiz — outcome mapping.
- Launch operations certification — timed exam with certificate-style flow.

The demo installer also creates a `pulse` field, a `pulse-demo` template, and
public demo pages under `/pulse-demo/`, using ProcessWire markup regions so the
examples can run inside the site shell without fighting the main template.

## Usage

1. **Setup → Pulse → Add poll / Add quiz**, build it, set status to *Published*.
   You can also use **Install demo** to import the bundled poll and quiz examples.
2. Add the `TextformatterPulse` formatter to the text field you embed into
   (e.g. `body`): *Setup → Fields → body → Details → Text Formatters*.
3. Embed with a shortcode:

   ```
   [[pulse:poll name="my-poll"]]
   [[pulse:quiz name="my-quiz"]]
   ```

The endpoint base is configurable in the module settings.

## How it works

- The server renders a **static widget shell** with no per-visitor data, so the
  page stays fully cacheable (ProCache / template cache).
- `pulse.js` hydrates each widget via `GET /pulse/state?name=…` (fresh CSRF
  token, current state, results if already answered) and submits via
  `POST /pulse/submit`.
- The cache-safe shortcode widget requires JavaScript for submission: every
  write requires the CSRF token obtained during hydration. A custom,
  non-cached server template can provide a CSRF input for a no-JS form.

## Features

| Area | Settings |
|---|---|
| Duplicate protection | `dedupe`: `cookie_ip` / `user` / `soft` |
| Poll | `multiple`, `min_select`/`max_select`, `allow_other`, `show_counts`, `result_visibility` |
| Quiz (all) | `mode`, `shuffle_questions`, `shuffle_options`, `pagination`, `progress_bar` |
| Graded/Exam | `pass_percent`, `show_correct`, `result_messages` (range messages) |
| Exam | `time_limit`, `max_attempts`, `pick_random`, `certificate` |
| Personality | `result_mode`: `highest` / `range`; outcomes with point ranges |
| Media | question images, option images, outcome images |
| Video gate | `video`: `provider` (youtube/vimeo/mp4), `src`, `gate` (ended/percent/button) |
| Engagement | `require_fields` (name/email), `notify_admin`, `notify_user`, mail provider, `share` |

### Email merge tags

Notification subject/body support: `{title}`, `{score}`, `{max_score}`,
`{percent}`, `{passed}`, `{outcome}`, `{date}`, `{name}`.

## Security & integrity

- All SQL via prepared statements; answers validated server-side (option
  ownership, required, types).
- Anti-cheat: `is_correct` / `match_value` / `outcome_points` never reach the
  client before submission; all scoring is server-side.
- Mandatory CSRF (token from `/pulse/state`), honeypot, and rate limiting by
  hashed IP on the public submission endpoint.
- Voter cookie tokens and IPs are stored only as salted hashes.
- `pick_random` question sets are HMAC-signed; exam timeouts are enforced and
  recorded server-side; certificates use a signed token and are only issued
  to those who passed.
- Public endpoint item names are strict slugs (`^[a-z][a-z0-9_-]*$`), while
  imports auto-prefix invalid generated names so they remain valid.
- CSV export neutralizes spreadsheet formulas in lead fields.
- Uploaded images are validated as real raster images and constrained to safe
  filenames before rendering.

## Customizing the markup

Provide `/site/templates/components/pulse/<name>.php` (folder configurable) to
override rendering for a specific item. Available variables: `$item`,
`$questions`, `$results`, `$page`, `$config`, `$sanitizer`. To keep the widget
interactive, preserve the contract: root `data-pulse-name` / `data-pulse-kind`,
the honeypot field, and the `answers[...]` field names. A server-only no-JS
variant must be non-cached and include a ProcessWire CSRF token input.
The configured components folder is constrained to safe relative path segments
under `/site/templates`.

## Analytics, export, GDPR

- Per-item analytics: votes/percentages (poll); starts, completions, average
  score, pass rate, % correct per question, drop-off, outcome distribution
  (quiz).
- CSV export of answers; JSON export/import of definitions (with rename on
  conflict) and one-click Clone.
- Personality `outcome_points` in portable JSON use `idx:N` keys; invalid or
  out-of-range indexes are dropped before save. Range outcome scores are
  normalized on import/save payloads.
- Data retention: anonymize (null hashes/lead) or delete submissions older than
  N days.

## Files

```
Pulse.module.php               Main module entry point
ProcessPulse.module.php        Admin, installer, endpoints, config
TextformatterPulse.module.php  [[pulse:poll name="name"]] parser
src/                           Runtime classes and repositories
assets/                        Frontend and admin assets
examples/                      Importable demo definitions and demo assets
```
