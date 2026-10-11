/**
 * Featured cover editor: the parts the featured blocks share, an image (by
 * default the post's featured image) as a background under an overlay, with
 * the position, height, link, tilt, focal point, overlay and highlight
 * controls. Each block passes in its own text. Plain JS on the wp.* globals,
 * so there's no build step.
 */
( function ( element, blockEditor, components, data, coreData, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;

	const VerticalAlignmentControl = blockEditor.BlockVerticalAlignmentControl || blockEditor.BlockVerticalAlignmentToolbar;
	const AlignmentControl = blockEditor.AlignmentControl || blockEditor.AlignmentToolbar;

	/**
	 * Plain text of an HTML string, without running anything in it.
	 *
	 * @param {string} html
	 * @return {string} Text.
	 */
	function stripTags( html ) {
		return new window.DOMParser().parseFromString( html || '', 'text/html' ).body.textContent.trim();
	}

	/**
	 * Whether the block shows the post being edited, so its text can be
	 * edited in place. In a Query Loop it shows another post, read-only.
	 *
	 * @param {Object} context Block context.
	 * @return {boolean} Editable.
	 */
	function isEditable( context ) {
		return !! context.postId && ! Number.isFinite( context.queryId );
	}

	/**
	 * The text's tilt in degrees, from -max to max. Picked from the post ID,
	 * so each post keeps its angle; matches MST_Blocks::tilt().
	 *
	 * @param {number} postId
	 * @param {number} max    Maximum tilt in degrees.
	 * @return {number} Angle.
	 */
	function tilt( postId, max ) {
		return ( ( postId * 7919 ) % ( 2 * max + 1 ) ) - max;
	}

	/**
	 * The block's text element: tagName with class mst-cover__text, tilted if
	 * the block says so, around a span that carries the highlight, so it
	 * follows each line of text. A block with an angle attribute tilts by
	 * that; otherwise each post gets its own angle up to maxTilt.
	 *
	 * @param {Object}        props   Block edit props.
	 * @param {string}        tagName Text element, e.g. 'p' or 'h2'.
	 * @param {Object|string} content RichText props to edit the text in
	 *                                place, or the text to show read-only.
	 * @return {Element} Text element.
	 */
	function text( props, tagName, content ) {
		const attributes = props.attributes;
		let angle = 0;
		if ( attributes.tilt ) {
			// Without a post, e.g. in a template preview, show a typical tilt.
			angle = 'number' === typeof attributes.angle ? attributes.angle : tilt( props.context.postId || 1, attributes.maxTilt );
		}
		const span = {
			className: 'mst-cover__highlight' + ( attributes.highlightColor ? ' has-highlight' : '' ),
			style: attributes.highlightColor ? { backgroundColor: attributes.highlightColor } : undefined,
		};

		return el(
			tagName,
			{
				className: 'mst-cover__text',
				style: angle ? { transform: 'rotate(' + angle + 'deg)' } : undefined,
			},
			'string' === typeof content
				? el( 'span', span, content )
				: el( blockEditor.RichText, Object.assign( { tagName: 'span' }, content, span ) )
		);
	}

	/**
	 * Renders the block in the editor. Call from the block's edit function.
	 *
	 * @param {Object}  props               Block edit props.
	 * @param {Object}  options
	 * @param {Element} options.text        The block's text, from text().
	 * @param {Element} [options.toolbar]   Extra block toolbar controls.
	 * @param {Element} [options.settings]  Extra controls for the Settings panel.
	 * @param {number}  [options.imageId]   Image to use instead of the post's
	 *                                      featured image; 0 for none.
	 * @param {Element} [options.link]      Replaces the "Link to post" toggle.
	 * @param {string}  [options.tiltHelp]  Help for the "Tilt text" toggle.
	 * @param {Element} [options.tilt]      Replaces the "Maximum tilt" control.
	 * @param {Object}  [options.wrapper]   Wraps the cover in the block's
	 *                                      wrapper instead of making it the
	 *                                      wrapper: { className, cover: {
	 *                                      className, style }, after }, where
	 *                                      after is an element below the cover.
	 * @param {Element} [options.inspector] Extra inspector panels.
	 * @return {Element} Block editor element.
	 */
	function edit( props, options ) {
		const attributes = props.attributes;
		const setAttributes = props.setAttributes;
		const context = props.context;

		const featuredId = coreData.useEntityProp( 'postType', context.postType, 'featured_media', context.postId )[ 0 ];
		const ownImage = undefined !== options.imageId;
		const imageId = ownImage ? options.imageId : featuredId;

		const selected = data.useSelect(
			function ( select ) {
				return {
					media: imageId ? select( 'core' ).getMedia( imageId, { context: 'view' } ) : null,
					colors: select( 'core/block-editor' ).getSettings().colors || [],
				};
			},
			[ imageId ]
		);
		const imageUrl = selected.media && selected.media.source_url;

		const focalPoint = attributes.focalPoint || { x: 0.5, y: 0.5 };
		const classes = [ 'mst-cover', 'is-position-' + attributes.contentPosition ];
		if ( attributes.textAlign ) {
			classes.push( 'has-text-align-' + attributes.textAlign );
		}
		if ( ! imageUrl ) {
			classes.push( 'has-no-image' );
		}

		const wrapper = options.wrapper;
		const coverStyle = { minHeight: attributes.minHeight + 'px' };
		let blockProps;
		let coverProps;
		if ( wrapper ) {
			blockProps = blockEditor.useBlockProps( { className: wrapper.className } );
			coverProps = {
				className: classes.concat( wrapper.cover.className || [] ).join( ' ' ),
				style: Object.assign( {}, wrapper.cover.style, coverStyle ),
			};
		} else {
			blockProps = blockEditor.useBlockProps( { className: classes.join( ' ' ), style: coverStyle } );
			coverProps = blockProps;
		}

		const toolbar = el(
			blockEditor.BlockControls,
			{ group: 'block' },
			options.toolbar,
			el( VerticalAlignmentControl, {
				value: attributes.contentPosition,
				onChange: function ( value ) {
					setAttributes( { contentPosition: value || 'center' } );
				},
			} ),
			el( AlignmentControl, {
				value: attributes.textAlign,
				onChange: function ( value ) {
					setAttributes( { textAlign: value } );
				},
			} )
		);

		const inspector = el(
			blockEditor.InspectorControls,
			null,
			el(
				components.PanelBody,
				{ title: __( 'Settings', 'multisite-tools' ) },
				! ownImage && isEditable( context ) && ! featuredId && el(
					'p',
					{ className: 'components-base-control__help' },
					__( 'Set a featured image in the post settings to use it as the background.', 'multisite-tools' )
				),
				options.settings,
				el( components.RangeControl, {
					label: __( 'Minimum height (px)', 'multisite-tools' ),
					value: attributes.minHeight,
					min: 100,
					max: 1200,
					step: 10,
					onChange: function ( value ) {
						setAttributes( { minHeight: value || 400 } );
					},
				} ),
				undefined !== options.link ? options.link : el( components.ToggleControl, {
					label: __( 'Link to post', 'multisite-tools' ),
					help: __( 'Makes the whole block a link, e.g. in a Query Loop.', 'multisite-tools' ),
					checked: attributes.isLink,
					onChange: function ( value ) {
						setAttributes( { isLink: value } );
					},
				} ),
				el( components.ToggleControl, {
					label: __( 'Tilt text', 'multisite-tools' ),
					help: options.tiltHelp || __( 'Rotates the text by a different angle for each post.', 'multisite-tools' ),
					checked: attributes.tilt,
					onChange: function ( value ) {
						setAttributes( { tilt: value } );
					},
				} ),
				attributes.tilt && options.tilt,
				attributes.tilt && ! options.tilt && el( components.RangeControl, {
					label: __( 'Maximum tilt (degrees)', 'multisite-tools' ),
					value: attributes.maxTilt,
					min: 1,
					max: 45,
					onChange: function ( value ) {
						setAttributes( { maxTilt: value || 15 } );
					},
				} ),
				imageUrl && el( components.FocalPointPicker, {
					label: __( 'Image focal point', 'multisite-tools' ),
					url: imageUrl,
					value: focalPoint,
					onChange: function ( value ) {
						setAttributes( { focalPoint: value } );
					},
				} )
			),
			el(
				components.PanelBody,
				{ title: __( 'Overlay', 'multisite-tools' ) },
				el(
					components.BaseControl,
					{ label: __( 'Colour', 'multisite-tools' ) },
					el( components.ColorPalette, {
						colors: selected.colors,
						value: attributes.overlayColor,
						clearable: false,
						onChange: function ( value ) {
							setAttributes( { overlayColor: value || '#000000' } );
						},
					} )
				),
				el( components.RangeControl, {
					label: __( 'Opacity', 'multisite-tools' ),
					value: attributes.dimRatio,
					min: 0,
					max: 100,
					step: 5,
					onChange: function ( value ) {
						setAttributes( { dimRatio: undefined === value ? 50 : value } );
					},
				} )
			),
			el(
				components.PanelBody,
				{ title: __( 'Text highlight', 'multisite-tools' ), initialOpen: !! attributes.highlightColor },
				el(
					components.BaseControl,
					{
						label: __( 'Colour', 'multisite-tools' ),
						help: __( 'A bar of colour behind each line of text, to make it easier to read over the image. Clear it for none.', 'multisite-tools' ),
					},
					el( components.ColorPalette, {
						colors: selected.colors,
						value: attributes.highlightColor,
						enableAlpha: true,
						onChange: function ( value ) {
							setAttributes( { highlightColor: value } );
						},
					} )
				)
			),
			options.inspector
		);

		const cover = el(
			'div',
			coverProps,
			imageUrl && el( 'img', {
				className: 'mst-cover__image',
				src: imageUrl,
				alt: '',
				style: { objectPosition: ( focalPoint.x * 100 ) + '% ' + ( focalPoint.y * 100 ) + '%' },
			} ),
			el( 'span', {
				className: 'mst-cover__overlay',
				style: { backgroundColor: attributes.overlayColor, opacity: attributes.dimRatio / 100 },
				'aria-hidden': true,
			} ),
			options.text
		);

		return el(
			element.Fragment,
			null,
			toolbar,
			inspector,
			wrapper ? el( 'div', blockProps, cover, wrapper.after ) : cover
		);
	}

	window.mstFeaturedCover = {
		edit: edit,
		text: text,
		isEditable: isEditable,
		stripTags: stripTags,
	};
} )( wp.element, wp.blockEditor, wp.components, wp.data, wp.coreData, wp.i18n );
