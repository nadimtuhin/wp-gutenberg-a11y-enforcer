<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';

/**
 * WordPress function stubs for unit-test context (no WP runtime).
 */
if ( ! function_exists( 'parse_blocks' ) ) {
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
    function serialize_blocks( array $blocks ): string {
        $out = '';
        foreach ( $blocks as $block ) {
            $name  = $block['blockName'];
            $attrs = empty( $block['attrs'] ) ? '' : ' ' . json_encode( $block['attrs'] );
            $inner = $block['innerHTML'] ?? '';
            $out  .= "<!-- wp:{$name}{$attrs} -->{$inner}<!-- /wp:{$name} -->";
        }
        return $out;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $s ): string {
        return strip_tags( $s );
    }
}

if ( ! function_exists( 'add_filter' ) )   { function add_filter()   {} }
if ( ! function_exists( 'add_action' ) )   { function add_action()   {} }
if ( ! function_exists( 'get_option' ) )   { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )  { function plugins_url( $path, $plugin ) { return '/plugins/' . $path; } }
if ( ! function_exists( 'plugin_dir_path' ) ) { function plugin_dir_path( $file ) { return dirname( $file ) . '/'; } }
if ( ! function_exists( 'get_the_ID' ) )   { function get_the_ID() { return 0; } }
if ( ! function_exists( 'absint' ) )        { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $str ) { return trim( strip_tags( $str ) ); } }
if ( ! function_exists( 'get_post_meta' ) ) { function get_post_meta( $post_id, $key, $single = false ) { return $single ? '' : []; } }

