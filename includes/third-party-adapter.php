<?php
/**
 * ThirdPartyAdapter — Issue #22: Third-party block adapter API.
 *
 * Allows third-party plugins and themes to register custom block validators
 * without forking or patching the core enforcer.
 *
 * Usage:
 *   // Register a validator for 'acf/my-block':
 *   GutenbergA11yEnforcer\ThirdPartyAdapter::registerBlockValidator(
 *       'acf/my-block',
 *       function( array $block ): array {
 *           $violations = [];
 *           if ( empty( $block['attrs']['field_label'] ) ) {
 *               $violations[] = 'acf/my-block: field_label is required.';
 *           }
 *           return $violations;
 *       }
 *   );
 *
 * Or via filter:
 *   add_filter( 'gae_block_validators', function( $validators ) {
 *       $validators['acf/my-block'] = fn($b) => [];
 *       return $validators;
 *   });
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class ThirdPartyAdapter {

    /** @var array<string, callable[]> blockName → [ callable ] */
    private static array $validators = [];

    /**
     * Register a validator callable for a block type.
     *
     * The callable receives the parsed block array and must return an array of
     * violation strings (empty = passes).
     *
     * Multiple validators can be registered for the same block — all are run.
     *
     * @param string   $block_name  e.g. 'acf/hero'
     * @param callable $validator   fn(array $block): string[]
     */
    public static function registerBlockValidator( string $block_name, callable $validator ): void {
        self::$validators[ $block_name ][] = $validator;
    }

    /**
     * Remove all validators for a block type.
     */
    public static function deregisterBlockValidators( string $block_name ): void {
        unset( self::$validators[ $block_name ] );
    }

    /**
     * Run all registered third-party validators for a block.
     *
     * @param array $block Parsed block array.
     * @return string[]   Violations from all validators.
     */
    public function getViolations( array $block ): array {
        $block_name = $block['blockName'] ?? '';
        $violations = [];

        // Static registry.
        $callables = self::$validators[ $block_name ] ?? [];

        // Filter-based registry.
        $filter_validators = [];
        if ( function_exists( 'apply_filters' ) ) {
            $filter_validators = (array) \apply_filters( 'gae_block_validators', [] );
        }
        if ( isset( $filter_validators[ $block_name ] ) && is_callable( $filter_validators[ $block_name ] ) ) {
            $callables[] = $filter_validators[ $block_name ];
        }

        foreach ( $callables as $fn ) {
            $result = call_user_func( $fn, $block );
            if ( is_array( $result ) ) {
                $violations = array_merge( $violations, $result );
            }
        }

        return $violations;
    }

    /**
     * Whether any validators are registered for a block type.
     */
    public static function hasValidatorsFor( string $block_name ): bool {
        return ! empty( self::$validators[ $block_name ] );
    }

    /**
     * List all block types with registered validators.
     *
     * @return string[]
     */
    public static function registeredBlockTypes(): array {
        return array_keys( self::$validators );
    }

    /**
     * Clear all registered validators (useful for test isolation).
     */
    public static function reset(): void {
        self::$validators = [];
    }

    public function register(): void {
        // Hook into enforcer's violation pipeline via filter.
        \add_filter( 'gae_block_violations', [ $this, 'appendViolations' ], 10, 2 );
    }

    /**
     * gae_block_violations filter: append third-party violations.
     *
     * @param string[] $violations Existing violations.
     * @param array    $block      Parsed block.
     * @return string[]
     */
    public function appendViolations( array $violations, array $block ): array {
        return array_merge( $violations, $this->getViolations( $block ) );
    }
}
