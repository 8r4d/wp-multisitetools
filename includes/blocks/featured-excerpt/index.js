/**
 * Featured Excerpt editor. The background, overlay and their controls come
 * from shared/featured-cover.js; the front end is rendered by render.php.
 */
( function ( blocks, coreData, i18n, cover ) {
	const __ = i18n.__;

	function Edit( props ) {
		const context = props.context;
		const excerptProp = coreData.useEntityProp( 'postType', context.postType, 'excerpt', context.postId );
		const rawExcerpt = excerptProp[ 0 ] || '';
		const autoExcerpt = cover.stripTags( excerptProp[ 2 ] && excerptProp[ 2 ].rendered );

		let text;
		if ( cover.isEditable( context ) ) {
			text = cover.text( props, 'p', {
				value: rawExcerpt,
				onChange: excerptProp[ 1 ],
				allowedFormats: [],
				withoutInteractiveFormatting: true,
				// With no excerpt set, WordPress generates one from the content.
				placeholder: autoExcerpt || __( 'Write an excerpt…', 'multisite-tools' ),
				'aria-label': __( 'Excerpt', 'multisite-tools' ),
			} );
		} else {
			text = cover.text(
				props,
				'p',
				cover.stripTags( rawExcerpt ) || autoExcerpt || __( 'The post\'s excerpt.', 'multisite-tools' )
			);
		}

		return cover.edit( props, { text: text } );
	}

	blocks.registerBlockType( 'multisite-tools/featured-excerpt', {
		edit: Edit,
		// Rendered on the server, so it always shows the post's current image and excerpt.
		save: function () {
			return null;
		},
	} );
} )( wp.blocks, wp.coreData, wp.i18n, window.mstFeaturedCover );
