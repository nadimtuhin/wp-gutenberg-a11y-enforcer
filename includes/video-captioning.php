<?php
/**
 * VideoCaptioning — Issue #24: Video block caption enforcement.
 *
 * Validates core/video blocks for the presence of a captions track,
 * and warns for core/audio blocks lacking transcripts.
 *
 * Integrates with Enforcer via the 'require_caption_track' rule slug.
 *
 * REST endpoint (for editor toolbar):
 *   GET /wp-json/gae/v1/video-caption-check?post_id=123
 *   → { "violations": [...], "clean": true/false }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class VideoCaptioning {

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
        // Extend core enforcer rules.
        \add_filter( 'gae_block_violations', [ $this, 'checkVideoBlock' ], 10, 2 );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/video-caption-check',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restVideoCaptionCheck' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'post_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                ],
            ]
        );
    }

    public function restVideoCaptionCheck( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) $request->get_param( 'post_id' );
        $post    = \get_post( $post_id );

        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );
        }

        $blocks     = \parse_blocks( $post->post_content );
        $violations = [];

        foreach ( $blocks as $block ) {
            $msgs = $this->checkVideoBlock( [], $block );
            $violations = array_merge( $violations, $msgs );
        }

        return new \WP_REST_Response(
            [
                'violations' => $violations,
                'clean'      => empty( $violations ),
            ],
            200
        );
    }

    /**
     * gae_block_violations filter: add video/audio caption checks.
     *
     * @param string[] $violations Existing violations.
     * @param array    $block      Parsed block.
     * @return string[]
     */
    public function checkVideoBlock( array $violations, array $block ): array {
        $name  = $block['blockName'] ?? '';
        $attrs = $block['attrs'] ?? [];

        switch ( $name ) {
            case 'core/video':
                if ( ! $this->hasTracksInHtml( $block['innerHTML'] ?? '' ) &&
                     empty( $attrs['tracks'] ) ) {
                    /**
                     * Filter the violation message for a video block missing captions.
                     *
                     * @param string $msg   Default violation message.
                     * @param array  $block The parsed video block.
                     */
                    $violations[] = \apply_filters(
                        'gae_video_caption_violation_message',
                        'core/video: missing captions track (WCAG 1.2.2).',
                        $block
                    );
                }
                break;

            case 'core/audio':
                if ( empty( $attrs['transcript'] ) &&
                     ! $this->hasTranscriptLink( $block['innerHTML'] ?? '' ) ) {
                    /**
                     * Filter the violation message for an audio block missing a transcript.
                     *
                     * @param string $msg   Default violation message.
                     * @param array  $block The parsed audio block.
                     */
                    $violations[] = \apply_filters(
                        'gae_audio_transcript_violation_message',
                        'core/audio: missing transcript or description (WCAG 1.2.1).',
                        $block
                    );
                }
                break;
        }

        /**
         * Fires after video/audio caption checks complete for a block.
         *
         * @param array    $violations Current violation list.
         * @param array    $block      The parsed block.
         */
        \do_action( 'gae_after_video_caption_check', $violations, $block );

        return $violations;
    }

    /**
     * Check if the block HTML contains a <track> element (VTT captions).
     */
    public function hasTracksInHtml( string $html ): bool {
        return (bool) preg_match( '/<track[^>]+kind=["\']?captions/i', $html );
    }

    /**
     * Check if block HTML contains an explicit transcript link or element.
     */
    public function hasTranscriptLink( string $html ): bool {
        return (bool) preg_match( '/transcript|aria-describedby/i', $html );
    }

    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-video-captioning',
            \plugins_url( 'assets/js/video-captioning.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n' ],
            '1.0.0',
            true
        );
    }
}
