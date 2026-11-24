<?php
/**
 * Regression tests for save-filter correctness and REST authorization fixes.
 *
 * Relies on the shared stubs defined by earlier test files (AiAltTextTest,
 * EnforcerTest, FeatureTests); PHPUnit loads test files alphabetically.
 */
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PHPUNIT_RUNNING' ) ) {
    define( 'PHPUNIT_RUNNING', true );
}

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/validation-log.php';
require_once __DIR__ . '/../includes/enforcer.php';
require_once __DIR__ . '/../includes/accessibility-score.php';
require_once __DIR__ . '/../includes/trend-chart.php';
require_once __DIR__ . '/../includes/video-captioning.php';

class RegressionFixesTest extends TestCase {

    private \GutenbergA11yEnforcer\Enforcer $enforcer;

    protected function setUp(): void {
        $this->enforcer = new \GutenbergA11yEnforcer\Enforcer();
        $this->enforcer->setConfig( \GutenbergA11yEnforcer\Settings::defaults() );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['_gae_test_user_can'], $GLOBALS['wpdb'] );
    }

    // ── content_save_pre receives slashed content ──────────────────────────

    public function testFilterContentPreservesSlashedBlockAttributes(): void {
        $raw     = '<!-- wp:core/heading {"level":3} --><h3>Title</h3><!-- /wp:core/heading -->';
        $slashed = wp_slash( $raw );

        $result = $this->enforcer->filterContent( $slashed );

        // Output must stay slashed for wp_insert_post and keep the attributes.
        $this->assertSame( $slashed, $result );
    }

    public function testFilterContentKeepsSlashedImageWithAltInMarkup(): void {
        $raw = '<!-- wp:core/image {"id":5} --><figure class="wp-block-image"><img src="a.jpg" alt="A cat" class="wp-image-5"/></figure><!-- /wp:core/image -->';

        $result = $this->enforcer->filterContent( wp_slash( $raw ) );

        $this->assertStringContainsString( 'alt=\"A cat\"', $result );
    }

    // ── core/image alt is sourced from <img alt>, not the block comment ───

    public function testImageWithAltOnlyInMarkupPasses(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [ 'id' => 5 ],
            'innerHTML' => '<figure class="wp-block-image"><img src="a.jpg" alt="A cat"/></figure>',
        ];
        $this->assertSame( [], $this->enforcer->getViolations( $block ) );
    }

    public function testImageWithEmptyAltInMarkupFails(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [],
            'innerHTML' => '<figure class="wp-block-image"><img src="a.jpg" alt=""/></figure>',
        ];
        $this->assertCount( 1, $this->enforcer->getViolations( $block ) );
    }

    public function testImageWithoutAltAttributeInMarkupFails(): void {
        $block = [
            'blockName' => 'core/image',
            'attrs'     => [],
            'innerHTML' => '<figure class="wp-block-image"><img src="a.jpg" data-alt="x"/></figure>',
        ];
        $this->assertCount( 1, $this->enforcer->getViolations( $block ) );
    }

    // ── REST endpoints must check access to the specific post ─────────────

    /**
     * @return array<string, array{0: object}>
     */
    public function postScopedEndpointProvider(): array {
        return [
            'score-trend'         => [ new \GutenbergA11yEnforcer\TrendChart() ],
            'video-caption-check' => [ new \GutenbergA11yEnforcer\VideoCaptioning() ],
            'accessibility-score' => [ new \GutenbergA11yEnforcer\AccessibilityScore() ],
        ];
    }

    /**
     * @dataProvider postScopedEndpointProvider
     */
    public function testPostScopedEndpointDeniesUserWhoCannotEditPost( object $endpoint ): void {
        $checked = [];
        $GLOBALS['_gae_test_user_can'] = function ( $cap, ...$args ) use ( &$checked ) {
            $checked[] = [ $cap, $args[0] ?? null ];
            // User may edit posts in general, but not post 42.
            return 'edit_posts' === $cap;
        };

        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 42 );

        $this->assertFalse( $endpoint->canAccessPost( $req ) );
        $this->assertContains( [ 'edit_post', 42 ], $checked );
    }

    /**
     * @dataProvider postScopedEndpointProvider
     */
    public function testPostScopedEndpointAllowsUserWhoCanEditPost( object $endpoint ): void {
        $GLOBALS['_gae_test_user_can'] = fn( $cap, ...$args ) => 'edit_post' === $cap && 42 === ( $args[0] ?? null );

        $req = new WP_REST_Request();
        $req->set_param( 'post_id', 42 );

        $this->assertTrue( $endpoint->canAccessPost( $req ) );
    }

    public function testNoPostScopedEndpointIsPublic(): void {
        foreach ( [ 'trend-chart.php', 'video-captioning.php' ] as $file ) {
            $source = file_get_contents( __DIR__ . '/../includes/' . $file );
            $this->assertStringNotContainsString( "'__return_true'", $source, $file );
        }
    }

    // ── /gae/v1/logs per_page=0 must not fatal on PHP 8 ───────────────────

    public function testRestGetLogsHandlesZeroPerPage(): void {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function prepare( $q, ...$a ) { return vsprintf( str_replace( '%d', '%s', $q ), $a ); }
            public function get_results( $q ) { return []; }
            public function get_var( $q ) { return 7; }
        };

        $req = new WP_REST_Request();
        $req->set_param( 'per_page', 0 );
        $req->set_param( 'page', 0 );

        $resp = ( new \GutenbergA11yEnforcer\ValidationLog() )->restGetLogs( $req );

        $this->assertSame( 200, $resp->get_status() );
        $headers = $resp->get_headers();
        $this->assertSame( 7, $headers['X-WP-Total'] );
        $this->assertSame( 7, $headers['X-WP-TotalPages'] ); // clamped per_page = 1.
    }
}
