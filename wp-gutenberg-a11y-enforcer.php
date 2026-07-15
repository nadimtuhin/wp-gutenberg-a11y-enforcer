<?php
/**
 * Plugin Name: WP Gutenberg A11y Enforcer
 * Description: Hooks block saving process to check accessibility compliance — both server-side (content_save_pre) and client-side (Gutenberg JS filter).
 * Version: 1.1.0
 * Author: Omar Faruque Tuhin (Nadim)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/enforcer.php';

// Bootstrap the plugin hooks.
$enforcer = new \GutenbergA11yEnforcer\Enforcer();
$enforcer->register();
