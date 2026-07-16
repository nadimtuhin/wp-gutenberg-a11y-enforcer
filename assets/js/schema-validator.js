/**
 * GAE Schema Validator — Editor JS (Issue #5)
 *
 * Reads developer-registered schemas from `window.gaeSchemas` and fires
 * a `gae.schemaViolation` action for each violation found in a block's
 * attributes during the `blocks.getSaveElement` filter.
 *
 * Third-party code can hook into that action to display notices:
 *   wp.hooks.addAction('gae.schemaViolation', 'my-plugin', (blockName, msg) => {});
 *
 * Issue #5.
 */
/* global wp, gaeSchemas */
( function () {
    'use strict';

    const { addFilter, doAction } = wp.hooks;
    const schemas = ( typeof gaeSchemas !== 'undefined' ) ? gaeSchemas : {};

    /**
     * Check a block's attributes against its registered schema.
     *
     * @param {string} blockName
     * @param {Object} attributes
     * @returns {string[]} Violation messages.
     */
    function checkSchema( blockName, attributes ) {
        const schema = schemas[ blockName ];
        if ( ! schema ) return [];

        const violations = [];

        // required_attrs
        ( schema.required_attrs || [] ).forEach( ( attr ) => {
            if ( ! attributes[ attr ] && attributes[ attr ] !== 0 ) {
                violations.push(
                    blockName + ': required attribute "' + attr + '" is missing or empty (schema validation).'
                );
            }
        } );

        // allowed_values
        Object.entries( schema.allowed_values || {} ).forEach( ( [ attr, allowed ] ) => {
            if ( attributes[ attr ] === undefined ) return;
            if ( ! allowed.includes( attributes[ attr ] ) ) {
                violations.push(
                    blockName + ': attribute "' + attr + '" value "' + attributes[ attr ] +
                    '" is not in allowed set [' + allowed.join( ', ' ) + '] (schema validation).'
                );
            }
        } );

        return violations;
    }

    /**
     * Filter on getSaveElement — emit action per violation; element passes through.
     */
    function schemaValidateOnSave( element, blockType, attributes ) {
        const violations = checkSchema( blockType.name, attributes );
        violations.forEach( ( msg ) => {
            console.warn( '[GAE Schema]', msg );
            doAction( 'gae.schemaViolation', blockType.name, msg, attributes );
        } );
        return element;
    }

    addFilter(
        'blocks.getSaveElement',
        'wp-gutenberg-a11y-enforcer/schema-validator',
        schemaValidateOnSave
    );
} )();
