<?php
/**
 * Settings page for WP Gutenberg A11y Enforcer.
 * Allows admins to configure which blocks to validate and their rules.
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class Settings {

    const OPTION_KEY = 'gae_block_validation_config';

    /**
     * Post meta key: when this meta is set to '1' on a post, all block
     * validation is skipped for that save. Admins can set it via custom
     * fields or programmatically with update_post_meta().
     *
     * Issue #8.
     */
    const BYPASS_META_KEY = '_gae_bypass_validation';

    /**
     * Default block validation config.
     * Each key is a block name; value is array of enabled rule slugs.
     */
    public static function defaults(): array {
        return [
            'core/image'   => [ 'require_alt' ],
            'core/button'  => [ 'require_link_text' ],
            'core/heading' => [ 'require_non_empty_text' ],
        ];
    }

    /**
     * Get the current config, merged with defaults so new blocks always appear.
     */
    public static function getConfig(): array {
        $saved = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            return self::defaults();
        }
        // Merge: saved values win, but add any new defaults not yet in DB.
        return array_merge( self::defaults(), $saved );
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'addMenuPage' ] );
        add_action( 'admin_init', [ $this, 'registerSettings' ] );
    }

    public function addMenuPage(): void {
        add_options_page(
            __( 'A11y Enforcer Settings', 'wp-gutenberg-a11y-enforcer' ),
            __( 'A11y Enforcer', 'wp-gutenberg-a11y-enforcer' ),
            'manage_options',
            'gae-settings',
            [ $this, 'renderPage' ]
        );
    }

    public function registerSettings(): void {
        register_setting(
            'gae_settings_group',
            self::OPTION_KEY,
            [ $this, 'sanitizeConfig' ]
        );
    }

    /**
     * Sanitize submitted config — only allow known rule slugs, cast to arrays.
     */
    public function sanitizeConfig( $input ): array {
        $allowed_rules = [ 'require_alt', 'require_link_text', 'require_non_empty_text' ];
        $sanitized     = [];

        $all_blocks = array_keys( self::defaults() );
        foreach ( $all_blocks as $block ) {
            $sanitized[ $block ] = [];
            if ( isset( $input[ $block ] ) && is_array( $input[ $block ] ) ) {
                foreach ( $input[ $block ] as $rule ) {
                    $rule = sanitize_key( $rule );
                    if ( in_array( $rule, $allowed_rules, true ) ) {
                        $sanitized[ $block ][] = $rule;
                    }
                }
            }
        }

        return $sanitized;
    }

    public function renderPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $config = self::getConfig();
        $rules_labels = [
            'require_alt'              => __( 'Require alt text (images)', 'wp-gutenberg-a11y-enforcer' ),
            'require_link_text'        => __( 'Require link text (buttons)', 'wp-gutenberg-a11y-enforcer' ),
            'require_non_empty_text'   => __( 'Require non-empty text (headings)', 'wp-gutenberg-a11y-enforcer' ),
        ];

        // Map: which rules apply to which blocks.
        $block_rules = [
            'core/image'   => [ 'require_alt' ],
            'core/button'  => [ 'require_link_text' ],
            'core/heading' => [ 'require_non_empty_text' ],
        ];

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'A11y Enforcer — Block Validation Settings', 'wp-gutenberg-a11y-enforcer' ); ?></h1>
            <p><?php esc_html_e( 'Enable or disable accessibility validation rules per block type.', 'wp-gutenberg-a11y-enforcer' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'gae_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <?php foreach ( $block_rules as $block => $available_rules ) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html( $block ); ?></th>
                        <td>
                            <?php foreach ( $available_rules as $rule ) : ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr( self::OPTION_KEY . '[' . $block . '][]' ); ?>"
                                    value="<?php echo esc_attr( $rule ); ?>"
                                    <?php checked( in_array( $rule, $config[ $block ] ?? [], true ) ); ?>
                                />
                                <?php echo esc_html( $rules_labels[ $rule ] ?? $rule ); ?>
                            </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
