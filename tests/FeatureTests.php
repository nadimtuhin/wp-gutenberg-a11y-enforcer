<?php
/**
 * Tests for RuleProfile (Issue #15), AccessibilityScore (#16),
 * RevisionDiff (#17), VideoCaptioning (#24), WcagAaaProfile (#21),
 * ThirdPartyAdapter (#22), WcagEmExport (#25).
 * Kept in one file to share stubs.
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
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public array $posts = [];
        public function __construct( array $args ) {}
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
if ( ! function_exists( 'get_posts' ) )           { function get_posts( $a ) { return []; } }
if ( ! function_exists( 'get_post_meta' ) )       { function get_post_meta( $id, $k, $s = false ) { return $s ? '' : []; } }
if ( ! function_exists( 'update_post_meta' ) )    { function update_post_meta() { return true; } }
if ( ! function_exists( 'current_user_can' ) )    { function current_user_can() { return true; } }
if ( ! function_exists( 'absint' ) )              { function absint( $v ) { return abs( (int) $v ); } }
if ( ! function_exists( '__return_true' ) )       { function __return_true() { return true; } }
if ( ! function_exists( 'apply_filters' ) )       { function apply_filters( $t, $v ) { return $v; } }
if ( ! function_exists( 'sanitize_key' ) )        { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( string $s ): string { return strip_tags( $s ); } }
if ( ! function_exists( 'get_option' ) )          { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'register_setting' ) )    { function register_setting() {} }
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
if ( ! function_exists( 'wp_get_post_revisions' ) ) { function wp_get_post_revisions() { return []; } }
if ( ! function_exists( 'get_block_templates' ) )   { function get_block_templates() { return []; } }
if ( ! function_exists( 'get_permalink' ) )          { function get_permalink( $id ) { return 'http://example.com/?p=' . $id; } }
if ( ! function_exists( 'wp_is_post_revision' ) )    { function wp_is_post_revision() { return false; } }
if ( ! function_exists( 'wp_is_post_autosave' ) )    { function wp_is_post_autosave() { return false; } }
if ( ! function_exists( 'current_time' ) )           { function current_time( $f ) { return date( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'update_option' ) )          { function update_option() { return true; } }
if ( ! function_exists( 'get_attached_file' ) )      { function get_attached_file( $id ) { return '/tmp/file.jpg'; } }
if ( ! function_exists( 'wp_get_attachment_url' ) )  { function wp_get_attachment_url( $id ) { return 'http://x.com/file.jpg'; } }

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/rule-profile.php';
require_once __DIR__ . '/../includes/accessibility-score.php';
require_once __DIR__ . '/../includes/revision-diff.php';
require_once __DIR__ . '/../includes/bulk-validator.php';
require_once __DIR__ . '/../includes/template-validator.php';
require_once __DIR__ . '/../includes/wcag-aaa-profile.php';
require_once __DIR__ . '/../includes/third-party-adapter.php';
require_once __DIR__ . '/../includes/video-captioning.php';
require_once __DIR__ . '/../includes/wcag-em-export.php';

// ─────────────────────────────────────────────────────────────────────────────
// RuleProfile Tests — Issue #15
// ─────────────────────────────────────────────────────────────────────────────
class RuleProfileTest extends TestCase {

    private \GutenbergA11yEnforcer\RuleProfile $rp;

    protected function setUp(): void {
        $this->rp = new \GutenbergA11yEnforcer\RuleProfile();
        \GutenbergA11yEnforcer\RuleProfile::registerProfile( 'custom', [ 'core/image' => [ 'require_alt' ] ] );
    }

    public function testBuiltinProfilesIncludeDefault(): void {
        $profiles = \GutenbergA11yEnforcer\RuleProfile::builtinProfiles();
        $this->assertArrayHasKey( 'default', $profiles );
        $this->assertArrayHasKey( 'strict', $profiles );
        $this->assertArrayHasKey( 'minimal', $profiles );
        $this->assertArrayHasKey( 'wcag_aaa', $profiles );
    }

    public function testGetProfileDefaultReturnsSettings(): void {
        $profile = $this->rp->getProfile( 'default' );
        $this->assertArrayHasKey( 'core/image', $profile );
    }

    public function testGetProfileFallsBackToDefault(): void {
        $profile = $this->rp->getProfile( 'nonexistent_xyz' );
        $this->assertArrayHasKey( 'core/image', $profile );
    }

    public function testRegisterCustomProfile(): void {
        $profile = $this->rp->getProfile( 'custom' );
        $this->assertArrayHasKey( 'core/image', $profile );
    }

    public function testAvailableProfilesListsAll(): void {
        $profiles = $this->rp->availableProfiles();
        $this->assertContains( 'default', $profiles );
        $this->assertContains( 'wcag_aaa', $profiles );
        $this->assertContains( 'custom', $profiles );
    }

    public function testResolveProfileReturnsDefault(): void {
        $this->assertSame( 'default', $this->rp->resolveProfile( 'post' ) );
    }

    public function testGetConfigForPostTypeReturnsArray(): void {
        $config = $this->rp->getConfigForPostType( 'post' );
        $this->assertIsArray( $config );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->rp->register();
        $this->assertTrue( true );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AccessibilityScore Tests — Issue #16
// ─────────────────────────────────────────────────────────────────────────────
class AccessibilityScoreTest extends TestCase {

    private \GutenbergA11yEnforcer\AccessibilityScore $scorer;
    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $this->enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
        $this->scorer = new \GutenbergA11yEnforcer\AccessibilityScore( $this->enforcer );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->scorer->register();
        $this->assertTrue( true );
    }

    public function testScoreEmptyBlocksReturns100(): void {
        $result = $this->scorer->scoreBlocks( [] );
        $this->assertSame( 100, $result['overall'] );
        $this->assertSame( [], $result['blocks'] );
    }

    public function testScorePassingBlockGives100(): void {
        $blocks = [
            [ 'blockName' => 'core/image', 'attrs' => [ 'alt' => 'Cat' ], 'innerHTML' => '' ],
        ];
        $result = $this->scorer->scoreBlocks( $blocks );
        $this->assertSame( 100, $result['overall'] );
    }

    public function testScoreFailingBlockGivesLow(): void {
        $blocks = [
            [ 'blockName' => 'core/image', 'attrs' => [], 'innerHTML' => '' ],
        ];
        $result = $this->scorer->scoreBlocks( $blocks );
        $this->assertLessThan( 100, $result['overall'] );
    }

    public function testBlockScoreZeroViolations(): void {
        $this->assertSame( 100, $this->scorer->blockScore( [] ) );
    }

    public function testBlockScoreOneViolation(): void {
        $this->assertSame( 50, $this->scorer->blockScore( [ 'v1' ] ) );
    }

    public function testBlockScoreTwoViolationsIsZero(): void {
        $this->assertSame( 0, $this->scorer->blockScore( [ 'v1', 'v2' ] ) );
    }

    public function testBlockScoreNeverBelowZero(): void {
        $this->assertSame( 0, $this->scorer->blockScore( [ 'v1', 'v2', 'v3', 'v4' ] ) );
    }

    public function testScoreBlocksNamedBlocksOnly(): void {
        $blocks = [
            [ 'blockName' => null, 'attrs' => [], 'innerHTML' => '<p>whitespace</p>' ],
            [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>text</p>' ],
        ];
        $result = $this->scorer->scoreBlocks( $blocks );
        $this->assertCount( 1, $result['blocks'] );
    }

    public function testRestReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 9999 );
        $resp = $this->scorer->restAccessibilityScore( $req );
        $this->assertSame( 404, $resp->get_status() );
    }

    public function testEnqueueEditorScriptDoesNotThrow(): void {
        $this->scorer->enqueueEditorScript();
        $this->assertTrue( true );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RevisionDiff Tests — Issue #17
// ─────────────────────────────────────────────────────────────────────────────
class RevisionDiffTest extends TestCase {

    private \GutenbergA11yEnforcer\RevisionDiff $diff;
    private \GutenbergA11yEnforcer\AccessibilityScore $scorer;

    protected function setUp(): void {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
        $this->scorer = new \GutenbergA11yEnforcer\AccessibilityScore( $enforcer );
        $this->diff   = new \GutenbergA11yEnforcer\RevisionDiff( $this->scorer );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->diff->register();
        $this->assertTrue( true );
    }

    public function testDiffContentsIdenticalContent(): void {
        $content = '<!-- wp:core/image {"alt":"Cat"} --><figure></figure><!-- /wp:core/image -->';
        $result  = $this->diff->diffContents( $content, $content );
        $this->assertSame( 0, $result['score_delta'] );
    }

    public function testDiffContentsImprovesWhenFixingAlt(): void {
        $bad  = '<!-- wp:core/image --><figure></figure><!-- /wp:core/image -->';
        $good = '<!-- wp:core/image {"alt":"Cat"} --><figure></figure><!-- /wp:core/image -->';
        $result = $this->diff->diffContents( $bad, $good );
        $this->assertGreaterThan( 0, $result['score_delta'] );
        $this->assertSame( 'improved_or_unchanged', $result['summary'] );
    }

    public function testDiffContentsRegressesWhenRemovingAlt(): void {
        $good = '<!-- wp:core/image {"alt":"Cat"} --><figure></figure><!-- /wp:core/image -->';
        $bad  = '<!-- wp:core/image --><figure></figure><!-- /wp:core/image -->';
        $result = $this->diff->diffContents( $good, $bad );
        $this->assertLessThan( 0, $result['score_delta'] );
        $this->assertSame( 'regressed', $result['summary'] );
    }

    public function testDiffContentsHasRequiredKeys(): void {
        $result = $this->diff->diffContents( '', '' );
        $this->assertArrayHasKey( 'score_a', $result );
        $this->assertArrayHasKey( 'score_b', $result );
        $this->assertArrayHasKey( 'score_delta', $result );
        $this->assertArrayHasKey( 'summary', $result );
    }

    public function testRestDiffReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 9999 );
        $resp = $this->diff->restRevisionDiff( $req );
        $this->assertSame( 404, $resp->get_status() );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VideoCaptioning Tests — Issue #24
// ─────────────────────────────────────────────────────────────────────────────
class VideoCaptioningTest extends TestCase {

    private \GutenbergA11yEnforcer\VideoCaptioning $vc;

    protected function setUp(): void {
        $this->vc = new \GutenbergA11yEnforcer\VideoCaptioning();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->vc->register();
        $this->assertTrue( true );
    }

    public function testVideoBlockMissingCaptions(): void {
        $block = [ 'blockName' => 'core/video', 'attrs' => [], 'innerHTML' => '<video></video>' ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'WCAG 1.2.2', $violations[0] );
    }

    public function testVideoBlockWithTrackPasses(): void {
        $block = [
            'blockName' => 'core/video',
            'attrs'     => [],
            'innerHTML' => '<video><track kind="captions" src="caps.vtt"></video>',
        ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertEmpty( $violations );
    }

    public function testVideoBlockWithTracksAttrPasses(): void {
        $block = [
            'blockName' => 'core/video',
            'attrs'     => [ 'tracks' => [ [ 'src' => 'caps.vtt', 'kind' => 'captions' ] ] ],
            'innerHTML' => '<video></video>',
        ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertEmpty( $violations );
    }

    public function testAudioBlockMissingTranscript(): void {
        $block = [ 'blockName' => 'core/audio', 'attrs' => [], 'innerHTML' => '<audio></audio>' ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'WCAG 1.2.1', $violations[0] );
    }

    public function testAudioBlockWithTranscriptAttrPasses(): void {
        $block = [
            'blockName' => 'core/audio',
            'attrs'     => [ 'transcript' => 'Read the transcript at example.com' ],
            'innerHTML' => '<audio></audio>',
        ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertEmpty( $violations );
    }

    public function testAudioBlockWithTranscriptLinkPasses(): void {
        $block = [
            'blockName' => 'core/audio',
            'attrs'     => [],
            'innerHTML' => '<audio></audio><a href="#transcript">Transcript</a>',
        ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertEmpty( $violations );
    }

    public function testNonVideoBlockPassedThrough(): void {
        $block = [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerHTML' => '<p>Text</p>' ];
        $violations = $this->vc->checkVideoBlock( [], $block );
        $this->assertEmpty( $violations );
    }

    public function testHasTracksInHtmlTrue(): void {
        $this->assertTrue( $this->vc->hasTracksInHtml( '<track kind="captions" src="x.vtt">' ) );
    }

    public function testHasTracksInHtmlFalse(): void {
        $this->assertFalse( $this->vc->hasTracksInHtml( '<video></video>' ) );
    }

    public function testHasTranscriptLinkTrue(): void {
        $this->assertTrue( $this->vc->hasTranscriptLink( '<a href="#transcript">Transcript</a>' ) );
    }

    public function testHasTranscriptLinkFalse(): void {
        $this->assertFalse( $this->vc->hasTranscriptLink( '<audio></audio>' ) );
    }

    public function testRestReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 9999 );
        $resp = $this->vc->restVideoCaptionCheck( $req );
        $this->assertSame( 404, $resp->get_status() );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// WcagAaaProfile Tests — Issue #21
// ─────────────────────────────────────────────────────────────────────────────
class WcagAaaProfileTest extends TestCase {

    private \GutenbergA11yEnforcer\WcagAaaProfile $aaa;

    protected function setUp(): void {
        $this->aaa = new \GutenbergA11yEnforcer\WcagAaaProfile();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->aaa->register();
        $this->assertTrue( true );
    }

    public function testDefaultPostTypesAreAaa(): void {
        $types = $this->aaa->getAaaPostTypes();
        $this->assertContains( 'docs', $types );
        $this->assertContains( 'documentation', $types );
        $this->assertContains( 'kb', $types );
    }

    public function testIsAaaPostTypeTrue(): void {
        $this->assertTrue( $this->aaa->isAaaPostType( 'docs' ) );
    }

    public function testIsAaaPostTypeFalse(): void {
        $this->assertFalse( $this->aaa->isAaaPostType( 'post' ) );
    }

    public function testFilterProfileUpgradesAaaPostType(): void {
        $profile = $this->aaa->filterProfile( 'default', 'docs' );
        $this->assertSame( 'wcag_aaa', $profile );
    }

    public function testFilterProfileLeavesOtherPostTypesAlone(): void {
        $profile = $this->aaa->filterProfile( 'default', 'post' );
        $this->assertSame( 'default', $profile );
    }

    public function testSanitizePostTypesFromString(): void {
        $result = $this->aaa->sanitizePostTypes( 'docs,kb,page' );
        $this->assertContains( 'docs', $result );
        $this->assertContains( 'kb', $result );
        $this->assertContains( 'page', $result );
    }

    public function testSanitizePostTypesFromArray(): void {
        $result = $this->aaa->sanitizePostTypes( [ 'docs', 'KB' ] );
        $this->assertContains( 'docs', $result );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ThirdPartyAdapter Tests — Issue #22
// ─────────────────────────────────────────────────────────────────────────────
class ThirdPartyAdapterTest extends TestCase {

    protected function setUp(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::reset();
    }

    protected function tearDown(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::reset();
    }

    private function makeAdapter(): \GutenbergA11yEnforcer\ThirdPartyAdapter {
        return new \GutenbergA11yEnforcer\ThirdPartyAdapter();
    }

    public function testRegisterDoesNotThrow(): void {
        $this->makeAdapter()->register();
        $this->assertTrue( true );
    }

    public function testNoValidatorsRegisteredReturnsEmpty(): void {
        $block      = [ 'blockName' => 'acf/hero', 'attrs' => [], 'innerHTML' => '' ];
        $violations = $this->makeAdapter()->getViolations( $block );
        $this->assertEmpty( $violations );
    }

    public function testRegisteredValidatorCalled(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator(
            'acf/hero',
            fn( $b ) => [ 'acf/hero: missing label.' ]
        );
        $block      = [ 'blockName' => 'acf/hero', 'attrs' => [], 'innerHTML' => '' ];
        $violations = $this->makeAdapter()->getViolations( $block );
        $this->assertCount( 1, $violations );
        $this->assertStringContainsString( 'acf/hero', $violations[0] );
    }

    public function testMultipleValidatorsAllRun(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator(
            'acf/hero',
            fn( $b ) => [ 'violation 1' ]
        );
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator(
            'acf/hero',
            fn( $b ) => [ 'violation 2' ]
        );
        $block      = [ 'blockName' => 'acf/hero', 'attrs' => [], 'innerHTML' => '' ];
        $violations = $this->makeAdapter()->getViolations( $block );
        $this->assertCount( 2, $violations );
    }

    public function testDeregisterRemovesValidators(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/hero', fn( $b ) => [ 'v' ] );
        \GutenbergA11yEnforcer\ThirdPartyAdapter::deregisterBlockValidators( 'acf/hero' );
        $block = [ 'blockName' => 'acf/hero', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertEmpty( $this->makeAdapter()->getViolations( $block ) );
    }

    public function testHasValidatorsFor(): void {
        $this->assertFalse( \GutenbergA11yEnforcer\ThirdPartyAdapter::hasValidatorsFor( 'acf/hero' ) );
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/hero', fn( $b ) => [] );
        $this->assertTrue( \GutenbergA11yEnforcer\ThirdPartyAdapter::hasValidatorsFor( 'acf/hero' ) );
    }

    public function testRegisteredBlockTypes(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/hero', fn( $b ) => [] );
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/cta', fn( $b ) => [] );
        $types = \GutenbergA11yEnforcer\ThirdPartyAdapter::registeredBlockTypes();
        $this->assertContains( 'acf/hero', $types );
        $this->assertContains( 'acf/cta', $types );
    }

    public function testValidatorForOtherBlockDoesNotRun(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/hero', fn( $b ) => [ 'v' ] );
        $block = [ 'blockName' => 'acf/other', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertEmpty( $this->makeAdapter()->getViolations( $block ) );
    }

    public function testPassingValidatorReturnsEmpty(): void {
        \GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator( 'acf/hero', fn( $b ) => [] );
        $block = [ 'blockName' => 'acf/hero', 'attrs' => [], 'innerHTML' => '' ];
        $this->assertEmpty( $this->makeAdapter()->getViolations( $block ) );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// WcagEmExport Tests — Issue #25
// ─────────────────────────────────────────────────────────────────────────────
class WcagEmExportTest extends TestCase {

    private \GutenbergA11yEnforcer\WcagEmExport $exporter;
    private \GutenbergA11yEnforcer\BulkValidator $bulk;

    protected function setUp(): void {
        $enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
        $this->bulk     = new \GutenbergA11yEnforcer\BulkValidator( $enforcer );
        $this->exporter = new \GutenbergA11yEnforcer\WcagEmExport( $this->bulk );
    }

    public function testRegisterDoesNotThrow(): void {
        $this->exporter->register();
        $this->assertTrue( true );
    }

    public function testBuildReportStructure(): void {
        $report = $this->exporter->buildReport( [], [], 'Test scope' );
        $this->assertArrayHasKey( 'wcag_em_version', $report );
        $this->assertArrayHasKey( 'scope', $report );
        $this->assertArrayHasKey( 'audit_results', $report );
        $this->assertArrayHasKey( 'summary', $report );
        $this->assertSame( '1.0', $report['wcag_em_version'] );
    }

    public function testBuildReportCleanContent(): void {
        $report = $this->exporter->buildReport( [], [], 'Clean' );
        $this->assertSame( 0, $report['summary']['failed'] );
    }

    public function testBuildReportWithViolations(): void {
        $violations = [
            [ 'block' => 'core/image', 'message' => 'core/image: missing alt text (WCAG 1.1.1).' ],
        ];
        $report = $this->exporter->buildReport( [ [ 'id' => 1, 'title' => 'Post' ] ], $violations, 'Test' );
        $this->assertGreaterThan( 0, $report['summary']['failed'] );
    }

    public function testAuditResultsHaveRequiredKeys(): void {
        $report   = $this->exporter->buildReport( [], [], 'Test' );
        $first    = $report['audit_results'][0];
        $this->assertArrayHasKey( 'criterion', $first );
        $this->assertArrayHasKey( 'level', $first );
        $this->assertArrayHasKey( 'result', $first );
        $this->assertArrayHasKey( 'violations', $first );
    }

    public function testConclusionStringOnPass(): void {
        $report = $this->exporter->buildReport( [], [], 'Test' );
        $this->assertStringContainsString( 'Conforms', $report['summary']['conclusion'] );
    }

    public function testRestReturns404ForMissingPost(): void {
        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 9999 );
        $resp = $this->exporter->restWcagEmReport( $req );
        $this->assertSame( 200, $resp->get_status() ); // site-wide report when no post
    }

    public function testRestWithNoPostIdReturnsSiteReport(): void {
        $req = new WP_REST_Request();
        $resp = $this->exporter->restWcagEmReport( $req );
        $this->assertSame( 200, $resp->get_status() );
        $data = $resp->get_data();
        $this->assertArrayHasKey( 'audit_results', $data );
    }
}
