/**
 * WP Gutenberg A11y Enforcer — Editor Script
 *
 * Reads server-side config (window.gaeConfig.blockRules) and enforces
 * accessibility rules client-side via blocks.getSaveElement filter.
 *
 * Supported rules:
 *   require_alt             — core/image must have alt attribute
 *   require_link_text       — core/button must have non-empty text
 *   require_non_empty_text  — core/heading must have non-empty content
 */

/* global wp, gaeConfig */
( function () {
    'use strict';

    const { addFilter } = wp.hooks;
    const { createElement, Fragment } = wp.element;

    /**
     * Block rules from PHP (via wp_localize_script).
     * Falls back to defaults so the script works even without localisation.
     *
     * @type {Object.<string, string[]>}
     */
    const blockRules = ( typeof gaeConfig !== 'undefined' && gaeConfig.blockRules )
        ? gaeConfig.blockRules
        : {
            'core/image':   [ 'require_alt' ],
            'core/button':  [ 'require_link_text' ],
            'core/heading': [ 'require_non_empty_text' ],
        };

    /**
     * Return violation messages for a block.
     *
     * @param {string} blockName
     * @param {Object} attributes
     * @param {*}      element    React element (innerHTML proxy for text checks)
     * @returns {string[]}
     */
    function getA11yViolations( blockName, attributes, element ) {
        const rules      = blockRules[ blockName ] || [];
        const violations = [];

        rules.forEach( ( rule ) => {
            switch ( rule ) {
                case 'require_alt':
                    if ( ! attributes.alt ) {
                        violations.push(
                            'core/image: missing alt text — WCAG 2.1 SC 1.1.1.'
                        );
                    }
                    break;

                case 'require_link_text': {
                    const text = ( attributes.text || '' ).replace( /<[^>]*>/g, '' ).trim();
                    if ( ! text ) {
                        violations.push(
                            'core/button: missing link text — WCAG 2.4.6.'
                        );
                    }
                    break;
                }

                case 'require_non_empty_text': {
                    const content = ( attributes.content || '' ).replace( /<[^>]*>/g, '' ).trim();
                    if ( ! content ) {
                        violations.push(
                            'core/heading: heading must not be empty — WCAG 2.4.6.'
                        );
                    }
                    break;
                }
            }
        } );

        return violations;
    }

    /**
     * Filter: enforce a11y rules on save element.
     * Returns a sentinel element that differs from saved markup when
     * violations exist, causing Gutenberg to flag the block as invalid.
     */
    function enforceA11yOnSave( element, blockType, attributes ) {
        const violations = getA11yViolations( blockType.name, attributes, element );

        if ( violations.length === 0 ) {
            return element;
        }

        violations.forEach( ( msg ) => console.warn( '[A11y Enforcer]', msg ) );

        return createElement(
            Fragment,
            null,
            element,
            createElement(
                'div',
                {
                    'data-a11y-enforcer-violation': violations.join( ' | ' ),
                    style: { display: 'none' },
                },
                violations.join( ' ' )
            )
        );
    }

    addFilter(
        'blocks.getSaveElement',
        'wp-gutenberg-a11y-enforcer/enforce-a11y',
        enforceA11yOnSave
    );
} )();
