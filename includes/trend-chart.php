<?php
/**
 * TrendChart — Issue #20: Gutenberg sidebar accessibility score trend chart.
 *
 * Stores per-post accessibility score snapshots in a custom DB table
 * and exposes a REST endpoint so the editor sidebar can render a trend line.
 *
 * Table: {prefix}gae_score_history  (post_id, score, checked_at)
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/score-trend?post_id=123&limit=30
 *   → { "trend": [ { "date": "2024-01-01", "score": 80 }, … ] }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class TrendChart {

    public const TABLE_VERSION_OPTION = 'gae_score_history_version';
    public const TABLE_VERSION        = '1.0';

    /** @var AccessibilityScore */
    private AccessibilityScore $scorer;

    public function __construct( ?AccessibilityScore $scorer = null ) {
        $this->scorer = $scorer ?? new AccessibilityScore();
    }

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'save_post', [ $this, 'onSavePost' ], 20, 2 );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/score-trend',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restScoreTrend' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                    'limit'   => [ 'required' => false, 'sanitize_callback' => 'absint' ],
                ],
            ]
        );
    }

    public function restScoreTrend( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) $request->get_param( 'post_id' );
        $limit   = min( 90, max( 7, (int) ( $request->get_param( 'limit' ) ?? 30 ) ) );

        $trend = $this->getTrend( $post_id, $limit );

        return new \WP_REST_Response( [ 'trend' => $trend ], 200 );
    }

    /**
     * Called on save_post to record score snapshot.
     */
    public function onSavePost( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $blocks = \parse_blocks( $post->post_content );
        $result = $this->scorer->scoreBlocks( $blocks );
        $this->recordScore( $post_id, $result['overall'] );
    }

    /**
     * Record a score snapshot for a post.
     */
    public function recordScore( int $post_id, int $score ): void {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }
        $table = $wpdb->prefix . 'gae_score_history';
        $wpdb->insert(
            $table,
            [
                'post_id'    => $post_id,
                'score'      => $score,
                'checked_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s' ]
        );
    }

    /**
     * Get score trend for a post.
     *
     * @param int $post_id
     * @param int $limit   Max rows to return.
     * @return array[] [ ['date' => '2024-01-01', 'score' => 80] ]
     */
    public function getTrend( int $post_id, int $limit = 30 ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return [];
        }
        $table = $wpdb->prefix . 'gae_score_history';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(checked_at) AS date, AVG(score) AS score
                 FROM {$table}
                 WHERE post_id = %d
                 GROUP BY DATE(checked_at)
                 ORDER BY checked_at DESC
                 LIMIT %d",
                $post_id,
                $limit
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_map( function ( array $row ): array {
            return [
                'date'  => $row['date'],
                'score' => (int) round( $row['score'] ),
            ];
        }, array_reverse( $rows ) );
    }

    /**
     * Create or upgrade the score history table.
     */
    public function maybeCreateTable(): void {
        if ( \get_option( self::TABLE_VERSION_OPTION ) === self::TABLE_VERSION ) {
            return;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            return;
        }
        $table   = $wpdb->prefix . 'gae_score_history';
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE {$table} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            score tinyint(3) NOT NULL DEFAULT 0,
            checked_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY checked_at (checked_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        \update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }
}
