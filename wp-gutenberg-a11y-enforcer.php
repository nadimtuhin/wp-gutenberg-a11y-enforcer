<?php
/**
 * Plugin Name: WP Gutenberg A11y Enforcer
 * Description: Hooks block saving process to check accessibility compliance.
 * Version: 1.0.0
 * Author: Omar Faruque Tuhin (Nadim)
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/enforcer.php';
