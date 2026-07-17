<?php
/**
 * VoiceOverSimulator — Issue #14.
 *
 * Extends ScreenReaderSimulator with audio-preview REST endpoint.
 * Returns SSML-like text that a TTS engine (or the browser Web Speech API)
 * can read aloud, giving editors an audio preview of how assistive tech
 * would present their content.
 *
 * REST endpoint:
 *   POST /wp-json/gae/v1/voiceover-preview
 *   Body: { "post_id": 123 }   OR   { "blocks": [...parsed blocks...] }
 *   → { "ssml": "<speak>...", "transcript": ["line1","line2",...] }
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class VoiceOverSimulator extends ScreenReaderSimulator {

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/voiceover-preview',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'restVoiceOverPreview' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function restVoiceOverPreview( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) ( $request->get_param( 'post_id' ) ?? 0 );
        $blocks  = $request->get_param( 'blocks' );

        if ( $post_id > 0 ) {
            $post = \get_post( $post_id );
            if ( ! $post ) {
                return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );
            }
            $blocks = \parse_blocks( $post->post_content );
        }

        if ( ! is_array( $blocks ) || empty( $blocks ) ) {
            return new \WP_REST_Response( [ 'error' => 'No blocks provided.' ], 400 );
        }

        $transcript = $this->transcript( $blocks );
        $ssml       = $this->toSsml( $transcript );

        /**
         * Filter the VoiceOver SSML output before it is returned.
         *
         * @param string   $ssml       The SSML markup string.
         * @param string[] $transcript The transcript lines.
         * @param array[]  $blocks     The parsed blocks.
         */
        $ssml = \apply_filters( 'gae_voiceover_ssml', $ssml, $transcript, $blocks );

        return new \WP_REST_Response(
            [
                'ssml'       => $ssml,
                'transcript' => $transcript,
            ],
            200
        );
    }

    /**
     * Convert transcript lines to SSML markup.
     *
     * Wraps structural elements in <p> tags and adds short pauses between
     * blocks so a TTS engine mimics real screen reader pacing.
     *
     * @param string[] $lines
     * @return string SSML <speak>…</speak>
     */
    public function toSsml( array $lines ): string {
        if ( empty( $lines ) ) {
            return '<speak></speak>';
        }

        $parts = [];
        foreach ( $lines as $line ) {
            // Escape XML special chars.
            $safe = htmlspecialchars( $line, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
            // Add a short break after each announcement.
            $parts[] = "<p>{$safe}</p><break time=\"300ms\"/>";
        }

        return '<speak>' . implode( "\n", $parts ) . '</speak>';
    }

    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-voiceover-simulator',
            \plugins_url( 'assets/js/voiceover-simulator.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch' ],
            '1.0.0',
            true
        );
        \wp_localize_script(
            'gae-voiceover-simulator',
            'gaeVoiceOver',
            [ 'restUrl' => \rest_url( 'gae/v1/voiceover-preview' ) ]
        );
    }
}
