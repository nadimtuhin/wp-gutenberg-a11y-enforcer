<?php
/**
 * Validation log: DB table, write, REST endpoint, CSV export.
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class ValidationLog {

    const TABLE_VERSION_OPTION = 'gae_log_table_version';
    const TABLE_VERSION        = 1;

    private function tableName(): string {
        global $wpdb;
        return $wpdb->prefix . 'gae_validation_log';
    }

    /**
     * Create or upgrade the log table. Call on plugin activation.
     */
    public function maybeCreateTable(): void {
        $installed = (int) get_option( self::TABLE_VERSION_OPTION, 0 );
        if ( $installed >= self::TABLE_VERSION ) {
            return;
        }

        global $wpdb;
        $table      = $this->tableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            block_name  VARCHAR(100)    NOT NULL DEFAULT '',
            rule        VARCHAR(100)    NOT NULL DEFAULT '',
            severity    VARCHAR(20)     NOT NULL DEFAULT 'error',
            message     TEXT            NOT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION );
    }

    /**
     * Log a single violation.
     */
    public function log( int $post_id, string $block_name, string $rule, string $message, string $severity = 'error' ): void {
        global $wpdb;

        /**
         * Filter the violation data before it is written to the log.
         *
         * @param array $data {
         *     @type int    $post_id    Post ID.
         *     @type string $block_name Block name.
         *     @type string $rule       Rule slug.
         *     @type string $message    Violation message.
         *     @type string $severity   Severity level.
         * }
         */
        $data = \apply_filters( 'gae_log_entry_data', [
            'post_id'    => absint( $post_id ),
            'block_name' => sanitize_text_field( $block_name ),
            'rule'       => sanitize_key( $rule ),
            'severity'   => sanitize_key( $severity ),
            'message'    => wp_kses_post( $message ), // strip XSS; allow safe HTML. Issue #26.
            'created_at' => current_time( 'mysql' ),
        ] );

        $wpdb->insert(
            $this->tableName(),
            $data,
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        /**
         * Fires after a violation is logged.
         *
         * @param array $data    The logged violation data.
         * @param int   $post_id The post ID where the violation occurred.
         */
        \do_action( 'gae_violation_logged', $data, $post_id );
    }

    /**
     * Fetch log entries. Returns array of row objects.
     *
     * @param int $per_page Max rows (capped at 500).
     * @param int $page     1-based page.
     */
    public function getEntries( int $per_page = 50, int $page = 1 ): array {
        global $wpdb;
        $per_page = min( 500, max( 1, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;
        $table    = $this->tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $entries = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        /**
         * Filter the log entries returned from the database.
         *
         * @param array $entries  Array of row objects from the log table.
         * @param int   $per_page Number of entries per page.
         * @param int   $page     Current page number.
         */
        return \apply_filters( 'gae_log_entries', $entries, $per_page, $page );
    }

    /**
     * Total count of log entries.
     * Uses prepare() to prevent SQL injection. Issue #34.
     */
    public function countEntries(): int {
        global $wpdb;
        $table = $this->tableName();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        return (int) $wpdb->get_var(
            // Table name cannot be parameterised with %s in prepare(); it is
            // always the WP prefix + a hardcoded slug — safe here.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE 1=%d", 1 )
        );
    }

    /**
     * Register REST API endpoint and admin CSV download action.
     */
    public function register(): void {
        add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
        add_action( 'admin_post_gae_export_csv', [ $this, 'exportCsv' ] );
    }

    public function registerRestRoutes(): void {
        register_rest_route(
            'gae/v1',
            '/logs',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restGetLogs' ],
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
                'args' => [
                    'per_page' => [
                        'default'           => 50,
                        'sanitize_callback' => 'absint',
                    ],
                    'page' => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * REST GET /gae/v1/logs callback.
     */
    public function restGetLogs( \WP_REST_Request $request ): \WP_REST_Response {
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );
        $entries  = $this->getEntries( $per_page, $page );
        $total    = $this->countEntries();

        $response = new \WP_REST_Response( $entries, 200 );
        $response->header( 'X-WP-Total', $total );
        $response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

        return $response;
    }

    /**
     * Admin POST action: stream all log entries as CSV download.
     */
    public function exportCsv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'wp-gutenberg-a11y-enforcer' ) );
        }
        check_admin_referer( 'gae_export_csv' );

        $entries = $this->getEntries( 500, 1 );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="a11y-validation-log.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Post ID', 'Block', 'Rule', 'Severity', 'Message', 'Date' ] );

        foreach ( $entries as $row ) {
            fputcsv( $out, [
                $row->id,
                $row->post_id,
                $row->block_name,
                $row->rule,
                $row->severity,
                $row->message,
                $row->created_at,
            ] );
        }

        fclose( $out );
        exit;
    }
}
