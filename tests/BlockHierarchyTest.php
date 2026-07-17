<?php
/**
 * Tests for BlockHierarchy (Issue #13).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// WP stubs
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
if ( ! function_exists( 'current_user_can' ) )    { function current_user_can() { return true; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
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

require_once __DIR__ . '/../includes/block-hierarchy.php';

class BlockHierarchyTest extends TestCase {

    private \GutenbergA11yEnforcer\BlockHierarchy $bh;

    protected function setUp(): void {
        $this->bh = new \GutenbergA11yEnforcer\BlockHierarchy();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->bh->register();
        $this->assertTrue( true );
    }

    public function testBuildTreeEmptyBlocks(): void {
        $this->assertSame( [], $this->bh->buildTree( [] ) );
    }

    public function testBuildTreeSkipsNullBlockName(): void {
        $blocks = [ [ 'blockName' => null, 'attrs' => [], 'innerBlocks' => [] ] ];
        $this->assertSame( [], $this->bh->buildTree( $blocks ) );
    }

    public function testBuildTreeSingleBlock(): void {
        $blocks = [
            [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [] ],
        ];
        $tree = $this->bh->buildTree( $blocks );
        $this->assertCount( 1, $tree );
        $this->assertSame( 'core/paragraph', $tree[0]['name'] );
        $this->assertSame( [], $tree[0]['children'] );
    }

    public function testBuildTreeNestedInnerBlocks(): void {
        $blocks = [
            [
                'blockName'   => 'core/group',
                'attrs'       => [],
                'innerBlocks' => [
                    [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [] ],
                ],
            ],
        ];
        $tree = $this->bh->buildTree( $blocks );
        $this->assertCount( 1, $tree );
        $this->assertSame( 'core/group', $tree[0]['name'] );
        $this->assertCount( 1, $tree[0]['children'] );
        $this->assertSame( 'core/paragraph', $tree[0]['children'][0]['name'] );
    }

    public function testFlatBlockNamesEmpty(): void {
        $this->assertSame( [], $this->bh->flatBlockNames( [] ) );
    }

    public function testFlatBlockNamesSingleLevel(): void {
        $blocks = [
            [ 'blockName' => 'core/heading', 'attrs' => [], 'innerBlocks' => [] ],
            [ 'blockName' => 'core/image',   'attrs' => [], 'innerBlocks' => [] ],
        ];
        $this->assertSame( [ 'core/heading', 'core/image' ], $this->bh->flatBlockNames( $blocks ) );
    }

    public function testFlatBlockNamesNested(): void {
        $blocks = [
            [
                'blockName'   => 'core/group',
                'attrs'       => [],
                'innerBlocks' => [
                    [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [] ],
                ],
            ],
        ];
        $names = $this->bh->flatBlockNames( $blocks );
        $this->assertContains( 'core/group', $names );
        $this->assertContains( 'core/paragraph', $names );
    }

    public function testRestBlockHierarchyReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 999 );
        $resp = $this->bh->restBlockHierarchy( $req );
        $this->assertSame( 404, $resp->get_status() );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->bh->enqueueEditorScript();
        $this->assertTrue( true );
    }
}
