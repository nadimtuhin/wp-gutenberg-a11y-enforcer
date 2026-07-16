/**
 * GAE AI Alt-Text Suggestion — Editor JS
 *
 * Adds an "AI Suggest Alt" button to the core/image block sidebar.
 * Calls /wp-json/gae/v1/suggest-alt?attachment_id=<N> and injects
 * the returned string into the block's alt attribute.
 *
 * Issue #4.
 */
/* global wp, gaeAiAltText */
( function () {
    'use strict';

    const { addFilter }             = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment }              = wp.element;
    const { InspectorControls }     = wp.blockEditor;
    const { PanelBody, Button, TextControl, Spinner } = wp.components;
    const { useState }              = wp.element;
    const { __ }                    = wp.i18n;
    const apiFetch                  = wp.apiFetch;

    /**
     * HOC: add AI Alt-Text panel to core/image InspectorControls.
     */
    const withAiAltPanel = createHigherOrderComponent( ( BlockEdit ) => {
        return function ( props ) {
            if ( props.name !== 'core/image' ) {
                return wp.element.createElement( BlockEdit, props );
            }

            const [ loading, setLoading ] = useState( false );
            const [ suggestion, setSuggestion ] = useState( '' );
            const [ error, setError ] = useState( '' );

            const attachmentId = props.attributes.id;

            function fetchSuggestion() {
                if ( ! attachmentId ) {
                    setError( __( 'No attachment selected.', 'wp-gutenberg-a11y-enforcer' ) );
                    return;
                }
                setLoading( true );
                setError( '' );
                setSuggestion( '' );

                apiFetch( {
                    url: gaeAiAltText.restUrl + '?attachment_id=' + attachmentId,
                    headers: { 'X-WP-Nonce': gaeAiAltText.nonce },
                } )
                    .then( ( data ) => {
                        setSuggestion( data.alt || '' );
                        setLoading( false );
                    } )
                    .catch( () => {
                        setError( __( 'Could not fetch suggestion.', 'wp-gutenberg-a11y-enforcer' ) );
                        setLoading( false );
                    } );
            }

            function applySuggestion() {
                props.setAttributes( { alt: suggestion } );
                setSuggestion( '' );
            }

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
                            title: __( 'AI Alt Text', 'wp-gutenberg-a11y-enforcer' ),
                            initialOpen: false,
                        },
                        wp.element.createElement(
                            Button,
                            {
                                variant: 'secondary',
                                onClick: fetchSuggestion,
                                disabled: loading || ! attachmentId,
                            },
                            loading
                                ? wp.element.createElement( Spinner )
                                : __( 'Suggest Alt Text', 'wp-gutenberg-a11y-enforcer' )
                        ),
                        error && wp.element.createElement( 'p', { style: { color: 'red' } }, error ),
                        suggestion && wp.element.createElement(
                            Fragment,
                            null,
                            wp.element.createElement( TextControl, {
                                label: __( 'Suggested Alt', 'wp-gutenberg-a11y-enforcer' ),
                                value: suggestion,
                                onChange: setSuggestion,
                            } ),
                            wp.element.createElement(
                                Button,
                                { variant: 'primary', onClick: applySuggestion },
                                __( 'Apply', 'wp-gutenberg-a11y-enforcer' )
                            )
                        )
                    )
                )
            );
        };
    }, 'withAiAltPanel' );

    addFilter(
        'editor.BlockEdit',
        'wp-gutenberg-a11y-enforcer/ai-alt-text',
        withAiAltPanel
    );
} )();
