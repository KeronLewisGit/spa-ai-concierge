/**
 * Treatment Finder block editor UI. Plain JS, no build step: rendered on the server.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, TextareaControl, Disabled } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'spa-ai-concierge/treatment-finder', {
		edit( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Finder text', 'spa-ai-concierge' ) },
						el( TextControl, {
							label: __( 'Heading', 'spa-ai-concierge' ),
							value: attributes.heading,
							onChange: ( heading ) => setAttributes( { heading } ),
						} ),
						el( TextareaControl, {
							label: __( 'Intro', 'spa-ai-concierge' ),
							value: attributes.intro,
							onChange: ( intro ) => setAttributes( { intro } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( Disabled, null, el( ServerSideRender, { block: 'spa-ai-concierge/treatment-finder', attributes } ) )
				)
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
