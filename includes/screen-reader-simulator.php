<?php
/**
 * ScreenReaderSimulator — text-based screen reader transcript generator.
 *
 * Closes #11: Screen reader simulator JS/PHP.
 *
 * Produces a plain-text read-out of Gutenberg blocks that mimics how a
 * screen reader would announce each block to a user.  Useful for a11y
 * auditing and automated testing.
 *
 * Usage:
 *   $sim      = new ScreenReaderSimulator();
 *   $blocks   = parse_blocks( $post_content );
 *   $transcript = $sim->transcript( $blocks );
 *   // Returns an array of announcement strings, one per block.
 *
 *   $text = implode( "\n", $transcript );  // flat read-out
 */
namespace GutenbergA11yEnforcer;

if ( defined( 'ABSPATH' ) === false && ! defined( 'PHPUNIT_RUNNING' ) ) {
    exit;
}

class ScreenReaderSimulator {

    /**
     * Generate a screen-reader-style transcript for an array of parsed blocks.
     *
     * @param array[] $blocks  Output of parse_blocks().
     * @return string[]        One announcement string per block.
     */
    public function transcript( array $blocks ): array {
        $lines = [];

        foreach ( $blocks as $block ) {
            $line = $this->announceBlock( $block );
            if ( $line !== null ) {
                $lines[] = $line;
            }
        }

        /**
         * Filter the complete screen reader transcript lines.
         *
         * @param string[] $lines  Announcement strings, one per block.
         * @param array[]  $blocks The original parsed blocks array.
         */
        return \apply_filters( 'gae_screen_reader_transcript', $lines, $blocks );
    }

    /**
     * Produce a single screen-reader announcement for one block.
     *
     * @param array $block Parsed block array.
     * @return string|null  Null means block is invisible to screen readers.
     */
    public function announceBlock( array $block ): ?string {
        $name      = $block['blockName'] ?? 'unknown';
        $attrs     = $block['attrs']     ?? [];
        $innerHtml = $block['innerHTML'] ?? '';
        $innerText = $this->innerText( $innerHtml );

        switch ( $name ) {
            case 'core/image':
                $alt = trim( $attrs['alt'] ?? '' );
                if ( $alt === '' ) {
                    return '[Image: (no alternative text — a11y violation)]';
                }
                $caption = trim( $attrs['caption'] ?? '' );
                $out     = "[Image: {$alt}]";
                if ( $caption !== '' ) {
                    $out .= " [Caption: {$caption}]";
                }
                return $out;

            case 'core/heading':
                $level   = (int) ( $attrs['level'] ?? 2 );
                $content = trim( $attrs['content'] ?? $innerText );
                if ( $content === '' ) {
                    return "[Heading level {$level}: (empty — a11y violation)]";
                }
                return "[Heading level {$level}: {$content}]";

            case 'core/paragraph':
                $text = trim( $attrs['content'] ?? $innerText );
                if ( $text === '' ) {
                    return null; // empty paragraphs are decorative
                }
                return "[Paragraph: {$text}]";

            case 'core/button':
                $label = trim( $attrs['text'] ?? $innerText );
                if ( $label === '' ) {
                    return '[Button: (no label — a11y violation)]';
                }
                return "[Button: {$label}]";

            case 'core/list':
                $items = $this->parseListItems( $innerHtml );
                if ( empty( $items ) ) {
                    return null;
                }
                $count = count( $items );
                $out   = "[List: {$count} item" . ( $count !== 1 ? 's' : '' ) . "]";
                foreach ( $items as $i => $item ) {
                    $out .= "\n  " . ( $i + 1 ) . ". {$item}";
                }
                return $out;

            case 'core/separator':
                return '[Separator]';

            case 'core/spacer':
                return null; // purely decorative

            default:
                // Generic fallback: announce block name + any readable inner text.
                $text = trim( $innerText );
                $slug = str_replace( '/', ' ', $name );
                if ( $text !== '' ) {
                    return "[{$slug}: {$text}]";
                }
                return "[{$slug}]";
        }
    }

    /**
     * Strip HTML tags to extract plain inner text.
     */
    public function innerText( string $html ): string {
        if ( function_exists( 'wp_strip_all_tags' ) ) {
            return wp_strip_all_tags( $html );
        }
        return strip_tags( $html );
    }

    /**
     * Extract list item texts from an HTML list.
     *
     * @return string[]
     */
    private function parseListItems( string $html ): array {
        $items = [];
        preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $html, $matches );
        foreach ( $matches[1] as $item ) {
            $text = trim( strip_tags( $item ) );
            if ( $text !== '' ) {
                $items[] = $text;
            }
        }
        return $items;
    }
}
