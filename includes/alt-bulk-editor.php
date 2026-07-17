<?php
/**
 * AltBulkEditor — Issue #23: Media file alt-text bulk editor.
 *
 * Scans all attachments in the media library that lack alt text and exposes
 * a REST endpoint to view them and update alt text in bulk.
 *
 * REST endpoints:
 *   GET  /wp-json/gae/v1/alt-missing          → { "attachments": [...] }
 *   POST /wp-json/gae/v1/alt-bulk-update       → { "updated": N }
 *        Body: { "updates": [ { "id": 123, "alt": "Cat" }, … ] }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class AltBulkEditor {

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoutes' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    public function registerRestRoutes(): void {
        \register_rest_route(
            'gae/v1',
            '/alt-missing',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restAltMissing' ],
                'permission_callback' => function() { return \current_user_can( 'upload_files' ); },
                'args'                => [
                    'limit'  => [ 'sanitize_callback' => 'absint' ],
                    'offset' => [ 'sanitize_callback' => 'absint' ],
                ],
            ]
        );

        \register_rest_route(
            'gae/v1',
            '/alt-bulk-update',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'restAltBulkUpdate' ],
                'permission_callback' => function() { return \current_user_can( 'upload_files' ); },
            ]
        );
    }

    public function restAltMissing( \WP_REST_Request $request ): \WP_REST_Response {
        $limit  = min( 200, max( 1, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );
        $offset = max( 0, (int) ( $request->get_param( 'offset' ) ?? 0 ) );

        $attachments = $this->getAttachmentsMissingAlt( $limit, $offset );

        return new \WP_REST_Response(
            [
                'attachments' => $attachments,
                'count'       => count( $attachments ),
            ],
            200
        );
    }

    public function restAltBulkUpdate( \WP_REST_Request $request ): \WP_REST_Response {
        $updates = $request->get_param( 'updates' );

        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return new \WP_REST_Response( [ 'error' => 'No updates provided.' ], 400 );
        }

        $count = $this->bulkUpdateAlt( $updates );

        return new \WP_REST_Response( [ 'updated' => $count ], 200 );
    }

    /**
     * Get attachments with missing or empty alt text.
     *
     * @return array[] [ ['id', 'filename', 'url', 'current_alt'] ]
     */
    public function getAttachmentsMissingAlt( int $limit = 50, int $offset = 0 ): array {
        $query = new \WP_Query( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_wp_attachment_image_alt',
                    'value'   => '',
                    'compare' => '=',
                ],
            ],
        ] );

        $results = [];
        foreach ( $query->posts as $post ) {
            $results[] = [
                'id'          => $post->ID,
                'filename'    => basename( \get_attached_file( $post->ID ) ?? '' ),
                'url'         => \wp_get_attachment_url( $post->ID ),
                'current_alt' => \get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
            ];
        }
        return $results;
    }

    /**
     * Bulk-update alt text for multiple attachments.
     *
     * @param array[] $updates [ ['id' => int, 'alt' => string] ]
     * @return int Number of updated attachments.
     */
    public function bulkUpdateAlt( array $updates ): int {
        /**
         * Fires before bulk alt text update begins.
         *
         * @param array $updates Array of { id: int, alt: string } pairs.
         */
        \do_action( 'gae_before_alt_bulk_update', $updates );

        $count = 0;
        foreach ( $updates as $item ) {
            $id  = (int) ( $item['id'] ?? 0 );
            $alt = isset( $item['alt'] ) ? \sanitize_text_field( $item['alt'] ) : '';

            if ( $id <= 0 ) {
                continue;
            }

            // Issue #28: verify caller can edit this specific attachment.
            if ( ! \current_user_can( 'edit_post', $id ) ) {
                continue;
            }

            /**
             * Filter the alt text before it is saved for an attachment.
             *
             * @param string $alt The sanitized alt text to be saved.
             * @param int    $id  The attachment ID.
             */
            $alt = \apply_filters( 'gae_alt_bulk_update_value', $alt, $id );

            \update_post_meta( $id, '_wp_attachment_image_alt', $alt );
            $count++;
        }

        /**
         * Fires after bulk alt text update completes.
         *
         * @param int $count Number of attachments updated.
         */
        \do_action( 'gae_after_alt_bulk_update', $count );

        return $count;
    }

    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-alt-bulk-editor',
            \plugins_url( 'assets/js/alt-bulk-editor.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch' ],
            '1.0.0',
            true
        );
        \wp_localize_script(
            'gae-alt-bulk-editor',
            'gaeAltEditor',
            [
                'restUrlMissing' => \rest_url( 'gae/v1/alt-missing' ),
                'restUrlUpdate'  => \rest_url( 'gae/v1/alt-bulk-update' ),
            ]
        );
    }
}
