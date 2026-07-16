<?php
/**
 * Plugin Name: WP Gutenberg A11y Enforcer
 * Description: Hooks block saving process to check accessibility compliance — server-side (content_save_pre), client-side (Gutenberg JS filter), and admin settings with validation log export.
 * Version: 1.2.0
 * Author: Omar Faruque Tuhin (Nadim)
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-gutenberg-a11y-enforcer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/validation-log.php';
require_once __DIR__ . '/includes/enforcer.php';
require_once __DIR__ . '/includes/ai-alt-text.php';
require_once __DIR__ . '/includes/schema-validator.php';
require_once __DIR__ . '/includes/contrast-checker.php';

// Bootstrap.
$gae_log      = new \GutenbergA11yEnforcer\ValidationLog();
$gae_settings = new \GutenbergA11yEnforcer\Settings();
$gae_enforcer = new \GutenbergA11yEnforcer\Enforcer( $gae_log );

$gae_enforcer->register();
$gae_settings->register();
$gae_log->register();

// Issue #4: AI alt-text suggestion API.
( new \GutenbergA11yEnforcer\AiAltText() )->register();

// Issue #5: Schema validation filter.
( new \GutenbergA11yEnforcer\SchemaValidator() )->register();

// Issue #6: Real-time contrast checker.
( new \GutenbergA11yEnforcer\ContrastChecker() )->register();

// Create/upgrade log table on load (cheap version-gated check).
$gae_log->maybeCreateTable();

register_activation_hook( __FILE__, function () use ( $gae_log ) {
    $gae_log->maybeCreateTable();
} );