class EnforcerTest extends TestCase {

    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
        // Use defaults so tests are not affected by any saved DB config.
        $this->enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
    }

    // ------------------------------------------------------------------ //
    //  validateBlock / getViolations — core/image
    // ------------------------------------------------------------------ //

    public function testImagePassesWithAlt(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'A cat' ], 'innerHTML' => '' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
        $this->assertEmpty( $this->enforcer->getViolations( $block ) );
    }

    public function testImageFailsWithoutAlt(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
        $this->assertCount( 1, $this->enforcer->getViolations( $block ) );
    }

    public function testImageFailsWithEmptyAlt(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => '' ], 'innerHTML' => '' ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  core/button
    // ------------------------------------------------------------------ //

    public function testButtonPassesWithText(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [ 'text' => 'Click me' ], 'innerHTML' => '' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    public function testButtonFailsWithEmptyText(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [ 'text' => '' ], 'innerHTML' => '' ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    public function testButtonFailsWithNoTextAttr(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    public function testButtonPassesWithInnerHtmlText(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [], 'innerHTML' => '<a>Subscribe</a>' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  core/heading
    // ------------------------------------------------------------------ //

    public function testHeadingPassesWithContent(): void {
        $block = [ 'blockName' => 'core/heading', 'attrs' => [ 'content' => 'My Title' ], 'innerHTML' => '' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    public function testHeadingFailsWithEmptyContent(): void {
        $block = [ 'blockName' => 'core/heading', 'attrs' => [ 'content' => '' ], 'innerHTML' => '' ];
        $this->assertFalse( $this->enforcer->validateBlock( $block ) );
    }

    public function testHeadingPassesWithInnerHtml(): void {
        $block = [ 'blockName' => 'core/heading', 'attrs' => [], 'innerHTML' => '<h2>Hello</h2>' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  Unknown blocks pass by default
    // ------------------------------------------------------------------ //

    public function testUnknownBlockAlwaysPasses(): void {
        $block = [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  filterContent — content_save_pre hook
    // ------------------------------------------------------------------ //

    public function testFilterContentStripsImageBlockMissingAlt(): void {
        $content = '<!-- wp:core/image --><figure class="wp-block-image"></figure><!-- /wp:core/image -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringNotContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentKeepsImageBlockWithAlt(): void {
        $content = '<!-- wp:core/image {"alt":"Cat on a mat"} --><figure class="wp-block-image"><img alt="Cat on a mat" /></figure><!-- /wp:core/image -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentStripsEmptyButton(): void {
        $content = '<!-- wp:core/button {"text":""} --><div class="wp-block-button"></div><!-- /wp:core/button -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringNotContainsString( 'wp-block-button', $result );
    }

    public function testFilterContentKeepsButtonWithText(): void {
        $content = '<!-- wp:core/button {"text":"Buy now"} --><div class="wp-block-button">Buy now</div><!-- /wp:core/button -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringContainsString( 'wp-block-button', $result );
    }

    public function testFilterContentStripsEmptyHeading(): void {
        $content = '<!-- wp:core/heading {"content":""} --><h2></h2><!-- /wp:core/heading -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringNotContainsString( 'wp:core/heading', $result );
    }

    public function testFilterContentKeepsParagraphBlocks(): void {
        $content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
        $result  = $this->enforcer->filterContent( $content );
        $this->assertStringContainsString( 'Hello world', $result );
    }

    public function testFilterContentHandlesMixedBlocks(): void {
        $good    = '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->';
        $bad     = '<!-- wp:core/image --><figure class="wp-block-image"></figure><!-- /wp:core/image -->';
        $result  = $this->enforcer->filterContent( $good . $bad );

        $this->assertStringContainsString( 'Text', $result );
        $this->assertStringNotContainsString( 'wp-block-image', $result );
    }

    public function testFilterContentReturnsStringAlways(): void {
        $result = $this->enforcer->filterContent( 'raw content without blocks' );
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
    //  Smoke tests — no WP runtime needed
    // ------------------------------------------------------------------ //

    public function testRegisterDoesNotThrow(): void {
        $this->enforcer->register();
        $this->assertTrue( true );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->enforcer->enqueueEditorScript();
        $this->assertTrue( true );
    }

    // ------------------------------------------------------------------ //
    //  Settings defaults
    // ------------------------------------------------------------------ //

    public function testSettingsDefaultsContainAllThreeBlocks(): void {
        $defaults = \GutenbergA11yEnforcer\Settings::defaults();
        $this->assertArrayHasKey( 'core/image', $defaults );
        $this->assertArrayHasKey( 'core/button', $defaults );
        $this->assertArrayHasKey( 'core/heading', $defaults );
    }

    public function testSettingsGetConfigFallsBackToDefaults(): void {
        // get_option is stubbed to return false, so getConfig() returns defaults.
        $config = \GutenbergA11yEnforcer\Settings::getConfig();
        $this->assertIsArray( $config );
        $this->assertNotEmpty( $config );
    }

    // ------------------------------------------------------------------ //
    //  Config injection — rules can be disabled per block
    // ------------------------------------------------------------------ //

    public function testBlockRulesCanBeDisabledViaConfig(): void {
        // Empty rules for core/image means it always passes.
        $this->enforcer->setConfig( [ 'core/image' => [] ] );
        $block = [ 'blockName' => 'core/image', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertTrue( $this->enforcer->validateBlock( $block ) );
    }

    // ------------------------------------------------------------------ //
    //  Issue #7 — gae_alt_autofixer filter hook
    // ------------------------------------------------------------------ //

    public function testMaybeAutoFixAltLeavesNonImageBlockUntouched(): void {
        $block  = [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Hi</p>' ];
        $result = $this->enforcer->maybeAutoFixAlt( $block );
        $this->assertSame( $block, $result );
    }

    public function testMaybeAutoFixAltLeavesBlockWithAltUntouched(): void {
        $block  = [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'Cat' ], 'innerHTML' => '' ];
        $result = $this->enforcer->maybeAutoFixAlt( $block );
        $this->assertSame( 'Cat', $result['attrs']['alt'] );
    }

    public function testMaybeAutoFixAltDoesNotInjectEmptyString(): void {
        // apply_filters stub returns '' — no alt should be injected.
        $block  = [ 'blockName' => 'core/image', 'attrs' => [], 'innerHTML' => '' ];
        $result = $this->enforcer->maybeAutoFixAlt( $block );
        $this->assertArrayNotHasKey( 'alt', $result['attrs'] );
    }

    // ------------------------------------------------------------------ //
    //  Issue #8 — bypass meta key
    // ------------------------------------------------------------------ //

    public function testIsBypassedForPostReturnsFalseForZeroId(): void {
        $this->assertFalse( $this->enforcer->isBypassedForPost( 0 ) );
    }

    public function testIsBypassedForPostReturnsFalseWhenMetaAbsent(): void {
        // get_post_meta stub returns '' (not '1').
        $this->assertFalse( $this->enforcer->isBypassedForPost( 42 ) );
    }

    public function testBypassMetaKeyConstantDefined(): void {
        $this->assertSame( '_gae_bypass_validation', \GutenbergA11yEnforcer\Settings::BYPASS_META_KEY );
    }

    // ------------------------------------------------------------------ //
    //  Issue #9 — HeadingHierarchy check
    // ------------------------------------------------------------------ //

    public function testCheckHeadingHierarchyPassesForSequential(): void {
        $blocks = [
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 2 ], 'innerHTML' => '<h2>A</h2>' ],
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 3 ], 'innerHTML' => '<h3>B</h3>' ],
        ];
        $this->assertEmpty( $this->enforcer->checkHeadingHierarchy( $blocks ) );
    }

    public function testCheckHeadingHierarchyFlagsSkippedLevel(): void {
        $blocks = [
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 2 ], 'innerHTML' => '<h2>A</h2>' ],
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 4 ], 'innerHTML' => '<h4>B</h4>' ],
        ];
        $violations = $this->enforcer->checkHeadingHierarchy( $blocks );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'H2', $violations[0] );
        $this->assertStringContainsString( 'H4', $violations[0] );
    }

    public function testCheckHeadingHierarchyIgnoresNonHeadingBlocks(): void {
        $blocks = [
            [ 'blockName' => 'core/heading',   'attrs' => [ 'level' => 2 ], 'innerHTML' => '<h2>A</h2>' ],
            [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>text</p>' ],
            [ 'blockName' => 'core/heading',   'attrs' => [ 'level' => 3 ], 'innerHTML' => '<h3>B</h3>' ],
        ];
        $this->assertEmpty( $this->enforcer->checkHeadingHierarchy( $blocks ) );
    }

    public function testCheckHeadingHierarchyAllowsDescendingBack(): void {
        $blocks = [
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 2 ], 'innerHTML' => '<h2>A</h2>' ],
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 3 ], 'innerHTML' => '<h3>B</h3>' ],
            [ 'blockName' => 'core/heading', 'attrs' => [ 'level' => 2 ], 'innerHTML' => '<h2>C</h2>' ],
        ];
        $this->assertEmpty( $this->enforcer->checkHeadingHierarchy( $blocks ) );
    }
}
