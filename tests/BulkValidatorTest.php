<?php
/**
 * Tests for BulkValidator (#18) and TemplateValidator (#19).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

// WP class stubs
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

// WP function stubs
if ( ! function_exists( 'add_action' ) )          { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )          { function add_filter() {} }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route() {} }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/p/' . $p; } }
if ( ! function_exists( 'rest_url' ) )            { function rest_url( $p ) { return 'http://x.com/' . $p; } }
if ( ! function_exists( 'get_post' ) )            { function get_post( $id ) { return null; } }
if ( ! function_exists( 'current_user_can' ) )    { function current_user_can() { return true; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( '__return_true' ) )       { function __return_true() { return true; } }
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'sanitize_key' ) )        { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); } }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'get_posts' ) )           { function get_posts( $a ) { return []; } }
if ( ! function_exists( 'get_block_templates' ) ) { function get_block_templates() { return []; } }
if ( ! function_exists( 'parse_blocks' ) ) {
    function parse_blocks( string $c ): array {
        if ( empty( trim( $c ) ) ) return [];
        $pattern = '/<!--\s*wp:(\S+)\s*(\{[^}]*\})?\s*-->(.*?)<!--\s*\/wp:\1\s*-->/s';
        preg_match_all( $pattern, $c, $m, PREG_SET_ORDER );
        return array_map( function( $match ) {
            $attrs = [];
            if ( ! empty( $match[2] ) ) { $d = json_decode( $match[2], true ); if ( is_array( $d ) ) $attrs = $d; }
            return [ 'blockName' => $match[1], 'attrs' => $attrs, 'innerHTML' => $match[3] ];
        }, $m );
    }
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/bulk-validator.php';
require_once __DIR__ . '/../includes/template-validator.php';

// ─────────────────────────────────────────────────────────────────────────────
// BulkValidator Tests — Issue #18
// ─────────────────────────────────────────────────────────────────────────────
class BulkValidatorTest extends TestCase {

    private \GutenbergA11yEnforcer\BulkValidator $bv;
    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $this->enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
        $this->bv = new \GutenbergA11yEnforcer\BulkValidator( $this->enforcer );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->bv->register();
        $this->assertTrue( true );
    }

    public function testScanPostContentEmptyContent(): void {
        $violations = $this->bv->scanPostContent( '' );
        $this->assertSame( [], $violations );
    }

    public function testScanPostContentCleanImage(): void {
        $content    = '<!-- wp:core/image {"alt":"Cat"} --><figure></figure><!-- /wp:core/image -->';
        $violations = $this->bv->scanPostContent( $content );
        $this->assertEmpty( $violations );
    }

    public function testScanPostContentDetectsViolation(): void {
        $content    = '<!-- wp:core/image --><figure></figure><!-- /wp:core/image -->';
        $violations = $this->bv->scanPostContent( $content );
        $this->assertCount( 1, $violations );
        $this->assertSame( 'core/image', $violations[0]['block'] );
        $this->assertStringContainsString( 'alt', $violations[0]['message'] );
    }

    public function testScanPostContentMultipleBlocks(): void {
        $content  = '<!-- wp:core/image --><figure></figure><!-- /wp:core/image -->';
        $content .= '<!-- wp:core/image {"alt":"Cat"} --><figure></figure><!-- /wp:core/image -->';
        $violations = $this->bv->scanPostContent( $content );
        $this->assertCount( 1, $violations );
    }

    public function testScanPostsReturnsScanCount(): void {
        // get_posts returns [] by stub, so scanned=0
        $result = $this->bv->scanPosts( 'post', 10 );
        $this->assertArrayHasKey( 'scanned', $result );
        $this->assertArrayHasKey( 'posts', $result );
        $this->assertSame( 0, $result['scanned'] );
    }

    public function testRestBulkValidateReturnsResponse(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_type', 'post' );
        $req->set_param( 'limit', 5 );
        $resp = $this->bv->restBulkValidate( $req );
        $this->assertSame( 200, $resp->get_status() );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TemplateValidator Tests — Issue #19
// ─────────────────────────────────────────────────────────────────────────────
class TemplateValidatorTest extends TestCase {

    private \GutenbergA11yEnforcer\TemplateValidator $tv;
    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $this->enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
        $this->tv = new \GutenbergA11yEnforcer\TemplateValidator( $this->enforcer );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->tv->register();
        $this->assertTrue( true );
    }

    public function testValidateTemplatesEmptyList(): void {
        $result = $this->tv->validateTemplates( [] );
        $this->assertSame( 0, $result['templates_checked'] );
        $this->assertTrue( $result['clean'] );
    }

    public function testValidateTemplatesCleanContent(): void {
        $templates = [
            [
                'slug'    => 'single',
                'content' => '<!-- wp:core/image {"alt":"Logo"} --><figure></figure><!-- /wp:core/image -->',
            ],
        ];
        $result = $this->tv->validateTemplates( $templates );
        $this->assertSame( 1, $result['templates_checked'] );
        $this->assertTrue( $result['clean'] );
    }

    public function testValidateTemplatesDetectsViolation(): void {
        $templates = [
            [
                'slug'    => 'single',
                'content' => '<!-- wp:core/image --><figure></figure><!-- /wp:core/image -->',
            ],
        ];
        $result = $this->tv->validateTemplates( $templates );
        $this->assertFalse( $result['clean'] );
        $this->assertCount( 1, $result['violations'] );
        $this->assertSame( 'single', $result['violations'][0]['template'] );
    }

    public function testValidateAllTemplatesNoBlockTemplates(): void {
        // get_block_templates() stubbed to return []
        $result = $this->tv->validateAllTemplates();
        $this->assertSame( 0, $result['templates_checked'] );
        $this->assertTrue( $result['clean'] );
    }

    public function testGetBlockTemplatesReturnsEmptyWhenNoWpFunction(): void {
        $templates = $this->tv->getBlockTemplates();
        $this->assertSame( [], $templates );
    }

    public function testRestValidateTemplatesReturnsResponse(): void {
        $req  = new WP_REST_Request();
        $resp = $this->tv->restValidateTemplates( $req );
        $this->assertSame( 200, $resp->get_status() );
    }
}
