# Changelog

## 1.0.5

- Keep timed exams idle until required participant fields are confirmed with an explicit start action.
- Render gated exam questions and the submit button hidden in the initial HTML to prevent stale or delayed scripts from exposing the first question.
- Version public Pulse assets from their modification time so browsers fetch updated JavaScript and CSS after deployment.

## 1.0.3

- Render no-JavaScript quiz responses with the correct `pulse--quiz` wrapper.
- Include the certificate download link in server-rendered passing results.
- Add a responsive viewport declaration to standalone certificates.
- Add a CLI smoke test for certificate tokens and fallback rendering.

## 1.0.2

- Synchronize the builder payload immediately after question, option, or outcome image uploads and removals.
- Show a clear reminder that the item must be saved after changing an image.
- Add a reusable CLI smoke test for persisted question images and public rendering.

## v1.0.1 - Module directory entry point

### Fixed

- Added `Pulse.module.php` as the main module entry point so the ProcessWire
  modules directory can load module metadata from GitHub.
- Moved the internal item model to `src/PulseItem.php` to avoid a class-name
  conflict with the main `Pulse` module.

## v1.0.0 - First public release

Initial public release of Pulse for ProcessWire.

### Added

- Polls and quizzes embedded with Vox-style tokens:
  `[[pulse:poll name="name"]]` and `[[pulse:quiz name="name"]]`.
- Cache-safe frontend widgets hydrated through `/pulse/state` and submitted
  through `/pulse/submit`.
- Poll features: single/multiple choice, min/max selection, Other free text,
  live results, result visibility settings, duplicate protection, and sharing.
- Quiz modes:
  - graded quizzes with points, pass threshold, answer review, and result
    messages by score range;
  - personality quizzes with outcome scoring and range/highest result modes;
  - exams with timer, attempt limit, random question bank, pass threshold, and
    certificate-style result flow.
- Question-level images, option images, and outcome images.
- Admin builder for creating and editing polls/quizzes, including preview,
  import/export, clone, analytics, retention tools, and notification settings.
- Notification provider selection through ProcessWire mail modules.
- Analytics screens for poll totals and quiz starts/completions, score,
  pass rate, question performance, drop-off, and outcome distribution.
- CSV answer export and portable JSON definition import/export.
- GDPR retention action for anonymizing or deleting old submissions.
- Demo installer that creates demo items, demo assets, the `pulse` field,
  `pulse-demo` template, and public pages under `/pulse-demo/`.
- Bundled demos:
  - Planet mission vote;
  - Car logo recognition quiz;
  - Hollywood movie quiz;
  - Spacecraft personality quiz;
  - Launch operations certification.

### Security

- Server-side answer validation and scoring; answer keys are not exposed before
  submission.
- CSRF-protected public submissions with hydrated per-request token.
- Honeypot and rate limiting by hashed IP.
- Strict public item name validation.
- Safe image upload validation using file extension and actual raster MIME.
- CSV formula neutralization for exported lead fields.
- Signed exam randomization and certificate tokens.

### Notes

- Module version is `1.0.0`.
- Versioning follows SemVer: Major.Minor.Patch.
- Requires ProcessWire 3.0+ and PHP 8.2+.
- For cached pages, JavaScript is required for submissions because CSRF is
  hydrated per visitor.
