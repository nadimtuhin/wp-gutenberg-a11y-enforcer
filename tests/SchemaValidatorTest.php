<?php
/**
 * Tests for SchemaValidator (Issue #5).
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
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'do_action' ) )           { function do_action() {} }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $p ) { return 'http://example.com/wp-json/' . $p; } }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/plugins/' . $p; } }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }
if ( ! function_exists( 'plugin_dir_path' ) )     { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'parse_blocks' ) ) {
    function parse_blocks( string $content ): array { return []; }
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/schema-validator.php';

class SchemaValidatorTest extends TestCase {

    private \GutenbergA11yEnforcer\SchemaValidator $validator;

    protected function setUp(): void {
        $this->validator = new \GutenbergA11yEnforcer\SchemaValidator();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->validator->register();
        $this->assertTrue( true );
    }

    public function testGetSchemasReturnsArray(): void {
        $schemas = $this->validator->getSchemas();
        $this->assertIsArray( $schemas );
    }

    public function testCheckBlockPassesWhenNoSchema(): void {
        $violations = $this->validator->checkBlock(
            [ 'blockName' => 'core/paragraph', 'attrs' => [] ],
            []
        );
        $this->assertEmpty( $violations );
    }

    public function testCheckBlockDetectsMissingRequiredAttr(): void {
        $schema = [ 'required_attrs' => [ 'caption' ] ];
        $block  = [ 'blockName' => 'my-plugin/gallery', 'attrs' => [] ];

        $violations = $this->validator->checkBlock( $block, $schema );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'caption', $violations[0] );
    }

    public function testCheckBlockPassesWhenRequiredAttrPresent(): void {
        $schema = [ 'required_attrs' => [ 'caption' ] ];
        $block  = [ 'blockName' => 'my-plugin/gallery', 'attrs' => [ 'caption' => 'Hello' ] ];

        $violations = $this->validator->checkBlock( $block, $schema );
        $this->assertEmpty( $violations );
    }

    public function testCheckBlockDetectsDisallowedValue(): void {
        $schema = [ 'allowed_values' => [ 'align' => [ 'left', 'center', 'right' ] ] ];
        $block  = [ 'blockName' => 'core/image', 'attrs' => [ 'align' => 'justify' ] ];

        $violations = $this->validator->checkBlock( $block, $schema );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'justify', $violations[0] );
    }

    public function testCheckBlockPassesWhenValueAllowed(): void {
        $schema = [ 'allowed_values' => [ 'align' => [ 'left', 'center', 'right' ] ] ];
        $block  = [ 'blockName' => 'core/image', 'attrs' => [ 'align' => 'center' ] ];

        $violations = $this->validator->checkBlock( $block, $schema );
        $this->assertEmpty( $violations );
    }

    public function testCheckBlockSkipsAllowedValuesWhenAttrAbsent(): void {
        // attr not set → skip check (presence enforced via required_attrs).
        $schema = [ 'allowed_values' => [ 'align' => [ 'left', 'right' ] ] ];
        $block  = [ 'blockName' => 'core/image', 'attrs' => [] ];

        $violations = $this->validator->checkBlock( $block, $schema );
        $this->assertEmpty( $violations );
    }

    public function testValidateSchemasPassThroughContent(): void {
        $content = '<p>Hello</p>';
        $result  = $this->validator->validateSchemas( $content );
        $this->assertSame( $content, $result );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->validator->enqueueEditorScript();
        $this->assertTrue( true );
    }
}
