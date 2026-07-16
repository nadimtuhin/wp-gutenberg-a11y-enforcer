<?php
/**
 * Real-time Contrast Checker — Issue #6.
 *
 * Server-side: exposes a REST endpoint for ratio queries.
 * Client-side: enqueues a JS file that reads Gutenberg's color palette and
 * warns in real-time when text/background combinations fail WCAG AA (4.5:1
 * for normal text, 3:1 for large text / UI components).
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/contrast-ratio?fg=%23ffffff&bg=%23000000
 *   → { "ratio": 21.0, "passes_aa": true, "passes_aaa": true }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class ContrastChecker {

    /** Minimum contrast ratio for WCAG AA normal text. */
    public const WCAG_AA_NORMAL = 4.5;

    /** Minimum contrast ratio for WCAG AA large text / UI components. */
    public const WCAG_AA_LARGE = 3.0;

    /** Minimum contrast ratio for WCAG AAA normal text. */
    public const WCAG_AAA_NORMAL = 7.0;

    /**
     * Register hooks.
     */
    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    /**
     * Register REST route.
     */
    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/contrast-ratio',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restContrastRatio' ],
                'permission_callback' => '__return_true', // public — no auth needed for math.
                'args'                => [
                    'fg' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'bg' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * REST callback: compute contrast ratio between two hex colors.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function restContrastRatio( \WP_REST_Request $request ): \WP_REST_Response {
        $fg = (string) $request->get_param( 'fg' );
        $bg = (string) $request->get_param( 'bg' );

        $fg_rgb = $this->hexToRgb( $fg );
        $bg_rgb = $this->hexToRgb( $bg );

        if ( null === $fg_rgb || null === $bg_rgb ) {
            return new \WP_REST_Response(
                [ 'error' => 'Invalid hex color. Use format #RRGGBB or RRGGBB.' ],
                400
            );
        }

        $ratio = $this->contrastRatio( $fg_rgb, $bg_rgb );

        return new \WP_REST_Response(
            [
                'ratio'       => round( $ratio, 2 ),
                'passes_aa'   => $ratio >= self::WCAG_AA_NORMAL,
                'passes_aa_large' => $ratio >= self::WCAG_AA_LARGE,
                'passes_aaa'  => $ratio >= self::WCAG_AAA_NORMAL,
            ],
            200
        );
    }

    /**
     * Calculate WCAG contrast ratio between two sRGB triplets.
     *
     * @param int[] $fg [r, g, b] 0–255
     * @param int[] $bg [r, g, b] 0–255
     * @return float
     */
    public function contrastRatio( array $fg, array $bg ): float {
        $l1 = $this->relativeLuminance( $fg );
        $l2 = $this->relativeLuminance( $bg );

        $lighter = max( $l1, $l2 );
        $darker  = min( $l1, $l2 );

        return ( $lighter + 0.05 ) / ( $darker + 0.05 );
    }

    /**
     * Calculate relative luminance from sRGB triplet.
     *
     * @param int[] $rgb [r, g, b] 0–255
     * @return float 0–1
     */
    public function relativeLuminance( array $rgb ): float {
        $channels = array_map( function ( int $c ): float {
            $srgb = $c / 255;
            return $srgb <= 0.03928
                ? $srgb / 12.92
                : ( ( $srgb + 0.055 ) / 1.055 ) ** 2.4;
        }, $rgb );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Parse a hex color string (#RRGGBB or RRGGBB) to [r, g, b].
     *
     * @param string $hex
     * @return int[]|null
     */
    public function hexToRgb( string $hex ): ?array {
        $hex = ltrim( $hex, '#' );
        if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
            return null;
        }
        return [
            hexdec( substr( $hex, 0, 2 ) ),
            hexdec( substr( $hex, 2, 2 ) ),
            hexdec( substr( $hex, 4, 2 ) ),
        ];
    }

    /**
     * Enqueue the real-time contrast checker editor JS.
     */
    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-contrast-checker',
            \plugins_url( 'assets/js/contrast-checker.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-compose', 'wp-block-editor', 'wp-hooks', 'wp-i18n' ],
            '1.3.0',
            true
        );
        \wp_localize_script(
            'gae-contrast-checker',
            'gaeContrast',
            [
                'restUrl'    => \rest_url( 'gae/v1/contrast-ratio' ),
                'wcagAA'     => self::WCAG_AA_NORMAL,
                'wcagAALarge'=> self::WCAG_AA_LARGE,
                'wcagAAA'    => self::WCAG_AAA_NORMAL,
            ]
        );
    }
}
