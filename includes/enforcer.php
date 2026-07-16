<?php
/**
 * Core enforcer: validates blocks against configured a11y rules.
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class Enforcer {

    /** @var array|null Cached config (block => [rules]) */
    private ?array $config = null;

    /** @var ValidationLog|null */
    private ?ValidationLog $log = null;

    public function __construct( ?ValidationLog $log = null ) {
        $this->log = $log;
    }

    /**
     * Lazy-load config; callable injected for tests.
     */
    private function getConfig(): array {
        if ( $this->config === null ) {
            $this->config = function_exists( 'get_option' )
                ? Settings::getConfig()
                : Settings::defaults();
        }
        return $this->config;
    }

    /**
     * Override config (used in tests).
     */
    public function setConfig( array $config ): void {
        $this->config = $config;
    }

    /**
     * Validate a single parsed block array.
     * Returns array of violation messages (empty = valid).
     *
     * @param array $block Parsed block array from parse_blocks().
     * @return string[]
     */
    public function getViolations( array $block ): array {
        $config     = $this->getConfig();
        $block_name = $block['blockName'] ?? '';
        $attrs      = $block['attrs'] ?? [];
        $rules      = $config[ $block_name ] ?? [];
        $violations = [];

        foreach ( $rules as $rule ) {
            switch ( $rule ) {
                case 'require_alt':
                    if ( empty( $attrs['alt'] ) ) {
                        $violations[] = "core/image: missing alt text (WCAG 1.1.1).";
                    }
                    break;

                case 'require_link_text':
                    // Button: check 'text' attr or inner HTML is non-empty.
                    $text = $attrs['text'] ?? ( $block['innerHTML'] ?? '' );
                    if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
                        $violations[] = "core/button: missing link text (WCAG 2.4.6).";
                    }
                    break;

                case 'require_non_empty_text':
                    $text = $attrs['content'] ?? ( $block['innerHTML'] ?? '' );
                    if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
                        $violations[] = "core/heading: heading must not be empty (WCAG 2.4.6).";
                    }
                    break;
            }
        }

        return $violations;
    }

    /**
     * Validate block; returns false if any violations found.
     * BC-compatible with original API.
     */
    public function validateBlock( array $block ): bool {
        return count( $this->getViolations( $block ) ) === 0;
    }

    /**
     * Try to auto-fix a core/image block missing alt text.
     * Applies the `gae_alt_autofixer` filter so third-party code can
     * supply a generated alt string (e.g. from AI or attachment meta).
     *
     * Filter signature:
     *   apply_filters( 'gae_alt_autofixer', string $alt, array $block ): string
     *
     * If the filter returns a non-empty string, it is written into
     * `$block['attrs']['alt']` before the block is validated. When the
     * filter returns '' (the default) the block is returned unchanged.
     *
     * @param array $block Parsed block array.
     * @return array Block, possibly with alt injected.
     */
    public function maybeAutoFixAlt( array $block ): array {
        if ( ( $block['blockName'] ?? '' ) !== 'core/image' ) {
            return $block;
        }
        if ( ! empty( $block['attrs']['alt'] ) ) {
            return $block;
        }

        /** @var string $alt */
        $alt = \apply_filters( 'gae_alt_autofixer', '', $block );
        if ( '' !== trim( $alt ) ) {
            $block['attrs']['alt'] = sanitize_text_field( $alt );
        }

        return $block;
    }

    /**
     * Whether block validation should be skipped for a given post.
     * Reads the `_gae_bypass_validation` post meta key (value '1').
     *
     * Issue #8.
     */
    public function isBypassedForPost( int $post_id ): bool {
        if ( $post_id <= 0 ) {
            return false;
        }
        if ( ! function_exists( 'get_post_meta' ) ) {
            return false;
        }
        return '1' === \get_post_meta( $post_id, Settings::BYPASS_META_KEY, true );
    }

    /**
     * Scan post content, strip non-compliant blocks, log violations.
     * Hooked to `content_save_pre`.
     */
    public function filterContent( string $content ): string {
        if ( ! function_exists( 'parse_blocks' ) ) {
            return $content;
        }

        $post_id = $this->getCurrentPostId();

        // Issue #8: skip all validation when bypass meta is set.
        if ( $this->isBypassedForPost( $post_id ) ) {
            return $content;
        }

        $blocks  = parse_blocks( $content );

        // Apply alt auto-fixer before validation (Issue #7).
        $blocks = array_map( [ $this, 'maybeAutoFixAlt' ], $blocks );

        $kept    = [];

        foreach ( $blocks as $block ) {
            $violations = $this->getViolations( $block );
            if ( empty( $violations ) ) {
                $kept[] = $block;
            } else {
                if ( $this->log && $post_id ) {
                    foreach ( $violations as $msg ) {
                        $rule = $this->ruleFromMessage( $msg );
                        $this->log->log( $post_id, $block['blockName'] ?? '', $rule, $msg );
                    }
                }
            }
        }

        return $this->serializeBlocks( $kept );
    }

    /**
     * Serialise block array back to HTML.
     */
    public function serializeBlocks( array $blocks ): string {
        if ( function_exists( 'serialize_blocks' ) ) {
            return serialize_blocks( $blocks );
        }

        $output = '';
        foreach ( $blocks as $block ) {
            $output .= isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
        }
        return $output;
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_filter( 'content_save_pre', [ $this, 'filterContent' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    /**
     * Enqueue the Gutenberg editor JS.
     */
    public function enqueueEditorScript(): void {
        $asset_file = plugin_dir_path( __DIR__ ) . 'assets/js/editor.asset.php';
        $deps       = [ 'wp-blocks', 'wp-hooks', 'wp-i18n' ];
        $version    = '1.2.0';

        if ( file_exists( $asset_file ) ) {
            $asset   = require $asset_file;
            $deps    = $asset['dependencies'];
            $version = $asset['version'];
        }

        // Pass current config to JS.
        $config = $this->getConfig();
        wp_enqueue_script(
            'wp-gutenberg-a11y-enforcer-editor',
            plugins_url( 'assets/js/editor.js', __DIR__ . '/wp-gutenberg-a11y-enforcer.php' ),
            $deps,
            $version,
            true
        );
        wp_localize_script(
            'wp-gutenberg-a11y-enforcer-editor',
            'gaeConfig',
            [ 'blockRules' => $config ]
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function getCurrentPostId(): int {
        $post_id = 0;
        if ( function_exists( 'get_the_ID' ) ) {
            $post_id = (int) get_the_ID();
        }
        // Fallback for REST saves.
        if ( ! $post_id && isset( $_POST['post_ID'] ) ) {
            $post_id = absint( $_POST['post_ID'] );
        }
        return $post_id;
    }

    private function ruleFromMessage( string $msg ): string {
        if ( strpos( $msg, 'alt' ) !== false ) {
            return 'require_alt';
        }
        if ( strpos( $msg, 'link text' ) !== false ) {
            return 'require_link_text';
        }
        if ( strpos( $msg, 'heading' ) !== false ) {
            return 'require_non_empty_text';
        }
        return 'unknown';
    }
}
