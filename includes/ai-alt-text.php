<?php
/**
 * AI Alt-Text Suggestion API — REST endpoint + editor integration.
 *
 * Registers: GET /wp-json/gae/v1/suggest-alt?attachment_id=<N>
 *
 * Returns a suggested alt string via the `gae_suggest_alt_text` filter
 * so third-party code (OpenAI, Azure Vision, etc.) can plug in.
 * Default implementation uses the WP attachment caption / title as fallback.
 *
 * Issue #4.
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class AiAltText {

    /**
     * Register REST route and editor asset.
     */
    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    /**
     * Register /gae/v1/suggest-alt REST endpoint.
     */
    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/suggest-alt',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'suggestAlt' ],
                'permission_callback' => fn() => \current_user_can( 'upload_files' ),
                'args'                => [
                    'attachment_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => fn( $v ) => $v > 0,
                    ],
                ],
            ]
        );
    }

    /**
     * REST callback: return suggested alt text for an attachment.
     *
     * Fires the `gae_suggest_alt_text` filter — first arg is the draft alt
     * string (starts as WP caption or title), second is the attachment post
     * object. Any plugin/theme can override it with an AI-generated string.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function suggestAlt( \WP_REST_Request $request ): \WP_REST_Response {
        $id   = (int) $request->get_param( 'attachment_id' );
        $post = \get_post( $id );

        if ( ! $post || $post->post_type !== 'attachment' ) {
            return new \WP_REST_Response(
                [ 'error' => 'Attachment not found.' ],
                404
            );
        }

        // Start with caption, fall back to title.
        $alt = \wp_get_attachment_caption( $id );
        if ( '' === trim( (string) $alt ) ) {
            $alt = $post->post_title ?? '';
        }

        /**
         * Fires before the AI alt text suggestion is generated.
         *
         * @param int      $id   Attachment ID.
         * @param \WP_Post $post Attachment post object.
         */
        \do_action( 'gae_before_suggest_alt', $id, $post );

        /**
         * Filter: allow third parties to inject an AI-generated alt string.
         *
         * @param string   $alt  Draft alt text (caption or title).
         * @param \WP_Post $post Attachment post object.
         */
        $alt = (string) \apply_filters( 'gae_suggest_alt_text', $alt, $post );

        /**
         * Filter the final alt text suggestion for an attachment.
         *
         * @param string $alt Draft alt text after gae_suggest_alt_text filter.
         * @param int    $id  Attachment ID.
         */
        $alt = (string) \apply_filters( 'gae_alt_text_suggestion', $alt, $id );
        // Issue #29: strip tags before sanitize to block XSS from AI/exec output.
        $alt = \sanitize_text_field( wp_strip_all_tags( $alt ) );

        /**
         * Fires after the alt text suggestion has been generated.
         *
         * @param string $alt The final suggested alt text.
         * @param int    $id  Attachment ID.
         */
        \do_action( 'gae_after_suggest_alt', $alt, $id );

        return new \WP_REST_Response( [ 'alt' => $alt ], 200 );
    }

    /**
     * Enqueue the AI alt-text editor sidebar JS.
     */
    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-ai-alt-text',
            \plugins_url( 'assets/js/ai-alt-text.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-block-editor' ],
            '1.3.0',
            true
        );
        \wp_localize_script(
            'gae-ai-alt-text',
            'gaeAiAltText',
            [ 'restUrl' => \rest_url( 'gae/v1/suggest-alt' ), 'nonce' => \wp_create_nonce( 'wp_rest' ) ]
        );
    }
}
