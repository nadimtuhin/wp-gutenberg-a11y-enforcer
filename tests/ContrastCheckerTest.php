<?php
/**
 * Tests for ContrastChecker (Issue #6).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// WP class stubs
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params = [];
        public function set_param( string $k, mixed $v ): void { $this->params[ $k ] = $v; }
        public function get_param( string $k ): mixed { return $this->params[ $k ] ?? null; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private mixed $data;
        private int $status;
        public function __construct( mixed $data, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_status(): int  { return $this->status; }
        public function get_data(): mixed  { return $this->data; }
    }
}

// WP function stubs
if ( ! function_exists( 'add_action' ) )          { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )          { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $r, $a ) {} }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $p ) { return 'http://example.com/wp-json/' . $p; } }
if ( ! function_exists( 'wp_create_nonce' ) )     { function wp_create_nonce( $a ) { return 'nonce'; } }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/plugins/' . $p; } }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'plugin_dir_path' ) )     { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( '__return_true' ) )       { function __return_true() { return true; } }

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/contrast-checker.php';

class ContrastCheckerTest extends TestCase {

    private \GutenbergA11yEnforcer\ContrastChecker $checker;

    protected function setUp(): void {
        $this->checker = new \GutenbergA11yEnforcer\ContrastChecker();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->checker->register();
        $this->assertTrue( true );
    }

    // hexToRgb

    public function testHexToRgbParsesBlack(): void {
        $this->assertSame( [ 0, 0, 0 ], $this->checker->hexToRgb( '#000000' ) );
    }

    public function testHexToRgbParsesWhite(): void {
        $this->assertSame( [ 255, 255, 255 ], $this->checker->hexToRgb( '#ffffff' ) );
    }

    public function testHexToRgbParsesWithoutHash(): void {
        $this->assertSame( [ 255, 0, 0 ], $this->checker->hexToRgb( 'ff0000' ) );
    }

    public function testHexToRgbReturnsNullForInvalid(): void {
        $this->assertNull( $this->checker->hexToRgb( 'nope' ) );
        $this->assertNull( $this->checker->hexToRgb( '#fff' ) );
    }

    // relativeLuminance

    public function testRelativeLuminanceBlackIsZero(): void {
        $this->assertEqualsWithDelta( 0.0, $this->checker->relativeLuminance( [ 0, 0, 0 ] ), 0.001 );
    }

    public function testRelativeLuminanceWhiteIsOne(): void {
        $this->assertEqualsWithDelta( 1.0, $this->checker->relativeLuminance( [ 255, 255, 255 ] ), 0.001 );
    }

    // contrastRatio

    public function testContrastRatioBlackOnWhiteIs21(): void {
        $ratio = $this->checker->contrastRatio( [ 0, 0, 0 ], [ 255, 255, 255 ] );
        $this->assertEqualsWithDelta( 21.0, $ratio, 0.1 );
    }

    public function testContrastRatioSameColorIs1(): void {
        $ratio = $this->checker->contrastRatio( [ 128, 128, 128 ], [ 128, 128, 128 ] );
        $this->assertEqualsWithDelta( 1.0, $ratio, 0.01 );
    }

    public function testContrastRatioIsCommutative(): void {
        $r1 = $this->checker->contrastRatio( [ 255, 0, 0 ], [ 255, 255, 255 ] );
        $r2 = $this->checker->contrastRatio( [ 255, 255, 255 ], [ 255, 0, 0 ] );
        $this->assertEqualsWithDelta( $r1, $r2, 0.001 );
    }

    // REST endpoint

    public function testRestContrastRatioBlackWhite(): void {
        $request = new WP_REST_Request();
        $request->set_param( 'fg', '#000000' );
        $request->set_param( 'bg', '#ffffff' );

        $response = $this->checker->restContrastRatio( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertEqualsWithDelta( 21.0, $data['ratio'], 0.1 );
        $this->assertTrue( $data['passes_aa'] );
        $this->assertTrue( $data['passes_aaa'] );
    }

    public function testRestContrastRatioReturns400ForInvalidHex(): void {
        $request = new WP_REST_Request();
        $request->set_param( 'fg', 'notacolor' );
        $request->set_param( 'bg', '#ffffff' );

        $response = $this->checker->restContrastRatio( $request );
        $this->assertSame( 400, $response->get_status() );
    }

    public function testRestContrastRatioPassesAaLargeForMediumContrast(): void {
        // Gray on white: ratio ~3.95 — passes AA large but not AA normal.
        $request = new WP_REST_Request();
        $request->set_param( 'fg', '#767676' );
        $request->set_param( 'bg', '#ffffff' );

        $response = $this->checker->restContrastRatio( $request );
        $data     = $response->get_data();
        $this->assertArrayHasKey( 'passes_aa_large', $data );
        $this->assertArrayHasKey( 'passes_aa', $data );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->checker->enqueueEditorScript();
        $this->assertTrue( true );
    }

    // ── adjustForContrast (Issue #12) ────────────────────────────────────────

    public function testAdjustForContrastReturnsOriginalWhenAlreadyPasses(): void {
        // Black on white already passes (21:1).
        $result = $this->checker->adjustForContrast( '#000000', '#ffffff' );
        $this->assertSame( '#000000', $result );
    }

    public function testAdjustForContrastDarkensGrayOnWhiteToPassAA(): void {
        // #767676 on white has ratio ~4.48 — just below 4.5 AA threshold.
        $result = $this->checker->adjustForContrast( '#767676', '#ffffff' );
        $this->assertNotNull( $result );

        // Verify the adjusted color actually passes.
        $fg_rgb = $this->checker->hexToRgb( $result );
        $bg_rgb = $this->checker->hexToRgb( '#ffffff' );
        $ratio  = $this->checker->contrastRatio( $fg_rgb, $bg_rgb );
        $this->assertGreaterThanOrEqual( \GutenbergA11yEnforcer\ContrastChecker::WCAG_AA_NORMAL, $ratio );
    }

    public function testAdjustForContrastLightensOnDarkBackground(): void {
        // Light gray on dark bg — may need lightening.
        $result = $this->checker->adjustForContrast( '#999999', '#222222' );
        $this->assertNotNull( $result );

        $fg_rgb = $this->checker->hexToRgb( $result );
        $bg_rgb = $this->checker->hexToRgb( '#222222' );
        $ratio  = $this->checker->contrastRatio( $fg_rgb, $bg_rgb );
        $this->assertGreaterThanOrEqual( \GutenbergA11yEnforcer\ContrastChecker::WCAG_AA_NORMAL, $ratio );
    }

    public function testAdjustForContrastReturnsNullForInvalidHex(): void {
        $this->assertNull( $this->checker->adjustForContrast( 'notacolor', '#ffffff' ) );
        $this->assertNull( $this->checker->adjustForContrast( '#ffffff', 'notacolor' ) );
    }

    public function testAdjustForContrastReturnsHexString(): void {
        $result = $this->checker->adjustForContrast( '#888888', '#ffffff' );
        $this->assertNotNull( $result );
        $this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $result );
    }

    public function testAdjustForContrastCustomRatio(): void {
        // Require AAA (7:1) on white.
        $result = $this->checker->adjustForContrast( '#767676', '#ffffff', \GutenbergA11yEnforcer\ContrastChecker::WCAG_AAA_NORMAL );
        $this->assertNotNull( $result );

        $fg_rgb = $this->checker->hexToRgb( $result );
        $bg_rgb = $this->checker->hexToRgb( '#ffffff' );
        $ratio  = $this->checker->contrastRatio( $fg_rgb, $bg_rgb );
        $this->assertGreaterThanOrEqual( \GutenbergA11yEnforcer\ContrastChecker::WCAG_AAA_NORMAL, $ratio );
    }

    // ── rgbToHex ─────────────────────────────────────────────────────────────

    public function testRgbToHexBlack(): void {
        $this->assertSame( '#000000', $this->checker->rgbToHex( [ 0, 0, 0 ] ) );
    }

    public function testRgbToHexWhite(): void {
        $this->assertSame( '#ffffff', $this->checker->rgbToHex( [ 255, 255, 255 ] ) );
    }

    public function testRgbToHexRed(): void {
        $this->assertSame( '#ff0000', $this->checker->rgbToHex( [ 255, 0, 0 ] ) );
    }
}
