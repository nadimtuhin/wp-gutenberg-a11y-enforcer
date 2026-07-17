<?php
/**
 * Tests for AltBulkEditor (Issue #23).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $params = [];
        public function set_param( string $k, mixed $v ): void { $this->params[$k] = $v; }
        public function get_param( string $k ): mixed { return $this->params[$k] ?? null; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private mixed $data; private int $status;
        public function __construct( mixed $data, int $status = 200 ) { $this->data = $data; $this->status = $status; }
        public function get_status(): int { return $this->status; }
        public function get_data(): mixed { return $this->data; }
    }
}
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public array $posts = [];
        public function __construct( array $args ) {}
    }
}

if ( ! function_exists( 'add_action' ) )          { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )          { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() {} }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/p/' . $p; } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $p ) { return 'http://x.com/' . $p; } }
if ( ! function_exists( 'current_user_can' ) )    { function current_user_can() { return true; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'get_attached_file' ) )   { function get_attached_file( $id ) { return '/tmp/img-' . $id . '.jpg'; } }
if ( ! function_exists( 'wp_get_attachment_url' ) ) { function wp_get_attachment_url( $id ) { return 'http://x.com/img-' . $id . '.jpg'; } }
if ( ! function_exists( 'get_post_meta' ) )       { function get_post_meta( $id, $k, $s = false ) { return $s ? '' : []; } }

$_gae_alt_updated = [];
if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $id, $key, $value ) {
        global $_gae_alt_updated;
        $_gae_alt_updated[ $id ] = $value;
        return true;
    }
}

require_once __DIR__ . '/../includes/alt-bulk-editor.php';

class AltBulkEditorTest extends TestCase {

    private \GutenbergA11yEnforcer\AltBulkEditor $editor;

    protected function setUp(): void {
        global $_gae_alt_updated;
        $_gae_alt_updated = [];
        $this->editor = new \GutenbergA11yEnforcer\AltBulkEditor();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->editor->register();
        $this->assertTrue( true );
    }

    public function testBulkUpdateAltNoUpdates(): void {
        $count = $this->editor->bulkUpdateAlt( [] );
        $this->assertSame( 0, $count );
    }

    public function testBulkUpdateAltSingleItem(): void {
        global $_gae_alt_updated;
        $count = $this->editor->bulkUpdateAlt( [ [ 'id' => 42, 'alt' => 'Cat photo' ] ] );
        $this->assertSame( 1, $count );
        $this->assertArrayHasKey( 42, $_gae_alt_updated );
        $this->assertSame( 'Cat photo', $_gae_alt_updated[42] );
    }

    public function testBulkUpdateAltSkipsZeroId(): void {
        $count = $this->editor->bulkUpdateAlt( [ [ 'id' => 0, 'alt' => 'Whatever' ] ] );
        $this->assertSame( 0, $count );
    }

    public function testBulkUpdateAltMultipleItems(): void {
        $updates = [
            [ 'id' => 1, 'alt' => 'First' ],
            [ 'id' => 2, 'alt' => 'Second' ],
            [ 'id' => 3, 'alt' => 'Third' ],
        ];
        $count = $this->editor->bulkUpdateAlt( $updates );
        $this->assertSame( 3, $count );
    }

    public function testBulkUpdateAltSanitizesInput(): void {
        global $_gae_alt_updated;
        $this->editor->bulkUpdateAlt( [ [ 'id' => 10, 'alt' => '<script>alert(1)</script>Cat' ] ] );
        $this->assertStringNotContainsString( '<script>', $_gae_alt_updated[10] );
    }

    public function testRestAltMissingReturnsResponse(): void {
        $req  = new WP_REST_Request();
        $resp = $this->editor->restAltMissing( $req );
        $this->assertSame( 200, $resp->get_status() );
        $data = $resp->get_data();
        $this->assertArrayHasKey( 'attachments', $data );
        $this->assertArrayHasKey( 'count', $data );
    }

    public function testRestAltBulkUpdateReturns400ForNoUpdates(): void {
        $req  = new WP_REST_Request();
        $req->set_param( 'updates', null );
        $resp = $this->editor->restAltBulkUpdate( $req );
        $this->assertSame( 400, $resp->get_status() );
    }

    public function testRestAltBulkUpdateSucceeds(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'updates', [ [ 'id' => 5, 'alt' => 'Logo' ] ] );
        $resp = $this->editor->restAltBulkUpdate( $req );
        $this->assertSame( 200, $resp->get_status() );
        $this->assertSame( 1, $resp->get_data()['updated'] );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->editor->enqueueEditorScript();
        $this->assertTrue( true );
    }
}
