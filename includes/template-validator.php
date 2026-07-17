<?php
/**
 * TemplateValidator — Issue #19: Block template validation on theme switch.
 *
 * Validates FSE block templates and block patterns for accessibility rules
 * when a theme is activated, logging violations to the a11y log.
 *
 * Hook: `switch_theme` — scans all registered block templates.
 * REST endpoint: GET /wp-json/gae/v1/validate-templates
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class TemplateValidator {

    /** @var Enforcer */
    private Enforcer $enforcer;

    /** @var ValidationLog|null */
    private ?ValidationLog $log;

    public function __construct( ?Enforcer $enforcer = null, ?ValidationLog $log = null ) {
        $this->enforcer = $enforcer ?? new Enforcer();
        $this->log      = $log;
    }

    public function register(): void {
        \add_action( 'switch_theme', [ $this, 'onThemeSwitch' ], 10, 3 );
        \add_action( 'rest_api_init', [ $this, 'registerRestRoute' ] );
    }

    public function registerRestRoute(): void {
        \register_rest_route(
            'gae/v1',
            '/validate-templates',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'restValidateTemplates' ],
                'permission_callback' => function() { return \current_user_can( 'manage_options' ); },
            ]
        );
    }

    /**
     * Fired when a theme is switched.
     *
     * @param string    $new_name   New theme name.
     * @param \WP_Theme $new_theme  New theme object.
     * @param \WP_Theme $old_theme  Old theme object.
     */
    public function onThemeSwitch( string $new_name, $new_theme, $old_theme ): void {
        $results = $this->validateAllTemplates();
        if ( ! empty( $results['violations'] ) && $this->log ) {
            foreach ( $results['violations'] as $v ) {
                $this->log->log( 0, $v['block'], $v['rule'] ?? 'template_check', $v['message'] );
            }
        }
    }

    public function restValidateTemplates( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->validateAllTemplates(), 200 );
    }

    /**
     * Validate all registered block templates.
     *
     * @return array { templates_checked: int, violations: array[] }
     */
    public function validateAllTemplates(): array {
        $templates = $this->getBlockTemplates();

        /**
         * Fires before block templates are validated.
         *
         * @param array[] $templates Array of { slug, content } template definitions.
         */
        \do_action( 'gae_before_validate_templates', $templates );

        $violations = [];
        $checked    = 0;

        foreach ( $templates as $template ) {
            $content = $template['content'] ?? '';
            if ( empty( $content ) || ! function_exists( 'parse_blocks' ) ) {
                continue;
            }
            $blocks = \parse_blocks( $content );
            $checked++;
            foreach ( $blocks as $block ) {
                if ( empty( $block['blockName'] ) ) {
                    continue;
                }
                $msgs = $this->enforcer->getViolations( $block );
                foreach ( $msgs as $msg ) {
                    $violations[] = [
                        'template' => $template['slug'] ?? 'unknown',
                        'block'    => $block['blockName'],
                        'message'  => $msg,
                    ];
                }
            }
        }

        $result = [
            'templates_checked' => $checked,
            'violations'        => $violations,
            'clean'             => empty( $violations ),
        ];

        /**
         * Filter the template validation results.
         *
         * @param array $result { templates_checked: int, violations: array[], clean: bool }
         */
        return \apply_filters( 'gae_template_validation_results', $result );
    }

    /**
     * Get registered block templates. Returns array of ['slug', 'content'].
     * Supports WP 5.9+ get_block_templates() when available.
     *
     * @return array[]
     */
    public function getBlockTemplates(): array {
        if ( ! function_exists( 'get_block_templates' ) ) {
            return [];
        }

        $wp_templates = \get_block_templates();
        if ( empty( $wp_templates ) ) {
            return [];
        }

        $templates = [];
        foreach ( $wp_templates as $tpl ) {
            $templates[] = [
                'slug'    => $tpl->slug ?? 'unknown',
                'content' => $tpl->content ?? '',
            ];
        }
        return $templates;
    }

    /**
     * Directly validate an array of template definitions (for testing).
     *
     * @param array[] $templates  [ ['slug' => '...', 'content' => '...'] ]
     * @return array
     */
    public function validateTemplates( array $templates ): array {
        $violations = [];
        $checked    = 0;

        foreach ( $templates as $template ) {
            $content = $template['content'] ?? '';
            if ( empty( $content ) || ! function_exists( 'parse_blocks' ) ) {
                continue;
            }
            $blocks = \parse_blocks( $content );
            $checked++;
            foreach ( $blocks as $block ) {
                if ( empty( $block['blockName'] ) ) {
                    continue;
                }
                $msgs = $this->enforcer->getViolations( $block );
                foreach ( $msgs as $msg ) {
                    $violations[] = [
                        'template' => $template['slug'] ?? 'unknown',
                        'block'    => $block['blockName'],
                        'message'  => $msg,
                    ];
                }
            }
        }

        return [
            'templates_checked' => $checked,
            'violations'        => $violations,
            'clean'             => empty( $violations ),
        ];
    }
}
