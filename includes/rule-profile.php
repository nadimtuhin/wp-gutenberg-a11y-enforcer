<?php
/**
 * RuleProfile — Issue #15: Dynamic rule profiles based on post type.
 *
 * Allows different WCAG validation rule sets per post type.
 * E.g. stricter rules for 'page' (public-facing) vs. lighter for 'draft'.
 *
 * Usage via filter:
 *   add_filter( 'gae_rule_profile', function( $profile, $post_type ) {
 *       if ( $post_type === 'page' ) return 'strict';
 *       return $profile;
 *   }, 10, 2 );
 *
 * Built-in profiles: 'default', 'strict', 'minimal', 'wcag_aaa'
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class RuleProfile {

    /** @var array<string, array> Profile slug → block rule map. */
    private static array $profiles = [];

    public function register(): void {
        \add_filter( 'gae_block_config', [ $this, 'applyProfileFilter' ], 10, 2 );
    }

    /**
     * Built-in profiles.
     *
     * @return array<string, array>
     */
    public static function builtinProfiles(): array {
        return [
            'default' => Settings::defaults(),

            'strict' => [
                'core/image'   => [ 'require_alt', 'require_caption' ],
                'core/button'  => [ 'require_link_text', 'require_descriptive_text' ],
                'core/heading' => [ 'require_non_empty_text', 'require_hierarchy' ],
                'core/table'   => [ 'require_caption', 'require_scope' ],
                'core/video'   => [ 'require_caption_track' ],
            ],

            'minimal' => [
                'core/image' => [ 'require_alt' ],
            ],

            'wcag_aaa' => [
                'core/image'   => [ 'require_alt', 'require_caption', 'require_long_desc' ],
                'core/button'  => [ 'require_link_text', 'require_descriptive_text' ],
                'core/heading' => [ 'require_non_empty_text', 'require_hierarchy' ],
                'core/table'   => [ 'require_caption', 'require_scope', 'require_summary' ],
                'core/video'   => [ 'require_caption_track', 'require_audio_desc' ],
                'core/audio'   => [ 'require_transcript' ],
                'core/link'    => [ 'require_descriptive_text' ],
            ],
        ];
    }

    /**
     * Get config for a given post type.
     *
     * @param string $post_type
     * @return array Block rule map.
     */
    public function getConfigForPostType( string $post_type ): array {
        $profile_slug = $this->resolveProfile( $post_type );
        return $this->getProfile( $profile_slug );
    }

    /**
     * Resolve the profile slug for a post type via filter.
     */
    public function resolveProfile( string $post_type ): string {
        $default = 'default';
        if ( function_exists( 'apply_filters' ) ) {
            return (string) \apply_filters( 'gae_rule_profile', $default, $post_type );
        }
        return $default;
    }

    /**
     * Get a profile by slug. Falls back to 'default' for unknown slugs.
     */
    public function getProfile( string $slug ): array {
        $profiles = array_merge( self::builtinProfiles(), self::$profiles );
        return $profiles[ $slug ] ?? $profiles['default'];
    }

    /**
     * Register a custom profile.
     *
     * @param string $slug  Unique profile slug.
     * @param array  $rules Block rule map.
     */
    public static function registerProfile( string $slug, array $rules ): void {
        self::$profiles[ $slug ] = $rules;
    }

    /**
     * Filter callback: swap in the post-type profile when Enforcer fetches config.
     *
     * @param array  $config    Current block config.
     * @param string $post_type Current post type.
     * @return array
     */
    public function applyProfileFilter( array $config, string $post_type ): array {
        return $this->getConfigForPostType( $post_type );
    }

    /**
     * List all registered profile slugs.
     *
     * @return string[]
     */
    public function availableProfiles(): array {
        return array_keys( array_merge( self::builtinProfiles(), self::$profiles ) );
    }
}
