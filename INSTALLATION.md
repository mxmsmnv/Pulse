# Pulse — Installation

## Requirements

- ProcessWire 3.0.0 or newer
- PHP 8.2+
- MySQL/MariaDB with InnoDB and JSON column support (MySQL 5.7+ / MariaDB 10.2+)

## Install

1. Copy the `Pulse/` directory into `/site/modules/`.
2. In the admin: **Modules → Refresh**.
3. Find **Pulse** in the modules list and click **Install**.

On install the module:

- creates the database tables `pulse`, `pulse_questions`, `pulse_options`,
  `pulse_outcomes`, `pulse_submissions`, `pulse_answers`;
- creates the permissions `pulse` (view) and `pulse-edit` (create/edit/delete);
- installs the `TextformatterPulse` text formatter;
- generates a per-site hash salt (for voter/IP hashing);
- creates the admin page **Setup → Pulse**.

Grant `pulse` / `pulse-edit` to the relevant roles under **Access → Roles**.

## Configure

**Modules → Configure → Pulse Admin**:

| Setting | Default | Purpose |
|---|---|---|
| Components path | `components/` | Folder (under `/site/templates/`) for custom render templates |
| Endpoint base | `pulse` | URL prefix for `/pulse/state`, `/pulse/submit`, … |
| Embed tokens | `[[pulse:poll name="..."]]` | Vox-style Textformatter tokens |
| Rate limit / window | `10` / `60s` | Max submit requests per visitor per window (0 = off) |
| Data retention (days) | `0` | For reference; retention is run per-item from the analytics screen |
| Hash salt | generated | Salt for voter cookie/IP hashing — keep secret |
| Debug mode | off | Verbose logging to `pulse-debug` |

## Enable embedding

Add the **TextformatterPulse** formatter to each field you want to embed into:

**Setup → Fields → `<field>` → Details → Text Formatters → add “Pulse Text Formatter”.**

Then embed published items with `[[pulse:poll name="name"]]` or `[[pulse:quiz name="name"]]`.

## Try the examples

Use **Setup → Pulse → Install demo** to import the bundled poll, graded quiz,
personality quiz, and exam examples in one click. You can still import a single
example manually via **Import JSON** and a file from the `examples/` folder.

## Front-end assets

`pulse.css` and `pulse.js` are injected automatically on pages that render a
widget — no manual `$config->scripts`/`styles` wiring needed.

## Uninstall

**Modules → Pulse → Uninstall** drops the tables, removes the permissions and
the admin page. Uploaded option/outcome images under `/site/assets/Pulse/` are
left in place; remove them manually if desired.

## Logs

- `pulse-errors` — render/submit/notify errors (always on).
- `pulse-debug` — verbose flow (when Debug mode is enabled).

Found under **Setup → Logs**.
