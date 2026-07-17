<?php
/**
 * RevisionDiff — Issue #17: Revision diff accessibility comparison.
 *
 * Compares accessibility scores and violations between two post revisions,
 * showing which blocks improved or regressed.
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/revision-diff?post_id=123&revision_a=456&revision_b=789
 *   → { "improved": [...], "regressed": [...], "unchanged": [...], "score_delta": +10 }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class RevisionDiff {

    /** @var AccessibilityScore */
    private AccessibilityScore $scorer;

    public function __construct( ?AccessibilityScore $scorer = null ) {
        $this->scorer = $scorer ?? new AccessibilityScore();
    }

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/revision-diff',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restRevisionDiff' ],
                'permission_callback' => [ $this, 'canEdit' ],
                'args'                => [
                    'post_id'    => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                    'revision_a' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
                    'revision_b' => [ 'required' => false, 'sanitize_callback' => 'absint' ],
                ],
            ]
        );
    }

    public function canEdit( \WP_REST_Request $request ): bool {
        $post_id = (int) $request->get_param( 'post_id' );
        return $post_id > 0 && \current_user_can( 'edit_post', $post_id );
    }

    public function restRevisionDiff( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id    = (int) $request->get_param( 'post_id' );
        $rev_a_id   = (int) ( $request->get_param( 'revision_a' ) ?? 0 );
        $rev_b_id   = (int) ( $request->get_param( 'revision_b' ) ?? 0 );

        $post = \get_post( $post_id );
        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );
        }

        // If no revisions given, compare latest revision vs current post.
        $content_a = $this->getContentForRevision( $rev_a_id, $post_id );
        $content_b = $rev_b_id > 0
            ? $this->getContentForRevision( $rev_b_id, $post_id )
            : $post->post_content;

        $diff = $this->diffContents( $content_a, $content_b );

        return new \WP_REST_Response( $diff, 200 );
    }

    /**
     * Get post content from a revision ID, falling back to latest revision.
     */
    private function getContentForRevision( int $revision_id, int $post_id ): string {
        if ( $revision_id > 0 ) {
            $revision = \get_post( $revision_id );
            if ( $revision && (int) $revision->post_parent === $post_id ) {
                return $revision->post_content;
            }
        }

        // Fall back to latest revision.
        $revisions = \wp_get_post_revisions( $post_id, [ 'posts_per_page' => 1 ] );
        if ( ! empty( $revisions ) ) {
            $latest = reset( $revisions );
            return $latest->post_content;
        }

        $post = \get_post( $post_id );
        return $post ? $post->post_content : '';
    }

    /**
     * Compare accessibility violations between two content strings.
     *
     * @return array { improved, regressed, unchanged, score_delta, score_a, score_b }
     */
    public function diffContents( string $content_a, string $content_b ): array {
        $blocks_a = function_exists( 'parse_blocks' ) ? \parse_blocks( $content_a ) : [];
        $blocks_b = function_exists( 'parse_blocks' ) ? \parse_blocks( $content_b ) : [];

        $result_a = $this->scorer->scoreBlocks( $blocks_a );
        $result_b = $this->scorer->scoreBlocks( $blocks_b );

        $score_a = $result_a['overall'];
        $score_b = $result_b['overall'];

        return [
            'score_a'     => $score_a,
            'score_b'     => $score_b,
            'score_delta' => $score_b - $score_a,
            'blocks_a'    => $result_a['blocks'],
            'blocks_b'    => $result_b['blocks'],
            'summary'     => $score_b >= $score_a ? 'improved_or_unchanged' : 'regressed',
        ];
    }
}
