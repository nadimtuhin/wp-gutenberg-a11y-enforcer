# WP Gutenberg A11y Enforcer

A WordPress plugin that enforces accessibility (a11y) rules on Gutenberg block content — both **server-side** (PHP, via a `content_save_pre` filter) and **client-side** (JavaScript, via the `blocks.getSaveElement` Gutenberg hook).

---

## Features

| Layer | Mechanism | Effect |
|-------|-----------|--------|
| **Backend (PHP)** | `content_save_pre` filter | Strips non-compliant blocks before they are persisted to the database |
| **Frontend (JS)** | `blocks.getSaveElement` Gutenberg filter | Injects a sentinel element that triggers block validation failure in the editor, preventing save |

### Accessibility rules enforced

- **`core/image`** — must have a non-empty `alt` attribute (WCAG 2.1 SC 1.1.1).

Additional rules can be added to `Enforcer::validateBlock()` (PHP) and `getA11yViolations()` (JS).

---

## Architecture

```
wp-gutenberg-a11y-enforcer/
├── wp-gutenberg-a11y-enforcer.php  ← Plugin entry point; registers WP hooks
├── includes/
│   └── enforcer.php                ← PHP Enforcer class
├── assets/
│   └── js/
│       └── editor.js               ← Gutenberg editor script (JS filter)
└── tests/
    └── EnforcerTest.php            ← PHPUnit test suite
```

### Backend hook: `content_save_pre`

`Enforcer::filterContent()` is registered on `content_save_pre`. When a post is saved:

1. WordPress passes the raw post content (serialised block HTML) through the filter.
2. `parse_blocks()` splits the content into individual block objects.
3. Each block is evaluated by `validateBlock()`.
4. Blocks that fail validation are **dropped**; compliant blocks are re-serialised.
5. The cleaned content is returned to WordPress and written to the database.

```php
add_filter( 'content_save_pre', [ $enforcer, 'filterContent' ] );
```

### Frontend hook: `blocks.getSaveElement`

`assets/js/editor.js` is enqueued via `enqueue_block_editor_assets`. It adds a filter on `blocks.getSaveElement`:

1. For every block being serialised in the editor, `getA11yViolations()` is called.
2. If violations are found, a **hidden sentinel `<div>`** with a `data-a11y-enforcer-violation` attribute is appended to the save element.
3. The modified element differs from the stored markup, causing **Gutenberg block validation failure** (red border + "Attempt Block Recovery" prompt).
4. A `console.warn` message details the violation for developers.

```js
addFilter(
    'blocks.getSaveElement',
    'wp-gutenberg-a11y-enforcer/enforce-a11y',
    enforceA11yOnSave
);
```

> **Note:** The JS layer is a *soft* guard — it surfaces violations inside the editor. The PHP layer is the *hard* guard — it removes non-compliant blocks before they reach the database, regardless of client behaviour.

---

## Requirements

- PHP ≥ 7.4
- WordPress ≥ 5.8 (Gutenberg bundled)
- Composer (for development / testing)

---

## Installation

### As a WordPress plugin

1. Clone or download this repository into your `wp-content/plugins/` directory:

   ```bash
   git clone https://github.com/nadimtuhin/wp-gutenberg-a11y-enforcer.git \
       wp-content/plugins/wp-gutenberg-a11y-enforcer
   ```

2. Activate the plugin from the WordPress admin dashboard or via WP-CLI:

   ```bash
   wp plugin activate wp-gutenberg-a11y-enforcer
   ```

### Development setup

```bash
cd wp-content/plugins/wp-gutenberg-a11y-enforcer
composer install
```

---

## Running Tests

The test suite runs outside a WordPress installation using lightweight function stubs.

```bash
./vendor/bin/phpunit
```

Expected output:

```
PHPUnit 9.x by Sebastian Bergmann and contributors.

............                                    12 / 12 (100%)

Time: ~0s, Memory: 6.00 MB

OK (12 tests, 15 assertions)
```

### Test coverage

| Test | Description |
|------|-------------|
| `testBlockValidationPassesWithAlt` | `core/image` with alt → passes |
| `testBlockValidationFailsWithoutAlt` | `core/image` no alt → fails |
| `testBlockValidationFailsWithEmptyAlt` | `core/image` empty alt → fails |
| `testNonImageBlockAlwaysPasses` | Non-image block always passes |
| `testFilterContentStripsImageBlockMissingAlt` | `filterContent` removes bad image block |
| `testFilterContentKeepsImageBlockWithAlt` | `filterContent` keeps valid image block |
| `testFilterContentKeepsParagraphBlocks` | `filterContent` keeps unrelated blocks |
| `testFilterContentHandlesMixedBlocks` | Mixed content: strips bad, keeps good |
| `testFilterContentReturnsOriginalWhenParseBlocksMissing` | Safe when WP parser absent |
| `testSerializeBlocksProducesBlockComments` | Serialiser produces correct WP block comments |
| `testRegisterDoesNotThrow` | `register()` hooks cleanly |
| `testEnqueueEditorScriptDoesNotThrow` | Script enqueueing doesn't throw |

---

## Extending

### Add a new PHP rule

In `includes/enforcer.php`, extend `validateBlock()`:

```php
public function validateBlock( array $block ): bool {
    if ( $block['blockName'] === 'core/image' && empty( $block['attrs']['alt'] ) ) {
        return false;
    }
    // Example: require heading blocks to have non-empty content
    if ( $block['blockName'] === 'core/heading' && empty( $block['innerHTML'] ) ) {
        return false;
    }
    return true;
}
```

### Add a new JS rule

In `assets/js/editor.js`, extend `getA11yViolations()`:

```js
function getA11yViolations( blockName, attributes ) {
    const violations = [];
    if ( blockName === 'core/image' && ! attributes.alt ) {
        violations.push( 'core/image missing alt text.' );
    }
    // Example: warn on decorative images without explicit role
    if ( blockName === 'core/image' && attributes.alt === '' && ! attributes.decorative ) {
        violations.push( 'core/image with empty alt must mark the image as decorative.' );
    }
    return violations;
}
```

---

## Contributing

1. Fork the repo and create a feature branch.
2. Write a failing test first (TDD).
3. Implement the change.
4. Run `./vendor/bin/phpunit` — all tests must pass.
5. Open a pull request.

---

## License

MIT
