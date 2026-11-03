/**
 * Spa FAQs block editor UI. Plain JS, no build step: rendered on the server.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'spa-ai-concierge/faqs', {
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
						{ title: __( 'FAQ settings', 'spa-ai-concierge' ) },
						el( TextControl, {
							label: __( 'Heading', 'spa-ai-concierge' ),
							value: attributes.heading,
							onChange: ( heading ) => setAttributes( { heading } ),
						} ),
						el( TextControl, {
							label: __( 'Post ID (blank = this post)', 'spa-ai-concierge' ),
							type: 'number',
							value: attributes.postId || '',
							onChange: ( value ) => setAttributes( { postId: parseInt( value, 10 ) || 0 } ),
						} ),
						el( ToggleControl, {
							label: __( 'Open the first answer', 'spa-ai-concierge' ),
							checked: !! attributes.openFirst,
							onChange: ( openFirst ) => setAttributes( { openFirst } ),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'spa-ai-concierge/faqs',
						attributes,
						urlQueryArgs: { post_id: props.context && props.context.postId },
						EmptyResponsePlaceholder: () =>
							el(
								'p',
								{ className: 'spa-faqs-placeholder' },
								__( 'No FAQs saved for this post yet. Use “Generate FAQs” in the Spa AI Concierge box below the editor, review them, then update the post.', 'spa-ai-concierge' )
							),
					} )
				)
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
