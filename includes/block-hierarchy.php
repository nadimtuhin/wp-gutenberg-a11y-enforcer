<?php
/**
 * Block Hierarchy Navigator — Issue #13.
 *
 * Provides a REST endpoint that returns the nested block hierarchy tree for
 * a given post, enabling a sidebar navigation map in the editor.
 *
 * REST endpoint:
 *   GET /wp-json/gae/v1/block-hierarchy?post_id=123
 *   → { "tree": [ { "name": "core/heading", "level": 2, "children": [] }, … ] }
 *
 * JS sidebar panel: assets/js/block-hierarchy.js
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class BlockHierarchy {

    public function register(): void {
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/block-hierarchy',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restBlockHierarchy' ],
                'permission_callback' => [ $this, 'canEdit' ],
                'args'                => [
                    'post_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function canEdit( \WP_REST_Request $request ): bool {
        $post_id = (int) $request->get_param( 'post_id' );
        return $post_id > 0 && \current_user_can( 'edit_post', $post_id );
    }

    public function restBlockHierarchy( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = (int) $request->get_param( 'post_id' );
        $post    = \get_post( $post_id );

        if ( ! $post ) {
            return new \WP_REST_Response( [ 'error' => 'Post not found.' ], 404 );
        }

        $blocks = \parse_blocks( $post->post_content );
        $tree   = $this->buildTree( $blocks );

        /**
         * Filter the block hierarchy tree before it is returned.
         *
         * @param array[] $tree    The nested block hierarchy tree.
         * @param int     $post_id The post ID.
         */
        $tree = \apply_filters( 'gae_block_hierarchy_tree', $tree, $post_id );

        return new \WP_REST_Response( [ 'tree' => $tree ], 200 );
    }

    /**
     * Build a flat-to-nested tree from parsed blocks.
     * Top-level blocks with innerBlocks become parents.
     *
     * @param array[] $blocks
     * @return array[]
     */
    public function buildTree( array $blocks ): array {
        $tree = [];
        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }
            $node = [
                'name'     => $block['blockName'],
                'attrs'    => $block['attrs'] ?? [],
                'children' => [],
            ];
            if ( ! empty( $block['innerBlocks'] ) ) {
                $node['children'] = $this->buildTree( $block['innerBlocks'] );
            }
            $tree[] = $node;
        }
        return $tree;
    }

    /**
     * Return a flat list of block names (depth-first) for scoring/display.
     *
     * @param array[] $blocks
     * @return string[]
     */
    public function flatBlockNames( array $blocks ): array {
        $names = [];
        foreach ( $blocks as $block ) {
            if ( ! empty( $block['blockName'] ) ) {
                $names[] = $block['blockName'];
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $names = array_merge( $names, $this->flatBlockNames( $block['innerBlocks'] ) );
            }
        }
        return $names;
    }

    public function enqueueEditorScript(): void {
        \wp_enqueue_script(
            'gae-block-hierarchy',
            \plugins_url( 'assets/js/block-hierarchy.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-blocks', 'wp-element', 'wp-components', 'wp-data', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch' ],
            '1.0.0',
            true
        );
        \wp_localize_script(
            'gae-block-hierarchy',
            'gaeBlockHierarchy',
            [ 'restUrl' => \rest_url( 'gae/v1/block-hierarchy' ) ]
        );
    }
}
