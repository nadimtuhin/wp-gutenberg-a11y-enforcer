<?php
/**
 * Tests for AiAltText (Issue #4).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// WP class stubs — must come before require_once of production code.
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params = [];
        public function set_param( string $key, mixed $val ): void { $this->params[ $key ] = $val; }
        public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
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
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args ) {} }
if ( ! function_exists( 'current_user_can' ) )    { function current_user_can( $cap ) { return true; } }
if ( ! function_exists( 'get_post' ) )            { function get_post( $id ) { return null; } }
if ( ! function_exists( 'wp_get_attachment_caption' ) ) { function wp_get_attachment_caption( $id ) { return ''; } }
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $path ) { return 'https://example.com/wp-json/' . $path; } }
if ( ! function_exists( 'wp_create_nonce' ) )     { function wp_create_nonce( $a ) { return 'test-nonce'; } }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $path, $plugin ) { return '/plugins/' . $path; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }
if ( ! function_exists( 'plugin_dir_path' ) )     { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/ai-alt-text.php';

class AiAltTextTest extends TestCase {

    private \GutenbergA11yEnforcer\AiAltText $aiAlt;

    protected function setUp(): void {
        $this->aiAlt = new \GutenbergA11yEnforcer\AiAltText();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->aiAlt->register();
        $this->assertTrue( true );
    }

    public function testRegisterRestRouteDoesNotThrow(): void {
        $this->aiAlt->registerRestRoute();
        $this->assertTrue( true );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->aiAlt->enqueueEditorScript();
        $this->assertTrue( true );
    }

    public function testSuggestAltReturns404ForMissingAttachment(): void {
        $request = new WP_REST_Request();
        $request->set_param( 'attachment_id', 999 );

        $response = $this->aiAlt->suggestAlt( $request );
        $this->assertSame( 404, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'error', $data );
    }

    public function testSuggestAltReturns404ForZeroId(): void {
        $request = new WP_REST_Request();
        $request->set_param( 'attachment_id', 0 );

        $response = $this->aiAlt->suggestAlt( $request );
        $this->assertSame( 404, $response->get_status() );
    }
}
