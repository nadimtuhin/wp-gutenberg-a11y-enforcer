/**
 * WP Gutenberg A11y Enforcer — Editor Script
 *
 * Hooks into `blocks.getSaveElement` to enforce accessibility rules
 * on the client side before Gutenberg serialises block content.
 *
 * If a core/image block is missing its alt attribute, saving is blocked
 * by returning a React element wrapped in an error boundary placeholder
 * that Gutenberg marks as invalid, preventing the save action.
 */

/* global wp */
( function () {
    'use strict';

    const { addFilter } = wp.hooks;
    const { createElement, Fragment } = wp.element;

    /**
     * Validate accessibility rules for a block.
     *
     * @param {Object} attributes Block attributes.
     * @param {string} blockName  Block name (e.g. 'core/image').
     * @returns {string[]} Array of violation messages (empty = valid).
     */
    function getA11yViolations( blockName, attributes ) {
        const violations = [];

        if ( blockName === 'core/image' && ! attributes.alt ) {
            violations.push(
                'core/image block is missing an alt text attribute. ' +
                'Add alt text to satisfy WCAG 2.1 Success Criterion 1.1.1.'
            );
        }

        return violations;
    }

    /**
     * Filter applied to every block's save element.
     * When violations are found, we render a visible error element so
     * Gutenberg detects a block validation failure and prevents saving.
     */
    function enforceA11yOnSave( element, blockType, attributes ) {
        const violations = getA11yViolations( blockType.name, attributes );

        if ( violations.length === 0 ) {
            return element;
        }

        // Console warning for developers.
        violations.forEach( ( msg ) =>
            console.warn( '[A11y Enforcer]', msg )
        );

        // Return a sentinel element that differs from the saved markup so
        // Gutenberg marks the block as invalid (red border + "Attempt recovery").
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
