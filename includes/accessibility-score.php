<?php
/**
 * AccessibilityScore — Issue #16: Block-level accessibility score badge.
 *
 * Computes a per-block and overall post accessibility score (0–100).
 * Exposes a REST endpoint used by the editor sidebar badge.
 *
 * Score algorithm:
 *   - Each block is checked against its rules.
 *   - A block with 0 violations scores 100; each violation subtracts evenly.
 *   - Overall = average of all block scores.
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/accessibility-score?post_id=123
 *   → { "overall": 85, "blocks": [ { "name": "core/image", "score": 0, "violations": [...] } ] }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class AccessibilityScore {

    /** @var Enforcer */
    private Enforcer $enforcer;

    public function __construct( ?Enforcer $enforcer = null ) {
        $this->enforcer = $enforcer ?? new Enforcer();
    }

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/accessibility-score',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restAccessibilityScore' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function restAccessibilityScore( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) $request->get_param( 'post_id' );
        $post    = \get_post( $post_id );

        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );
        }

        $blocks = \parse_blocks( $post->post_content );
        $result = $this->scoreBlocks( $blocks );

        return new \WP_REST_Response( $result, 200 );
    }

    /**
     * Score an array of parsed blocks.
     *
     * @param array[] $blocks
     * @return array { overall: int, blocks: array[] }
     */
    public function scoreBlocks( array $blocks ): array {
        $named_blocks = array_filter( $blocks, fn( $b ) => ! empty( $b['blockName'] ) );
        $named_blocks = array_values( $named_blocks );

        if ( empty( $named_blocks ) ) {
            return [ 'overall' => 100, 'blocks' => [] ];
        }

        $scored = [];
        foreach ( $named_blocks as $block ) {
            $violations = $this->enforcer->getViolations( $block );
            $score      = $this->blockScore( $violations );
            $scored[]   = [
                'name'       => $block['blockName'],
                'score'      => $score,
                'violations' => $violations,
            ];
        }

        $overall = (int) round( array_sum( array_column( $scored, 'score' ) ) / count( $scored ) );

        return [
            'overall' => $overall,
            'blocks'  => $scored,
        ];
    }

    /**
     * Score a single block: 100 minus 50 per violation (floor 0).
     *
     * @param string[] $violations
     * @return int 0–100
     */
    public function blockScore( array $violations ): int {
        return max( 0, 100 - count( $violations ) * 50 );
    }

    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-accessibility-score',
            \plugins_url( 'assets/js/accessibility-score.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch' ],
            '1.0.0',
            true
        );
        \wp_localize_script(
            'gae-accessibility-score',
            'gaeScore',
            [ 'restUrl' => \rest_url( 'gae/v1/accessibility-score' ) ]
        );
    }
}
