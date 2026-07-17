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
                'permission_callback' => function() {
                    // Issue #32: require at least edit_posts to prevent public exposure.
                    // Pure math, but limits scrapers and DoS surface.
                    return \current_user_can( 'edit_posts' );
                },
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

        /**
         * Filter the WCAG AA contrast ratio threshold for normal text.
         *
         * @param float $threshold Minimum ratio (default 4.5).
         */
        $aa_threshold = (float) \apply_filters( 'gae_contrast_ratio_threshold_aa', self::WCAG_AA_NORMAL );

        /**
         * Filter the WCAG AA contrast ratio threshold for large text/UI components.
         *
         * @param float $threshold Minimum ratio (default 3.0).
         */
        $aa_large_threshold = (float) \apply_filters( 'gae_contrast_ratio_threshold_aa_large', self::WCAG_AA_LARGE );

        /**
         * Filter the WCAG AAA contrast ratio threshold.
         *
         * @param float $threshold Minimum ratio (default 7.0).
         */
        $aaa_threshold = (float) \apply_filters( 'gae_contrast_ratio_threshold_aaa', self::WCAG_AAA_NORMAL );

        return new \WP_REST_Response(
            [
                'ratio'           => round( $ratio, 2 ),
                'passes_aa'       => $ratio >= $aa_threshold,
                'passes_aa_large' => $ratio >= $aa_large_threshold,
                'passes_aaa'      => $ratio >= $aaa_threshold,
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

    // ── Contrast Auto-Adjuster (Issue #12) ──────────────────────────────────

    /**
     * Adjust a foreground hex color until it passes WCAG AA against a given
     * background.  Darkens or lightens the foreground in small steps until the
     * contrast ratio meets $min_ratio (default: WCAG AA normal = 4.5:1).
     *
     * Returns the adjusted hex string (with #) on success, or null when a
     * passing color cannot be found within the search space (should be very
     * rare — black on white is always 21:1, white on black always 21:1).
     *
     * Algorithm: binary search between the original color and black (to darken)
     * first; if that doesn't reach the target, tries the other direction
     * (lighten toward white).  This preserves hue while adjusting luminance.
     *
     * @param string $fg_hex     Foreground color e.g. '#767676' or '767676'.
     * @param string $bg_hex     Background color.
     * @param float  $min_ratio  Minimum ratio (default WCAG_AA_NORMAL = 4.5).
     * @return string|null       Adjusted '#RRGGBB' or null if impossible.
     */
    public function adjustForContrast(
        string $fg_hex,
        string $bg_hex,
        float $min_ratio = self::WCAG_AA_NORMAL
    ): ?string {
        $fg = $this->hexToRgb( $fg_hex );
        $bg = $this->hexToRgb( $bg_hex );

        if ( $fg === null || $bg === null ) {
            return null;
        }

        // Already passes — return original.
        if ( $this->contrastRatio( $fg, $bg ) >= $min_ratio ) {
            return '#' . strtolower( ltrim( $fg_hex, '#' ) );
        }

        // Try darkening first, then lightening.
        $result = $this->binarySearchColor( $fg, [ 0, 0, 0 ], $bg, $min_ratio )
            ?? $this->binarySearchColor( $fg, [ 255, 255, 255 ], $bg, $min_ratio );

        return $result;
    }

    /**
     * Binary search between $start RGB and $target RGB until contrast passes.
     *
     * @param int[] $start      Original foreground [r,g,b].
     * @param int[] $target     Direction: [0,0,0] to darken, [255,255,255] to lighten.
     * @param int[] $bg         Background [r,g,b].
     * @param float $min_ratio
     * @return string|null  '#RRGGBB' or null.
     */
    private function binarySearchColor(
        array $start,
        array $target,
        array $bg,
        float $min_ratio
    ): ?string {
        // Quick bail: if the extreme end of the search direction doesn't pass, give up.
        if ( $this->contrastRatio( $target, $bg ) < $min_ratio ) {
            return null;
        }

        $lo = 0.0;
        $hi = 1.0;

        for ( $i = 0; $i < 20; $i++ ) {
            $mid  = ( $lo + $hi ) / 2.0;
            $candidate = $this->blendRgb( $start, $target, $mid );
            if ( $this->contrastRatio( $candidate, $bg ) >= $min_ratio ) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        $best = $this->blendRgb( $start, $target, $hi );
        return $this->rgbToHex( $best );
    }

    /**
     * Linear interpolation between two RGB triplets.
     *
     * @param int[]  $a    Start color.
     * @param int[]  $b    End color.
     * @param float  $t    0.0 = $a, 1.0 = $b.
     * @return int[]
     */
    private function blendRgb( array $a, array $b, float $t ): array {
        return [
            (int) round( $a[0] + ( $b[0] - $a[0] ) * $t ),
            (int) round( $a[1] + ( $b[1] - $a[1] ) * $t ),
            (int) round( $a[2] + ( $b[2] - $a[2] ) * $t ),
        ];
    }

    /**
     * Convert [r,g,b] (0–255) to '#rrggbb'.
     *
     * @param int[] $rgb
     * @return string
     */
    public function rgbToHex( array $rgb ): string {
        return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
    }

    // ── Editor JS ────────────────────────────────────────────────────────────

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
