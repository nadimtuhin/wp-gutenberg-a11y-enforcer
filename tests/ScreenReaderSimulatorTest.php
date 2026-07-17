<?php
/**
 * Tests for ScreenReaderSimulator (Issue #11).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

if ( ! function_exists( 'add_action' ) )        { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )        { function add_filter() {} }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }

require_once __DIR__ . '/../includes/screen-reader-simulator.php';

class ScreenReaderSimulatorTest extends TestCase {

    private \GutenbergA11yEnforcer\ScreenReaderSimulator $sim;

    protected function setUp(): void {
        $this->sim = new \GutenbergA11yEnforcer\ScreenReaderSimulator();
    }

    // ── announceBlock ────────────────────────────────────────────────────────

    public function testImageWithAltAnnounced(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'A cat' ], 'innerHTML' => '' ];
        $this->assertSame( '[Image: A cat]', $this->sim->announceBlock( $block ) );
    }

    public function testImageWithAltAndCaption(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'A cat', 'caption' => 'Fluffy' ], 'innerHTML' => '' ];
        $this->assertSame( '[Image: A cat] [Caption: Fluffy]', $this->sim->announceBlock( $block ) );
    }

    public function testImageWithoutAltReportsViolation(): void {
        $block = [ 'blockName' => 'core/image', 'attrs' => [], 'innerHTML' => '' ];
        $out   = $this->sim->announceBlock( $block );
        $this->assertStringContainsString( 'a11y violation', $out );
        $this->assertStringContainsString( 'Image', $out );
    }

    public function testHeadingAnnounced(): void {
        $block = [ 'blockName' => 'core/heading', 'attrs' => [ 'content' => 'Hello', 'level' => 2 ], 'innerHTML' => '' ];
        $this->assertSame( '[Heading level 2: Hello]', $this->sim->announceBlock( $block ) );
    }

    public function testEmptyHeadingReportsViolation(): void {
        $block = [ 'blockName' => 'core/heading', 'attrs' => [ 'content' => '', 'level' => 3 ], 'innerHTML' => '' ];
        $out   = $this->sim->announceBlock( $block );
        $this->assertStringContainsString( 'a11y violation', $out );
    }

    public function testParagraphAnnounced(): void {
        $block = [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Hello world</p>' ];
        $this->assertSame( '[Paragraph: Hello world]', $this->sim->announceBlock( $block ) );
    }

    public function testEmptyParagraphReturnsNull(): void {
        $block = [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertNull( $this->sim->announceBlock( $block ) );
    }

    public function testButtonAnnounced(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [ 'text' => 'Buy now' ], 'innerHTML' => '' ];
        $this->assertSame( '[Button: Buy now]', $this->sim->announceBlock( $block ) );
    }

    public function testButtonWithoutLabelReportsViolation(): void {
        $block = [ 'blockName' => 'core/button', 'attrs' => [], 'innerHTML' => '' ];
        $out   = $this->sim->announceBlock( $block );
        $this->assertStringContainsString( 'a11y violation', $out );
    }

    public function testListAnnounced(): void {
        $block = [
            'blockName' => 'core/list',
            'attrs'     => [],
            'innerHTML' => '<ul><li>Apple</li><li>Banana</li></ul>',
        ];
        $out = $this->sim->announceBlock( $block );
        $this->assertStringContainsString( '[List: 2 items]', $out );
        $this->assertStringContainsString( 'Apple', $out );
        $this->assertStringContainsString( 'Banana', $out );
    }

    public function testSeparatorAnnounced(): void {
        $block = [ 'blockName' => 'core/separator', 'attrs' => [], 'innerHTML' => '<hr />' ];
        $this->assertSame( '[Separator]', $this->sim->announceBlock( $block ) );
    }

    public function testSpacerReturnsNull(): void {
        $block = [ 'blockName' => 'core/spacer', 'attrs' => [], 'innerHTML' => '<div></div>' ];
        $this->assertNull( $this->sim->announceBlock( $block ) );
    }

    public function testUnknownBlockWithTextAnnounced(): void {
        $block = [ 'blockName' => 'my-plugin/custom', 'attrs' => [], 'innerHTML' => '<div>Content</div>' ];
        $out   = $this->sim->announceBlock( $block );
        $this->assertStringContainsString( 'my-plugin custom', $out );
        $this->assertStringContainsString( 'Content', $out );
    }

    // ── transcript ───────────────────────────────────────────────────────────

    public function testTranscriptFiltersNulls(): void {
        $blocks = [
            [ 'blockName' => 'core/spacer',    'attrs' => [], 'innerHTML' => '' ],
            [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Hello</p>' ],
        ];
        $transcript = $this->sim->transcript( $blocks );
        $this->assertCount( 1, $transcript );
        $this->assertStringContainsString( 'Hello', $transcript[0] );
    }

    public function testTranscriptEmptyBlocksReturnsEmptyArray(): void {
        $this->assertSame( [], $this->sim->transcript( [] ) );
    }

    public function testTranscriptMixedContent(): void {
        $blocks = [
            [ 'blockName' => 'core/heading',   'attrs' => [ 'content' => 'Title', 'level' => 1 ], 'innerHTML' => '' ],
            [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Body text</p>' ],
            [ 'blockName' => 'core/image',     'attrs' => [], 'innerHTML' => '' ],
        ];
        $transcript = $this->sim->transcript( $blocks );
        $this->assertCount( 3, $transcript );
        $this->assertStringContainsString( 'Heading level 1', $transcript[0] );
        $this->assertStringContainsString( 'Paragraph', $transcript[1] );
        $this->assertStringContainsString( 'a11y violation', $transcript[2] );
    }

    // ── innerText ────────────────────────────────────────────────────────────

    public function testInnerTextStripsHtml(): void {
        $this->assertSame( 'Hello', $this->sim->innerText( '<p>Hello</p>' ) );
    }

    public function testInnerTextEmptyStringReturnsEmpty(): void {
        $this->assertSame( '', $this->sim->innerText( '' ) );
    }
}
