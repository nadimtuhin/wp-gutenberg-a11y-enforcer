<?php
/**
 * Schema Validation Filter — Issue #5.
 *
 * Exposes a PHP filter `gae_block_schemas` that lets developers register
 * JSON-schema-like rule descriptors for any block (including third-party).
 * Those schemas are passed to the JS editor as `gaeSchemas` so the same
 * rules run client-side too.
 *
 * PHP filter signature:
 *   apply_filters( 'gae_block_schemas', array $schemas ): array
 *
 * Each schema entry:
 *   $schemas['my-plugin/my-block'] = [
 *       'required_attrs' => [ 'caption', 'level' ],
 *       'allowed_values' => [
 *           'align' => [ 'left', 'center', 'right', 'wide', 'full' ],
 *       ],
 *   ];
 *
 * On `content_save_pre`, blocks are validated against their schema;
 * violations are logged (not stripped) so content is preserved.
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class SchemaValidator {

    /**
     * Register hooks.
     */
    public function register(): void {
        \add_filter( 'content_save_pre', [ $this, 'validateSchemas' ], 20 );
        \add_action( 'enqueue_block_editor_assets', [ $this, 'enqueueEditorScript' ] );
    }

    /**
     * Return merged schemas (built-in + developer-registered via filter).
     *
     * @return array<string, array>
     */
    public function getSchemas(): array {
        $schemas = [];
        return (array) \apply_filters( 'gae_block_schemas', $schemas );
    }

    /**
     * Validate block attributes against registered schemas.
     * Violations are logged to PHP error_log; content passes through unchanged.
     *
     * @param string $content Post content.
     * @return string Unmodified content (schema violations are informational only).
     */
    public function validateSchemas( string $content ): string {
        if ( ! function_exists( 'parse_blocks' ) ) {
            return $content;
        }

        $schemas = $this->getSchemas();
        if ( empty( $schemas ) ) {
            return $content;
        }

        $blocks = \parse_blocks( $content );

        foreach ( $blocks as $block ) {
            $name   = $block['blockName'] ?? '';
            $schema = $schemas[ $name ] ?? null;

            if ( ! $schema ) {
                continue;
            }

            $violations = $this->checkBlock( $block, $schema );
            foreach ( $violations as $msg ) {
                // ponytail: log via error_log; upgrade to ValidationLog injection when needed.
                \do_action( 'gae_schema_violation', $name, $msg, $block );
                if ( \defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    \error_log( '[GAE Schema] ' . $msg );
                }
            }
        }

        return $content;
    }

    /**
     * Validate one block against its schema descriptor.
     *
     * @param array $block  Parsed block.
     * @param array $schema Schema descriptor.
     * @return string[]     Violation messages.
     */
    public function checkBlock( array $block, array $schema ): array {
        $attrs      = $block['attrs'] ?? [];
        $name       = $block['blockName'] ?? '(unknown)';
        $violations = [];

        // required_attrs
        foreach ( $schema['required_attrs'] ?? [] as $attr ) {
            if ( ! isset( $attrs[ $attr ] ) || '' === (string) $attrs[ $attr ] ) {
                $violations[] = sprintf(
                    '%s: required attribute "%s" is missing or empty (schema validation).',
                    $name,
                    $attr
                );
            }
        }

        // allowed_values
        foreach ( $schema['allowed_values'] ?? [] as $attr => $allowed ) {
            if ( ! isset( $attrs[ $attr ] ) ) {
                continue;
            }
            if ( ! in_array( $attrs[ $attr ], $allowed, true ) ) {
                $violations[] = sprintf(
                    '%s: attribute "%s" value "%s" is not in allowed set [%s] (schema validation).',
                    $name,
                    $attr,
                    $attrs[ $attr ],
                    implode( ', ', $allowed )
                );
            }
        }

        return $violations;
    }

    /**
     * Enqueue the schema-validation editor JS.
     */
    public function enqueueEditorScript(): void {
        $schemas = $this->getSchemas();

        \wp_enqueue_script(
            'gae-schema-validator',
            \plugins_url( 'assets/js/schema-validator.js', dirname( __FILE__ ) . '/wp-gutenberg-a11y-enforcer.php' ),
            [ 'wp-hooks', 'wp-blocks', 'wp-i18n' ],
            '1.3.0',
            true
        );
        \wp_localize_script( 'gae-schema-validator', 'gaeSchemas', $schemas );
    }
}
