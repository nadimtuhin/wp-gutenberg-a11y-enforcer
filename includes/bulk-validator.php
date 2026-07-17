<?php
/**
 * BulkValidator — Issue #18: WP-CLI bulk post validation command.
 *
 * WP-CLI command: wp gae validate [--post_type=post] [--limit=100] [--fix]
 *
 * Also exposes a REST endpoint for programmatic bulk scan:
 *   POST /wp-json/gae/v1/bulk-validate
 *   Body: { "post_type": "post", "limit": 50 }
 *   → { "scanned": 50, "posts": [ { "post_id": 1, "violations": [...] } ] }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class BulkValidator {

    /** @var Enforcer */
    private Enforcer $enforcer;

    public function __construct( ?Enforcer $enforcer = null ) {
        $this->enforcer = $enforcer ?? new Enforcer();
    }

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );

        if ( defined( 'WP_CLI' ) && \WP_CLI ) {
            \WP_CLI::add_command( 'gae validate', [ $this, 'cliValidate' ] );
        }
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/bulk-validate',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'restBulkValidate' ],
                'permission_callback' => function() { return \current_user_can( 'manage_options' ); },
            ]
        );
    }

    public function restBulkValidate( \WP_REST_Request $request ): \WP_REST_Response {
        $post_type = sanitize_key( $request->get_param( 'post_type' ) ?? 'post' );
        $limit     = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );

        $results = $this->scanPosts( $post_type, $limit );

        return new \WP_REST_Response( $results, 200 );
    }

    /**
     * Scan posts for accessibility violations.
     *
     * @param string $post_type
     * @param int    $limit
     * @return array { scanned: int, posts: array[] }
     */
    public function scanPosts( string $post_type, int $limit ): array {
        /**
         * Fires before bulk post scanning begins.
         *
         * @param string $post_type The post type being scanned.
         * @param int    $limit     Max number of posts to scan.
         */
        \do_action( 'gae_before_bulk_validate', $post_type, $limit );

        $posts = \get_posts( [
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        $results = [];
        foreach ( $posts as $post_id ) {
            $post       = \get_post( (int) $post_id );
            $violations = $this->scanPostContent( $post->post_content ?? '' );
            $results[]  = [
                'post_id'    => (int) $post_id,
                'post_title' => $post->post_title ?? '',
                'violations' => $violations,
                'clean'      => empty( $violations ),
            ];
        }

        $output = [
            'scanned' => count( $results ),
            'posts'   => $results,
        ];

        /**
         * Fires after all posts have been scanned in bulk.
         *
         * @param array $output { scanned: int, posts: array[] }
         */
        \do_action( 'gae_bulk_validation_complete', $output );

        /**
         * Filter the bulk validation results.
         *
         * @param array  $output    { scanned: int, posts: array[] }
         * @param string $post_type The post type that was scanned.
         */
        return \apply_filters( 'gae_bulk_validation_results', $output, $post_type );
    }

    /**
     * Scan a single post content string and return all violations.
     *
     * @return array[] [ ['block' => 'core/image', 'message' => '...'] ]
     */
    public function scanPostContent( string $content ): array {
        if ( ! function_exists( 'parse_blocks' ) ) {
            return [];
        }

        $blocks     = \parse_blocks( $content );
        $violations = [];

        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }
            $msgs = $this->enforcer->getViolations( $block );
            foreach ( $msgs as $msg ) {
                $violations[] = [
                    'block'   => $block['blockName'],
                    'message' => $msg,
                ];
            }
        }

        return $violations;
    }

    /**
     * WP-CLI command handler.
     *
     * @param array $args       Positional args (unused).
     * @param array $assoc_args Named args: post_type, limit.
     */
    public function cliValidate( array $args, array $assoc_args ): void {
        $post_type = sanitize_key( $assoc_args['post_type'] ?? 'post' );
        $limit     = (int) ( $assoc_args['limit'] ?? 100 );

        \WP_CLI::log( "Scanning {$limit} posts of type '{$post_type}'…" );

        $results = $this->scanPosts( $post_type, $limit );
        $scanned = $results['scanned'];
        $errors  = 0;

        foreach ( $results['posts'] as $row ) {
            if ( ! $row['clean'] ) {
                $errors++;
                \WP_CLI::warning( "Post #{$row['post_id']} ({$row['post_title']}): " . count( $row['violations'] ) . ' violation(s)' );
                foreach ( $row['violations'] as $v ) {
                    \WP_CLI::log( "  [{$v['block']}] {$v['message']}" );
                }
            }
        }

        \WP_CLI::success( "Scanned {$scanned} posts. {$errors} post(s) with violations." );
    }
}
