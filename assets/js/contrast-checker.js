/**
 * GAE Real-time Contrast Checker — Editor JS (Issue #6)
 *
 * Hooks into BlockEdit for core/paragraph, core/heading, and core/cover.
 * Reads textColor / backgroundColor / style.color from block attributes and
 * computes WCAG contrast ratio client-side (mirrors the PHP implementation).
 * Displays an accessible warning notice in InspectorControls when the ratio
 * falls below WCAG AA (4.5:1 normal text).
 *
 * The pure-JS contrast math means zero round-trips to the server during
 * typing. The REST endpoint (/gae/v1/contrast-ratio) remains available for
 * programmatic use.
 *
 * Issue #6.
 */
/* global wp, gaeContrast */
( function () {
    'use strict';

    const { addFilter }              = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment, useState, useEffect } = wp.element;
    const { InspectorControls }      = wp.blockEditor;
    const { PanelBody, Notice }      = wp.components;
    const { __ }                     = wp.i18n;

    const WCAG_AA = ( typeof gaeContrast !== 'undefined' ) ? gaeContrast.wcagAA : 4.5;

    // Blocks to monitor — extend as needed.
    const MONITORED_BLOCKS = new Set( [
        'core/paragraph',
        'core/heading',
        'core/cover',
        'core/button',
    ] );

    // ── Pure-JS WCAG math ──────────────────────────────────────────────

    function hexToRgb( hex ) {
        hex = hex.replace( /^#/, '' );
        if ( hex.length !== 6 ) return null;
        return [
            parseInt( hex.slice( 0, 2 ), 16 ),
            parseInt( hex.slice( 2, 4 ), 16 ),
            parseInt( hex.slice( 4, 6 ), 16 ),
        ];
    }

    function linearize( c ) {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
    }

    function luminance( [ r, g, b ] ) {
        return 0.2126 * linearize( r ) + 0.7152 * linearize( g ) + 0.0722 * linearize( b );
    }

    function contrastRatio( hex1, hex2 ) {
        const rgb1 = hexToRgb( hex1 );
        const rgb2 = hexToRgb( hex2 );
        if ( ! rgb1 || ! rgb2 ) return null;
        const l1 = luminance( rgb1 );
        const l2 = luminance( rgb2 );
        const lighter = Math.max( l1, l2 );
        const darker  = Math.min( l1, l2 );
        return ( lighter + 0.05 ) / ( darker + 0.05 );
    }

    // ── Gutenberg color-slug → hex resolution ─────────────────────────
    // Reads the editor's registered color palette (theme + core defaults).

    function resolveColor( slug ) {
        if ( ! slug ) return null;
        try {
            const settings = wp.data.select( 'core/block-editor' ).getSettings();
            const palette   = ( settings && settings.colors ) || [];
            const match     = palette.find( ( c ) => c.slug === slug );
            return match ? match.color : null;
        } catch ( e ) {
            return null;
        }
    }

    // ── Attribute → hex helpers ───────────────────────────────────────

    function getFgHex( attributes ) {
        // Inline style takes precedence.
        const inline = attributes.style && attributes.style.color && attributes.style.color.text;
        if ( inline ) return inline;
        // Slug-based (theme palette).
        return resolveColor( attributes.textColor );
    }

    function getBgHex( attributes ) {
        const inline = attributes.style && attributes.style.color && attributes.style.color.background;
        if ( inline ) return inline;
        return resolveColor( attributes.backgroundColor );
    }

    // ── HOC ───────────────────────────────────────────────────────────

    const withContrastWarning = createHigherOrderComponent( ( BlockEdit ) => {
        return function ( props ) {
            if ( ! MONITORED_BLOCKS.has( props.name ) ) {
                return wp.element.createElement( BlockEdit, props );
            }

            const [ ratio, setRatio ] = useState( null );

            useEffect( () => {
                const fg = getFgHex( props.attributes );
                const bg = getBgHex( props.attributes );
                if ( fg && bg ) {
                    setRatio( contrastRatio( fg, bg ) );
                } else {
                    setRatio( null );
                }
            }, [
                props.attributes.textColor,
                props.attributes.backgroundColor,
                props.attributes.style,
            ] );

            const hasFail = ratio !== null && ratio < WCAG_AA;
            const ratioStr = ratio !== null ? ratio.toFixed( 2 ) + ':1' : null;

            return wp.element.createElement(
                Fragment,
                null,
                wp.element.createElement( BlockEdit, props ),
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        {
                            title: __( 'Contrast (WCAG)', 'wp-gutenberg-a11y-enforcer' ),
                            initialOpen: hasFail,
                        },
                        ratio === null && wp.element.createElement(
                            'p',
                            { style: { marginTop: 0 } },
                            __( 'Set a text and background color to check contrast.', 'wp-gutenberg-a11y-enforcer' )
                        ),
                        ratio !== null && ! hasFail && wp.element.createElement(
                            Notice,
                            { status: 'success', isDismissible: false },
                            /* translators: %s: contrast ratio like "5.43:1" */
                            wp.i18n.sprintf(
                                __( 'Contrast ratio %s — passes WCAG AA.', 'wp-gutenberg-a11y-enforcer' ),
                                ratioStr
                            )
                        ),
                        hasFail && wp.element.createElement(
                            Notice,
                            { status: 'warning', isDismissible: false },
                            wp.i18n.sprintf(
                                /* translators: 1: contrast ratio, 2: required ratio */
                                __( 'Low contrast %1$s (need %2$s:1 for WCAG AA). Choose a higher-contrast combination.', 'wp-gutenberg-a11y-enforcer' ),
                                ratioStr,
                                WCAG_AA
                            )
                        )
                    )
                )
            );
        };
    }, 'withContrastWarning' );

    addFilter(
        'editor.BlockEdit',
        'wp-gutenberg-a11y-enforcer/contrast-checker',
        withContrastWarning
    );
} )();
