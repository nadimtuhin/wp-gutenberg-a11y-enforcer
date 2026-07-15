<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/enforcer.php';

/**
 * Stub WordPress functions used by Enforcer so tests run outside WP.
 */
if ( ! function_exists( 'parse_blocks' ) ) {
    /**
     * Minimal parse_blocks stub.
     * Parses <!-- wp:blockName {...attrs} --> ... <!-- /wp:blockName --> comments.
     */
    function parse_blocks( string $content ): array {
        $blocks  = [];
        $pattern = '/<!--\s*wp:(\S+)\s*(\{[^}]*\})?\s*-->(.*?)<!--\s*\/wp:\1\s*-->/s';
        preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );

        foreach ( $matches as $m ) {
            $attrs = [];
            if ( ! empty( $m[2] ) ) {
                $decoded = json_decode( $m[2], true );
                if ( is_array( $decoded ) ) {
                    $attrs = $decoded;
                }
            }
            $blocks[] = [
                'blockName' => $m[1],
                'attrs'     => $attrs,
                'innerHTML' => $m[3],
            ];
        }

        return $blocks;
    }
}

if ( ! function_exists( 'serialize_blocks' ) ) {
    /**
     * Minimal serialize_blocks stub: rebuild block comment delimiters.
     */
    function serialize_blocks( array $blocks ): string {
        $out = '';
        foreach ( $blocks as $block ) {
            $name    = $block['blockName'];
            $attrs   = empty( $block['attrs'] ) ? '' : ' ' . json_encode( $block['attrs'] );
            $inner   = $block['innerHTML'] ?? '';
            $out    .= "<!-- wp:{$name}{$attrs} -->{$inner}<!-- /wp:{$name} -->";
        }
        return $out;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter() {}
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action() {}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script() {}
}
if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( $path, $plugin ) { return '/plugins/' . $path; }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}

class EnforcerTest extends TestCase {

    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
    }

    // ------------------------------------------------------------------ //
    //  validateBlock
    // ------------------------------------------------------------------ //

    public function testBlockValidationPassesWithAlt(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [ 'alt' => 'A description' ],
        ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    public function testBlockValidationFailsWithoutAlt(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [],
        ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    public function testBlockValidationFailsWithEmptyAlt(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [ 'alt' => '' ],
        ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    public function testNonImageBlockAlwaysPasses(): void {
        $block = [
            'blockName' => 'core/paragraph',
            'attrs'     => [],
        ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  filterContent — content_save_pre hook
    // ------------------------------------------------------------------ //

    public function testFilterContentStripsImageBlockMissingAlt(): void {
        // WordPress serialises core/image blocks as "core/image" in block comments.
        $content = '<!-- wp:core/image --><figure class="wp-block-image"></figure><!-- /wp:core/image -->';
        $result  = $this->enforcer->filterContent( $content );
        // Non-compliant image block should be removed.
        $this->assertStringNotContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentKeepsImageBlockWithAlt(): void {
        $content = '<!-- wp:core/image {"alt":"Cat on a mat"} --><figure class="wp-block-image"><img alt="Cat on a mat" /></figure><!-- /wp:core/image -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentKeepsParagraphBlocks(): void {
        $content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringContainsString( 'Hello world', $result );
    }

    public function testFilterContentHandlesMixedBlocks(): void {
        $good    = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';
        $bad     = '<!-- wp:core/image --><figure class="wp-block-image"></figure><!-- /wp:core/image -->';
        $content = $good . $bad;

        $result = $this->enforcer->filterContent( $content );

        $this->assertStringContainsString( 'Text', $result );
        $this->assertStringNotContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentReturnsOriginalWhenParseBlocksMissing(): void {
        // Simulate environment without parse_blocks (already defined here via stub,
        // so we test via filterContent directly; this test just confirms it doesn't crash).
        $content = 'raw content without blocks';
        $result  = $this->enforcer->filterContent( $content );
        // parse_blocks stub will return [] for content without block comments.
        $this->assertIsString( $result );
    }

    // ------------------------------------------------------------------ //
    //  serializeBlocks
    // ------------------------------------------------------------------ //

    public function testSerializeBlocksProducesBlockComments(): void {
        $blocks = [
            [
                'blockName' => 'core/paragraph',
                'attrs'     => [],
                'innerHTML' => '<p>Hello</p>',
            ],
        ];
        $result = $this->enforcer->serializeBlocks( $blocks );
        $this->assertStringContainsString( '<!-- wp:core/paragraph', $result );
        $this->assertStringContainsString( '<p>Hello</p>', $result );
        $this->assertStringContainsString( '<!-- /wp:core/paragraph', $result );
    }

    // ------------------------------------------------------------------ //
    //  register / enqueueEditorScript (smoke tests — no WP runtime needed)
    // ------------------------------------------------------------------ //

    public function testRegisterDoesNotThrow(): void {
        // add_filter / add_action are stubbed above; just ensure no exception.
        $this->enforcer->register();
        $this->assertTrue( true );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->enforcer->enqueueEditorScript();
        $this->assertTrue( true );
    }
}
