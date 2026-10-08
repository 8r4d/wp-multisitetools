/**
 * Featured Title editor. The background, overlay and their controls come
 * from shared/featured-cover.js; the front end is rendered by render.php.
 */
( function ( blocks, element, blockEditor, components, coreData, i18n, cover ) {
	const el = element.createElement;
	const __ = i18n.__;

	function Edit( props ) {
		const attributes = props.attributes;
		const setAttributes = props.setAttributes;
		const context = props.context;
		const titleProp = coreData.useEntityProp( 'postType', context.postType, 'title', context.postId );
		const rawTitle = titleProp[ 0 ] || '';
		const tagName = 'h' + attributes.level;

		let text;
		if ( cover.isEditable( context ) ) {
			text = cover.text( attributes, tagName, {
				value: rawTitle,
				onChange: titleProp[ 1 ],
				allowedFormats: [],
				withoutInteractiveFormatting: true,
				placeholder: __( 'Title', 'multisite-tools' ),
				'aria-label': __( 'Title', 'multisite-tools' ),
			} );
		} else {
			text = cover.text(
				attributes,
				tagName,
				cover.stripTags( ( titleProp[ 2 ] && titleProp[ 2 ].rendered ) || rawTitle ) || __( 'The post\'s title.', 'multisite-tools' )
			);
		}

		function setLevel( value ) {
			setAttributes( { level: Number( value ) } );
		}

		// HeadingLevelDropdown is in the toolbar from WordPress 6.4; before
		// that, choose the level in the Settings panel.
		const toolbar = blockEditor.HeadingLevelDropdown
			? el( blockEditor.HeadingLevelDropdown, { value: attributes.level, onChange: setLevel } )
			: null;
		const settings = blockEditor.HeadingLevelDropdown
			? null
			: el( components.SelectControl, {
				label: __( 'Heading level', 'multisite-tools' ),
				value: attributes.level,
				options: [ 1, 2, 3, 4, 5, 6 ].map( function ( level ) {
					return { label: 'H' + level, value: level };
				} ),
				onChange: setLevel,
			} );

		return cover.edit( props, { text: text, toolbar: toolbar, settings: settings } );
	}

	blocks.registerBlockType( 'multisite-tools/featured-title', {
		edit: Edit,
		// Rendered on the server, so it always shows the post's current image and title.
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.coreData, wp.i18n, window.mstFeaturedCover );
