<?php
/**
 * Tests for MockBlockConfig helper (Issue #10).
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

// Minimal WP stubs (copied pattern from existing tests).
if ( ! function_exists( 'add_action' ) )          { function add_action() {} }
if ( ! function_exists( 'add_filter' ) )          { function add_filter() {} }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'wp_enqueue_script' ) )   { function wp_enqueue_script() {} }
if ( ! function_exists( 'wp_localize_script' ) )  { function wp_localize_script() {} }
if ( ! function_exists( 'plugins_url' ) )         { function plugins_url( $p, $pl ) { return '/plugins/' . $p; } }
if ( ! function_exists( 'plugin_dir_path' ) )     { function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'get_post_meta' ) )       { function get_post_meta( $id, $k, $s = false ) { return $s ? '' : []; } }
if ( ! function_exists( 'get_the_ID' ) )          { function get_the_ID() { return 0; } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $s ) { return strip_tags( $s ); } }

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/MockBlockConfig.php';

class MockBlockConfigTest extends TestCase {

    // ── Factory methods ──────────────────────────────────────────────────────

    public function testWithDefaultsReturnsSettingsDefaults(): void {
        $mock = MockBlockConfig::withDefaults();
        $this->assertSame( \GutenbergA11yEnforcer\Settings::defaults(), $mock->toArray() );
    }

    public function testWithRulesStoresCustomRules(): void {
        $rules = [ 'core/image' => [ 'require_alt' ] ];
        $mock  = MockBlockConfig::withRules( $rules );
        $this->assertSame( $rules, $mock->toArray() );
    }

    public function testPermissiveReturnsEmptyArray(): void {
        $mock = MockBlockConfig::permissive();
        $this->assertSame( [], $mock->toArray() );
    }

    public function testBlockFactoryStoresSingleBlock(): void {
        $mock = MockBlockConfig::block( 'core/image', [ 'require_alt' ] );
        $this->assertArrayHasKey( 'core/image', $mock->toArray() );
        $this->assertSame( [ 'require_alt' ], $mock->toArray()['core/image'] );
    }

    // ── Mutation helpers ─────────────────────────────────────────────────────

    public function testWithBlockAddsNewBlock(): void {
        $mock = MockBlockConfig::permissive()->withBlock( 'core/image', [ 'require_alt' ] );
        $this->assertArrayHasKey( 'core/image', $mock->toArray() );
    }

    public function testWithBlockDoesNotMutateOriginal(): void {
        $original = MockBlockConfig::permissive();
        $original->withBlock( 'core/image', [ 'require_alt' ] );
        $this->assertSame( [], $original->toArray() );
    }

    public function testWithoutBlockRemovesEntry(): void {
        $mock = MockBlockConfig::withDefaults()->withoutBlock( 'core/image' );
        $this->assertArrayNotHasKey( 'core/image', $mock->toArray() );
    }

    // ── Block array builders ─────────────────────────────────────────────────

    public function testImageBlockHasCorrectBlockName(): void {
        $block = MockBlockConfig::imageBlock( [ 'alt' => 'Cat' ] );
        $this->assertSame( 'core/image', $block['blockName'] );
        $this->assertSame( 'Cat', $block['attrs']['alt'] );
    }

    public function testButtonBlockWithTextHasTextAttr(): void {
        $block = MockBlockConfig::buttonBlock( 'Buy now' );
        $this->assertSame( 'core/button', $block['blockName'] );
        $this->assertSame( 'Buy now', $block['attrs']['text'] );
    }

    public function testButtonBlockEmptyTextProducesNoAttr(): void {
        $block = MockBlockConfig::buttonBlock( '' );
        $this->assertArrayNotHasKey( 'text', $block['attrs'] );
    }

    public function testHeadingBlockHasLevelAttr(): void {
        $block = MockBlockConfig::headingBlock( 'Hello', 3 );
        $this->assertSame( 'core/heading', $block['blockName'] );
        $this->assertSame( 3, $block['attrs']['level'] );
        $this->assertSame( 'Hello', $block['attrs']['content'] );
    }

    public function testParagraphBlockHasCorrectName(): void {
        $block = MockBlockConfig::paragraphBlock( '<p>Hi</p>' );
        $this->assertSame( 'core/paragraph', $block['blockName'] );
        $this->assertSame( '<p>Hi</p>', $block['innerHTML'] );
    }

    // ── Integration: use mock config with Enforcer ───────────────────────────

    public function testPermissiveConfigMakesImageAlwaysPass(): void {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $enforcer->setConfig( MockBlockConfig::permissive()->toArray() );

        $block = MockBlockConfig::imageBlock(); // no alt
        $this->assertTrue( $enforcer->validateBlock( $block ) );
    }

    public function testDefaultConfigEnforcesAltOnImage(): void {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $enforcer->setConfig( MockBlockConfig::withDefaults()->toArray() );

        $this->assertFalse( $enforcer->validateBlock( MockBlockConfig::imageBlock() ) );
        $this->assertTrue( $enforcer->validateBlock( MockBlockConfig::imageBlock( [ 'alt' => 'Cat' ] ) ) );
    }

    public function testSingleBlockConfigOnlyEnforcesTargetBlock(): void {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $enforcer->setConfig( MockBlockConfig::block( 'core/image', [ 'require_alt' ] )->toArray() );

        // image fails without alt
        $this->assertFalse( $enforcer->validateBlock( MockBlockConfig::imageBlock() ) );
        // button not in config → always passes
        $this->assertTrue( $enforcer->validateBlock( MockBlockConfig::buttonBlock( '' ) ) );
    }
}
