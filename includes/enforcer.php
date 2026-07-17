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

        /**
         * Filter the rules applied to a specific block before validation.
         *
         * @param array  $rules      Array of rule slugs for this block.
         * @param string $block_name The block name (e.g. 'core/image').
         * @param array  $block      The full parsed block array.
         */
        $rules      = \apply_filters( 'gae_block_rules', $config[ $block_name ] ?? [], $block_name, $block );

        /**
         * Filter the list of block types to skip during validation entirely.
         *
         * @param string[] $ignored Array of block names to skip (default empty).
         */
        $ignored = \apply_filters( 'gae_ignored_blocks', [] );
        if ( in_array( $block_name, $ignored, true ) ) {
            return [];
        }

        $violations = [];

        /**
         * Fires before a block is validated against its rules.
         *
         * @param array $block The parsed block array.
         */
        \do_action( 'gae_before_validate_block', $block );

        foreach ( $rules as $rule ) {
            switch ( $rule ) {
                case 'require_alt':
                    if ( empty( $attrs['alt'] ) ) {
                        $msg = "core/image: missing alt text (WCAG 1.1.1).";
                        /**
                         * Filter a violation message for a specific block and rule.
                         *
                         * @param string $msg   The default violation message.
                         * @param array  $block The parsed block array.
                         * @param string $rule  The rule slug that triggered the violation.
                         */
                        $violations[] = \apply_filters( 'gae_violation_message', $msg, $block, 'require_alt' );
                    }
                    break;

                case 'require_link_text':
                    // Button: check 'text' attr or inner HTML is non-empty.
                    $text = $attrs['text'] ?? ( $block['innerHTML'] ?? '' );
                    if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
                        $msg = "core/button: missing link text (WCAG 2.4.6).";
                        /** @see gae_violation_message */
                        $violations[] = \apply_filters( 'gae_violation_message', $msg, $block, 'require_link_text' );
                    }
                    break;

                case 'require_non_empty_text':
                    $text = $attrs['content'] ?? ( $block['innerHTML'] ?? '' );
                    if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
                        $msg = "core/heading: heading must not be empty (WCAG 2.4.6).";
                        /** @see gae_violation_message */
                        $violations[] = \apply_filters( 'gae_violation_message', $msg, $block, 'require_non_empty_text' );
                    }
                    break;
            }
        }

        /**
         * Fires after a block has been validated.
         *
         * @param array    $block      The parsed block array.
         * @param string[] $violations Array of violation messages (empty = valid).
         */
        \do_action( 'gae_after_validate_block', $block, $violations );

        /**
         * Filter the final violations list for a block.
         *
         * @param string[] $violations Violation messages.
         * @param array    $block      The parsed block array.
         */
        return \apply_filters( 'gae_block_violations', $violations, $block );
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

        /**
         * Fires before content blocks are validated.
         *
         * @param array[] $blocks  Parsed blocks array.
         * @param int     $post_id The current post ID.
         */
        \do_action( 'gae_before_filter_content', $blocks, $post_id );

        // Issue #9: heading-hierarchy — log violations but do not strip blocks.
        $hierarchy_violations = $this->checkHeadingHierarchy( $blocks );
        if ( ! empty( $hierarchy_violations ) ) {
            /**
             * Fires when a heading hierarchy violation is detected.
             *
             * @param string[] $hierarchy_violations List of violation messages.
             * @param int      $post_id              The current post ID.
             */
            \do_action( 'gae_heading_hierarchy_violation', $hierarchy_violations, $post_id );
            if ( $this->log && $post_id ) {
                foreach ( $hierarchy_violations as $msg ) {
                    $this->log->log( $post_id, 'core/heading', 'heading_hierarchy', $msg );
                }
            }
        }

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
     * Check heading hierarchy across an array of blocks (WCAG 1.3.1).
     * Flags any heading whose level skips more than one step up from the
     * previous heading (e.g. H2 → H4). Descending back (H3 → H2) is allowed.
     *
     * Violations are logged but blocks are NOT stripped — the content is
     * preserved; only a log entry is written.
     *
     * @param array[] $blocks
     * @return string[] Violation messages (empty = no hierarchy issues).
     */
    public function checkHeadingHierarchy( array $blocks ): array {
        $violations = [];
        $prev_level = 0;

        foreach ( $blocks as $block ) {
            if ( ( $block['blockName'] ?? '' ) !== 'core/heading' ) {
                continue;
            }

            $level = (int) ( $block['attrs']['level'] ?? 2 );

            if ( $prev_level > 0 && $level > $prev_level + 1 ) {
                $violations[] = sprintf(
                    'core/heading: heading level skips from H%d to H%d (WCAG 1.3.1).',
                    $prev_level,
                    $level
                );
            }

            $prev_level = $level;
        }

        return $violations;
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
