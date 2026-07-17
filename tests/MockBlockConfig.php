<?php
/**
 * MockBlockConfig — reusable test helper for block configuration mocks.
 *
 * Closes #10: CI unit test mocks.
 *
 * Usage:
 *   $mock = MockBlockConfig::withDefaults();
 *   $mock = MockBlockConfig::withRules(['core/image' => ['require_alt']]);
 *   $mock = MockBlockConfig::permissive();   // all rules empty — everything passes
 *   $mock = MockBlockConfig::block('core/image', ['require_alt', 'require_link_text']);
 *
 *   $enforcer->setConfig($mock->toArray());
 *
 * Block array helpers:
 *   MockBlockConfig::imageBlock(['alt' => 'Cat'])
 *   MockBlockConfig::buttonBlock('Buy now')
 *   MockBlockConfig::headingBlock('Hello', 2)
 *   MockBlockConfig::paragraphBlock('<p>Text</p>')
 */
class MockBlockConfig {

    /** @var array<string, string[]> */
    private array $rules;

    private function __construct( array $rules ) {
        $this->rules = $rules;
    }

    // ── Factory methods ──────────────────────────────────────────────────────

    /** Same rules as Settings::defaults(). */
    public static function withDefaults(): self {
        return new self( \GutenbergA11yEnforcer\Settings::defaults() );
    }

    /** Fully custom rule map. */
    public static function withRules( array $rules ): self {
        return new self( $rules );
    }

    /** Every block gets an empty rule list — all blocks pass validation. */
    public static function permissive(): self {
        return new self( [] );
    }

    /**
     * Single-block shorthand.
     *
     * @param string   $blockName  e.g. 'core/image'
     * @param string[] $rules      e.g. ['require_alt']
     */
    public static function block( string $blockName, array $rules = [] ): self {
        return new self( [ $blockName => $rules ] );
    }

    // ── Mutation helpers ─────────────────────────────────────────────────────

    /** Return a new instance with one block's rules added/overridden. */
    public function withBlock( string $blockName, array $rules ): self {
        $merged                  = $this->rules;
        $merged[ $blockName ]    = $rules;
        return new self( $merged );
    }

    /** Return a new instance with a block rule removed entirely. */
    public function withoutBlock( string $blockName ): self {
        $merged = $this->rules;
        unset( $merged[ $blockName ] );
        return new self( $merged );
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function toArray(): array {
        return $this->rules;
    }

    // ── Block array builders ─────────────────────────────────────────────────

    /**
     * Build a core/image block array.
     *
     * @param array $attrs Override attributes (e.g. ['alt' => 'Cat']).
     */
    public static function imageBlock( array $attrs = [], string $innerHTML = '' ): array {
        return [
            'blockName' => 'core/image',
            'attrs'     => $attrs,
            'innerHTML' => $innerHTML,
        ];
    }

    /**
     * Build a core/button block array.
     *
     * @param string $text  Button label (empty → validation failure).
     */
    public static function buttonBlock( string $text = '', string $innerHTML = '' ): array {
        $attrs = $text !== '' ? [ 'text' => $text ] : [];
        return [
            'blockName' => 'core/button',
            'attrs'     => $attrs,
            'innerHTML' => $innerHTML,
        ];
    }

    /**
     * Build a core/heading block array.
     *
     * @param string $content Heading text.
     * @param int    $level   Heading level (1–6).
     */
    public static function headingBlock( string $content = '', int $level = 2, string $innerHTML = '' ): array {
        return [
            'blockName' => 'core/heading',
            'attrs'     => [ 'content' => $content, 'level' => $level ],
            'innerHTML' => $innerHTML ?: ( $content !== '' ? "<h{$level}>{$content}</h{$level}>" : '' ),
        ];
    }

    /**
     * Build a core/paragraph block array.
     */
    public static function paragraphBlock( string $innerHTML = '' ): array {
        return [
            'blockName' => 'core/paragraph',
            'attrs'     => [],
            'innerHTML' => $innerHTML,
        ];
    }
}
