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
require_once __DIR__ . '/includes/screen-reader-simulator.php';
// Issues #13-#25.
require_once __DIR__ . '/includes/block-hierarchy.php';
require_once __DIR__ . '/includes/voiceover-simulator.php';
require_once __DIR__ . '/includes/rule-profile.php';
require_once __DIR__ . '/includes/accessibility-score.php';
require_once __DIR__ . '/includes/revision-diff.php';
require_once __DIR__ . '/includes/bulk-validator.php';
require_once __DIR__ . '/includes/template-validator.php';
require_once __DIR__ . '/includes/trend-chart.php';
require_once __DIR__ . '/includes/wcag-aaa-profile.php';
require_once __DIR__ . '/includes/third-party-adapter.php';
require_once __DIR__ . '/includes/alt-bulk-editor.php';
require_once __DIR__ . '/includes/video-captioning.php';
require_once __DIR__ . '/includes/wcag-em-export.php';

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

// Issue #13: Block hierarchy sidebar.
( new \GutenbergA11yEnforcer\BlockHierarchy() )->register();

// Issue #14: Voice-over simulator.
( new \GutenbergA11yEnforcer\VoiceOverSimulator() )->register();

// Issue #15: Dynamic rule profiles.
( new \GutenbergA11yEnforcer\RuleProfile() )->register();

// Issue #16: Block-level accessibility score badge.
( new \GutenbergA11yEnforcer\AccessibilityScore( $gae_enforcer ) )->register();

// Issue #17: Revision diff accessibility comparison.
( new \GutenbergA11yEnforcer\RevisionDiff() )->register();

// Issue #18: WP-CLI bulk post validation.
( new \GutenbergA11yEnforcer\BulkValidator( $gae_enforcer ) )->register();

// Issue #19: Block template validation on theme switch.
( new \GutenbergA11yEnforcer\TemplateValidator( $gae_enforcer, $gae_log ) )->register();

// Issue #20: Score trend chart.
( new \GutenbergA11yEnforcer\TrendChart() )->register();

// Issue #21: WCAG AAA profile.
( new \GutenbergA11yEnforcer\WcagAaaProfile() )->register();

// Issue #22: Third-party block adapter.
( new \GutenbergA11yEnforcer\ThirdPartyAdapter() )->register();

// Issue #23: Alt bulk editor.
( new \GutenbergA11yEnforcer\AltBulkEditor() )->register();

// Issue #24: Video captioning enforcement.
( new \GutenbergA11yEnforcer\VideoCaptioning() )->register();

// Issue #25: WCAG-EM export.
( new \GutenbergA11yEnforcer\WcagEmExport( new \GutenbergA11yEnforcer\BulkValidator( $gae_enforcer ) ) )->register();

// Create/upgrade log table on load (cheap version-gated check).
$gae_log->maybeCreateTable();

register_activation_hook( __FILE__, function () use ( $gae_log ) {
    $gae_log->maybeCreateTable();
} );
