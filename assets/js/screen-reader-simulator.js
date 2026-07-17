/**
 * GAE Screen Reader Simulator — Issue #11
 *
 * Pure JS function that produces a text transcript of Gutenberg block
 * data as a screen reader would announce it.  Works in the editor
 * (access via `wp.data.select('core/block-editor').getBlocks()`) or
 * from any block array.
 *
 * Usage (browser console or unit test):
 *   const transcript = gaeScreenReaderTranscript( wp.data.select('core/block-editor').getBlocks() );
 *   console.log( transcript.join('\n') );
 */
( function ( global ) {
    'use strict';

    /**
     * Strip HTML tags from a string.
     * @param {string} html
     * @returns {string}
     */
    function innerText( html ) {
        if ( typeof document !== 'undefined' ) {
            const div       = document.createElement( 'div' );
            div.innerHTML   = html || '';
            return div.textContent || div.innerText || '';
        }
        // Node / test environment fallback.
        return ( html || '' ).replace( /<[^>]+>/g, '' );
    }

    /**
     * Extract <li> text nodes from an HTML list string.
     * @param {string} html
     * @returns {string[]}
     */
    function parseListItems( html ) {
        const matches = ( html || '' ).match( /<li[^>]*>([\s\S]*?)<\/li>/gi ) || [];
        return matches
            .map( ( m ) => innerText( m.replace( /<\/?li[^>]*>/gi, '' ) ).trim() )
            .filter( Boolean );
    }

    /**
     * Announce a single block as a screen reader would.
     *
     * Gutenberg block objects (from getBlocks()) have:
     *   { name, attributes, innerBlocks, originalContent }
     *
     * Raw parsed block arrays (from PHP parse_blocks() shape passed as JS):
     *   { blockName, attrs, innerHTML }
     *
     * This function accepts either shape.
     *
     * @param {Object} block
     * @returns {string|null}  null for decorative/invisible blocks.
     */
    function announceBlock( block ) {
        // Normalise both shapes.
        const name   = block.name       || block.blockName || 'unknown';
        const attrs  = block.attributes || block.attrs     || {};
        const html   = block.originalContent || block.innerHTML || '';
        const text   = innerText( html ).trim();

        switch ( name ) {
            case 'core/image': {
                const alt = ( attrs.alt || '' ).trim();
                if ( ! alt ) {
                    return '[Image: (no alternative text — a11y violation)]';
                }
                const caption = ( attrs.caption || '' ).trim();
                return '[Image: ' + alt + ']' + ( caption ? ' [Caption: ' + caption + ']' : '' );
            }

            case 'core/heading': {
                const level   = attrs.level || 2;
                const content = ( attrs.content || text ).trim();
                if ( ! content ) {
                    return '[Heading level ' + level + ': (empty — a11y violation)]';
                }
                return '[Heading level ' + level + ': ' + content + ']';
            }

            case 'core/paragraph': {
                const content = ( attrs.content || text ).trim();
                return content ? '[Paragraph: ' + content + ']' : null;
            }

            case 'core/button': {
                const label = ( attrs.text || text ).trim();
                return label
                    ? '[Button: ' + label + ']'
                    : '[Button: (no label — a11y violation)]';
            }

            case 'core/list': {
                const items = parseListItems( html );
                if ( ! items.length ) return null;
                const count = items.length;
                let out = '[List: ' + count + ' item' + ( count !== 1 ? 's' : '' ) + ']';
                items.forEach( function ( item, i ) {
                    out += '\n  ' + ( i + 1 ) + '. ' + item;
                } );
                return out;
            }

            case 'core/separator':
                return '[Separator]';

            case 'core/spacer':
                return null;

            default: {
                const slug = name.replace( '/', ' ' );
                return text ? '[' + slug + ': ' + text + ']' : '[' + slug + ']';
            }
        }
    }

    /**
     * Generate a full screen-reader transcript for an array of blocks.
     *
     * @param {Object[]} blocks
     * @returns {string[]}  One announcement per block (nulls filtered out).
     */
    function gaeScreenReaderTranscript( blocks ) {
        if ( ! Array.isArray( blocks ) ) return [];
        return blocks
            .map( announceBlock )
            .filter( function ( line ) { return line !== null; } );
    }

    // Export.
    if ( typeof module !== 'undefined' && module.exports ) {
        module.exports = { gaeScreenReaderTranscript, announceBlock };
    } else {
        global.gaeScreenReaderTranscript = gaeScreenReaderTranscript;
        global.gaeAnnounceBlock          = announceBlock;
    }

} )( typeof globalThis !== 'undefined' ? globalThis : this );
