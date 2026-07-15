# Roadmap

## v0.2 — Admin Configuration UI

**Goal:** Let site admins configure validation rules without touching code.

- WP Admin settings page under Settings > A11y Enforcer
- Toggle individual checks on/off per block type
- Set severity levels (error vs. warning) per rule
- Store config in `wp_options`

## v0.3 — Expanded Block Coverage

**Goal:** Support all common core blocks beyond the initial set.

- `core/image` — alt text presence and quality hints
- `core/table` — require caption and header scope attributes
- `core/video` — require captions track
- `core/audio` — require transcript or description
- `core/button` — disallow non-descriptive labels ("Click here", "Read more")
- `core/columns` / `core/group` — landmark role suggestions

## v0.4 — Validation Log Export

**Goal:** Give editors and auditors a history of a11y violations.

- Log each save event with block type, rule, severity, post ID, and timestamp
- Export log as CSV from WP Admin
- REST endpoint `GET /wp-json/a11y-enforcer/v1/logs` for external tooling
- Log retention setting (default 90 days), with auto-prune cron

## v1.0 — Stable Release

- Full test coverage (unit + integration)
- Comprehensive inline documentation
- Accessibility statement for the plugin itself
- Submission to wordpress.org plugin directory

## Backlog / Ideas

- WCAG level selector (A / AA / AAA) as a global setting
- Per-role enforcement (e.g. enforce for Editor, warn-only for Contributor)
- WP-CLI command: `wp a11y-enforcer scan <post_id>`
- GitHub Actions CI workflow included in plugin zip
