<?php
/**
 * WcagAaaProfile — Issue #21: WCAG AAA mode for documentation post type.
 *
 * When a post type is designated as "documentation" (e.g. 'docs', 'kb'),
 * enforcement automatically upgrades to WCAG AAA rules.
 *
 * Integrates with RuleProfile: registers a 'wcag_aaa' profile and wires
 * a filter that activates it for the configured post types.
 *
 * Configuration via option 'gae_wcag_aaa_post_types' (array of slugs).
 * Default: ['docs', 'documentation', 'kb'].
 *
 * Filter to override: add_filter( 'gae_aaa_post_types', fn($types) => [...] );
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class WcagAaaProfile {

    public const OPTION_KEY = 'gae_wcag_aaa_post_types';

    /** @var string[] Default post types that use WCAG AAA. */
    private static array $defaultPostTypes = [ 'docs', 'documentation', 'kb' ];

    public function register(): void {
        \add_filter( 'gae_rule_profile', [ $this, 'filterProfile' ], 20, 2 );
        \add_action( 'admin_init', [ $this, 'registerSettings' ] );
    }

    /**
     * Return the post types that require AAA mode.
     *
     * @return string[]
     */
    public function getAaaPostTypes(): array {
        $saved = function_exists( 'get_option' )
            ? \get_option( self::OPTION_KEY, [] )
            : [];

        $types = is_array( $saved ) && ! empty( $saved ) ? $saved : self::$defaultPostTypes;

        if ( function_exists( 'apply_filters' ) ) {
            $types = (array) \apply_filters( 'gae_aaa_post_types', $types );
        }

        return $types;
    }

    /**
     * gae_rule_profile filter: upgrade to 'wcag_aaa' for designated post types.
     *
     * @param string $profile   Current profile slug.
     * @param string $post_type Current post type.
     * @return string
     */
    public function filterProfile( string $profile, string $post_type ): string {
        if ( in_array( $post_type, $this->getAaaPostTypes(), true ) ) {
            return 'wcag_aaa';
        }
        return $profile;
    }

    /**
     * Whether a given post type is in AAA mode.
     */
    public function isAaaPostType( string $post_type ): bool {
        return in_array( $post_type, $this->getAaaPostTypes(), true );
    }

    public function registerSettings(): void {
        \register_setting(
            'gae_settings_group',
            self::OPTION_KEY,
            [ 'sanitize_callback' => [ $this, 'sanitizePostTypes' ] ]
        );
    }

    /**
     * Sanitize: accept comma-separated string or array of post type slugs.
     *
     * @param mixed $input
     * @return string[]
     */
    public function sanitizePostTypes( $input ): array {
        if ( is_string( $input ) ) {
            $input = explode( ',', $input );
        }
        if ( ! is_array( $input ) ) {
            return self::$defaultPostTypes;
        }
        return array_values(
            array_filter(
                array_map( 'sanitize_key', $input )
            )
        );
    }
}
