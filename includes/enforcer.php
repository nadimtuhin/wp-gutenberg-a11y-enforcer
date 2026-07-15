<?php
namespace GutenbergA11yEnforcer;

class Enforcer {

    /**
     * Validate a single parsed block array.
     * Returns false if the block fails accessibility checks.
     */
    public function validateBlock( array $block ): bool {
        if ( $block['blockName'] === 'core/image' && empty( $block['attrs']['alt'] ) ) {
            return false;
        }
        return true;
    }

    /**
     * Scan post content (serialised block HTML), strip invalid core/image blocks.
     * Hooked to `content_save_pre`.
     *
     * @param string $content Raw post content.
     * @return string Filtered content with non-compliant blocks removed.
     */
    public function filterContent( string $content ): string {
        if ( ! function_exists( 'parse_blocks' ) ) {
            return $content;
        }

        $blocks  = parse_blocks( $content );
        $kept    = [];

        foreach ( $blocks as $block ) {
            if ( $this->validateBlock( $block ) ) {
                $kept[] = $block;
            }
        }

        return $this->serializeBlocks( $kept );
    }

    /**
     * Serialise block array back to HTML string.
     * Uses WP core when available, falls back to manual serialisation.
     *
     * @param array $blocks
     * @return string
     */
    public function serializeBlocks( array $blocks ): string {
        if ( function_exists( 'serialize_blocks' ) ) {
            return serialize_blocks( $blocks );
        }

        // Minimal fallback: join inner HTML strings (covers unit-test context).
        $output = '';
        foreach ( $blocks as $block ) {
            $output .= isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
        }
        return $output;
    }

    /**
     * Register WordPress hooks.
     */
    public function register(): void {
        add_filter( 'content_save_pre', [ $this, 'filterContent' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    /**
     * Enqueue the Gutenberg editor JS that enforces a11y on the client side.
     */
    public function enqueueEditorScript(): void {
        $asset_file = plugin_dir_path( __DIR__ ) . 'assets/js/editor.asset.php';
        $deps       = [ 'wp-blocks', 'wp-hooks', 'wp-i18n' ];
        $version    = '1.0.0';

        if ( file_exists( $asset_file ) ) {
            $asset   = require $asset_file;
            $deps    = $asset['dependencies'];
            $version = $asset['version'];
        }

        wp_enqueue_script(
            'wp-gutenberg-a11y-enforcer-editor',
            plugins_url( 'assets/js/editor.js', __DIR__ . '/wp-gutenberg-a11y-enforcer.php' ),
            $deps,
            $version,
            true
        );
    }
}
