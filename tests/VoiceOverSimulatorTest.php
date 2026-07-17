<?php
/**
 * Tests for VoiceOverSimulator (Issue #14).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
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
if ( ! function_exists( 'add_action' ) )          { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )          { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() {} }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/p/' . $p; } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $p ) { return 'http://example.com/wp-json/' . $p; } }
if ( ! function_exists( 'get_post' ) )            { function get_post( $id ) { return null; } }
if ( ! function_exists( 'parse_blocks' ) ) {
    function parse_blocks( string $c ): array {
        if ( empty( trim( $c ) ) ) { return []; }
        $pattern = '/<!--\s*wp:(\S+)\s*(\{[^}]*\})?\s*-->(.*?)<!--\s*\/wp:\1\s*-->/s';
        preg_match_all( $pattern, $c, $m, PREG_SET_ORDER );
        return array_map( function( $match ) {
            $attrs = [];
            if ( ! empty( $match[2] ) ) { $d = json_decode( $match[2], true ); if ( is_array( $d ) ) $attrs = $d; }
            return [ 'blockName' => $match[1], 'attrs' => $attrs, 'innerHTML' => $match[3], 'innerBlocks' => [] ];
        }, $m );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( '__return_true' ) )       { function __return_true() { return true; } }

require_once __DIR__ . '/../includes/screen-reader-simulator.php';
require_once __DIR__ . '/../includes/voiceover-simulator.php';

class VoiceOverSimulatorTest extends TestCase {

    private \GutenbergA11yEnforcer\VoiceOverSimulator $sim;

    protected function setUp(): void {
        $this->sim = new \GutenbergA11yEnforcer\VoiceOverSimulator();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->sim->register();
        $this->assertTrue( true );
    }

    public function testToSsmlEmptyLines(): void {
        $ssml = $this->sim->toSsml( [] );
        $this->assertSame( '<speak></speak>', $ssml );
    }

    public function testToSsmlWrapsLinesInParagraphs(): void {
        $ssml = $this->sim->toSsml( [ '[Heading level 2: Title]' ] );
        $this->assertStringContainsString( '<speak>', $ssml );
        $this->assertStringContainsString( '<p>', $ssml );
        $this->assertStringContainsString( 'Heading level 2: Title', $ssml );
        $this->assertStringContainsString( '<break time="300ms"/>', $ssml );
    }

    public function testToSsmlEscapesXmlEntities(): void {
        $ssml = $this->sim->toSsml( [ 'Text with <b>bold</b> & amp' ] );
        $this->assertStringNotContainsString( '<b>', $ssml );
        $this->assertStringContainsString( '&amp;', $ssml );
    }

    public function testToSsmlMultipleLines(): void {
        $ssml = $this->sim->toSsml( [ 'Line 1', 'Line 2', 'Line 3' ] );
        $this->assertStringContainsString( 'Line 1', $ssml );
        $this->assertStringContainsString( 'Line 2', $ssml );
        $this->assertStringContainsString( 'Line 3', $ssml );
    }

    public function testRestVoiceOverPreviewReturns400ForNoBlocks(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 0 );
        $req->set_param( 'blocks', null );
        $resp = $this->sim->restVoiceOverPreview( $req );
        $this->assertSame( 400, $resp->get_status() );
    }

    public function testRestVoiceOverPreviewReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 999 );
        $resp = $this->sim->restVoiceOverPreview( $req );
        $this->assertSame( 404, $resp->get_status() );
    }

    public function testRestVoiceOverPreviewWithBlocks(): void {
        $blocks = [
            [
                'blockName' => 'core/heading',
                'attrs'     => [ 'level' => 2, 'content' => 'Hello World' ],
                'innerHTML' => '<h2>Hello World</h2>',
            ],
        ];
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 0 );
        $req->set_param( 'blocks', $blocks );
        $resp = $this->sim->restVoiceOverPreview( $req );
        $this->assertSame( 200, $resp->get_status() );
        $data = $resp->get_data();
        $this->assertArrayHasKey( 'ssml', $data );
        $this->assertArrayHasKey( 'transcript', $data );
        $this->assertNotEmpty( $data['ssml'] );
    }

    public function testTranscriptInheritsFromParent(): void {
        // VoiceOverSimulator extends ScreenReaderSimulator — transcript() must work.
        $blocks = [
            [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'Cat' ], 'innerHTML' => '' ],
        ];
        $transcript = $this->sim->transcript( $blocks );
        $this->assertCount( 1, $transcript );
        $this->assertStringContainsString( 'Cat', $transcript[0] );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->sim->enqueueEditorScript();
        $this->assertTrue( true );
    }
}
