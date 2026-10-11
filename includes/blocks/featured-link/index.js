/**
 * Featured Link editor. The background, overlay and their controls come from
 * shared/featured-cover.js; the front end is rendered by render.php. The
 * description below the image is inner blocks, saved in the post content.
 */
( function ( blocks, element, blockEditor, components, i18n, cover ) {
	const el = element.createElement;
	const __ = i18n.__;

	const LinkControl = blockEditor.LinkControl || blockEditor.__experimentalLinkControl;

	/** Blocks allowed in the description. */
	const DESCRIPTION_BLOCKS = [ 'core/paragraph', 'core/heading', 'core/list', 'core/buttons' ];

	/**
	 * A preset reference such as var:preset|color|primary as a CSS variable.
	 *
	 * @param {*} value
	 * @return {*} CSS value.
	 */
	function presetVar( value ) {
		if ( 'string' !== typeof value || 0 !== value.indexOf( 'var:' ) ) {
			return value;
		}
		return 'var(--wp--' + value.slice( 4 ).split( '|' ).join( '--' ) + ')';
	}

	/**
	 * kebab-case like WordPress's preset class names, e.g. h2 → h-2.
	 *
	 * @param {string} value
	 * @return {string} kebab-case.
	 */
	function kebab( value ) {
		return ( String( value ).match( /[A-Z]{2,}(?=[A-Z][a-z]+|\b|\d)|[A-Z]?[a-z]+|[A-Z]+|\d+/g ) || [] )
			.map( function ( word ) {
				return word.toLowerCase();
			} )
			.join( '-' );
	}

	/**
	 * Classes and styles for the text colour, typography and padding, which
	 * block.json keeps off the wrapper so they style the cover and not the
	 * description. Matches MST_Blocks::support_styles().
	 *
	 * @param {Object} attributes
	 * @return {{className: string[], style: Object}} Cover props.
	 */
	function supportProps( attributes ) {
		const style = attributes.style || {};
		const classes = [];
		const css = {};

		if ( attributes.textColor ) {
			classes.push( 'has-text-color', 'has-' + kebab( attributes.textColor ) + '-color' );
		} else if ( style.color && style.color.text ) {
			classes.push( 'has-text-color' );
			css.color = presetVar( style.color.text );
		}
		if ( attributes.fontSize ) {
			classes.push( 'has-' + kebab( attributes.fontSize ) + '-font-size' );
		}
		if ( attributes.fontFamily ) {
			classes.push( 'has-' + kebab( attributes.fontFamily ) + '-font-family' );
		}

		Object.keys( style.typography || {} ).forEach( function ( key ) {
			css[ key ] = presetVar( style.typography[ key ] );
		} );

		const padding = style.spacing && style.spacing.padding;
		if ( 'string' === typeof padding ) {
			css.padding = presetVar( padding );
		} else if ( padding ) {
			[ 'top', 'right', 'bottom', 'left' ].forEach( function ( side ) {
				if ( padding[ side ] ) {
					css[ 'padding' + side.charAt( 0 ).toUpperCase() + side.slice( 1 ) ] = presetVar( padding[ side ] );
				}
			} );
		}

		return { className: classes, style: css };
	}

	function Edit( props ) {
		const attributes = props.attributes;
		const setAttributes = props.setAttributes;
		const tagName = 'h' + attributes.level;

		const editingLinkState = element.useState( false );
		const isEditingLink = editingLinkState[ 0 ];
		const setEditingLink = editingLinkState[ 1 ];
		const anchorState = element.useState( null );

		const text = cover.text( props, tagName, {
			value: attributes.text,
			onChange: function ( value ) {
				setAttributes( { text: value } );
			},
			// No links: the text is the link.
			allowedFormats: [ 'core/bold', 'core/italic' ],
			withoutInteractiveFormatting: true,
			placeholder: __( 'Link text…', 'multisite-tools' ),
			'aria-label': __( 'Link text', 'multisite-tools' ),
		} );

		function setLevel( value ) {
			setAttributes( { level: Number( value ) } );
		}

		const linkButton = LinkControl && el( components.ToolbarButton, {
			ref: anchorState[ 1 ],
			icon: 'admin-links',
			label: attributes.url ? __( 'Edit link', 'multisite-tools' ) : __( 'Link', 'multisite-tools' ),
			isPressed: !! attributes.url,
			onClick: function () {
				setEditingLink( true );
			},
		} );

		const linkPopover = LinkControl && isEditingLink && el(
			components.Popover,
			{
				anchor: anchorState[ 0 ],
				placement: 'bottom',
				focusOnMount: 'firstElement',
				onClose: function () {
					setEditingLink( false );
				},
			},
			el( LinkControl, {
				value: { url: attributes.url, opensInNewTab: attributes.opensInNewTab },
				onChange: function ( value ) {
					setAttributes( { url: value.url || undefined, opensInNewTab: !! value.opensInNewTab } );
				},
				onRemove: function () {
					setAttributes( { url: undefined, opensInNewTab: false } );
					setEditingLink( false );
				},
			} )
		);

		// HeadingLevelDropdown is in the toolbar from WordPress 6.4; before
		// that, choose the level in the Settings panel.
		const toolbar = el(
			element.Fragment,
			null,
			blockEditor.HeadingLevelDropdown && el( blockEditor.HeadingLevelDropdown, { value: attributes.level, onChange: setLevel } ),
			linkButton,
			linkPopover
		);

		const settings = el(
			element.Fragment,
			null,
			! blockEditor.HeadingLevelDropdown && el( components.SelectControl, {
				label: __( 'Heading level', 'multisite-tools' ),
				value: attributes.level,
				options: [ 1, 2, 3, 4, 5, 6 ].map( function ( level ) {
					return { label: 'H' + level, value: level };
				} ),
				onChange: setLevel,
			} ),
			attributes.imageId
				? el(
					components.Button,
					{
						variant: 'secondary',
						isDestructive: true,
						style: { marginBottom: '16px' },
						onClick: function () {
							setAttributes( { imageId: undefined, focalPoint: undefined } );
						},
					},
					__( 'Remove image', 'multisite-tools' )
				)
				: el(
					'p',
					{ className: 'components-base-control__help' },
					__( 'Add an image from the block toolbar.', 'multisite-tools' )
				)
		);

		const link = attributes.url
			? el( components.ToggleControl, {
				label: __( 'Make the whole image a link', 'multisite-tools' ),
				help: __( 'Off: only the text is a link.', 'multisite-tools' ),
				checked: attributes.isLink,
				onChange: function ( value ) {
					setAttributes( { isLink: value } );
				},
			} )
			: el(
				'p',
				{ className: 'components-base-control__help' },
				__( 'Add a link from the block toolbar.', 'multisite-tools' )
			);

		const tilt = el( components.RangeControl, {
			label: __( 'Angle (degrees)', 'multisite-tools' ),
			value: attributes.angle,
			min: -45,
			max: 45,
			onChange: function ( value ) {
				setAttributes( { angle: undefined === value ? -5 : value } );
			},
		} );

		const description = el(
			'div',
			{ className: 'mst-promo__description' },
			el( blockEditor.InnerBlocks, {
				allowedBlocks: DESCRIPTION_BLOCKS,
				template: [ [ 'core/paragraph', { placeholder: __( 'Describe the link…', 'multisite-tools' ) } ] ],
				templateLock: false,
			} )
		);

		const media = el(
			blockEditor.BlockControls,
			{ group: 'other' },
			el( blockEditor.MediaReplaceFlow, {
				mediaId: attributes.imageId,
				allowedTypes: [ 'image' ],
				accept: 'image/*',
				name: attributes.imageId ? __( 'Replace image', 'multisite-tools' ) : __( 'Add image', 'multisite-tools' ),
				onSelect: function ( image ) {
					setAttributes( { imageId: image.id, focalPoint: undefined } );
				},
			} )
		);

		return el(
			element.Fragment,
			null,
			media,
			cover.edit( props, {
				text: text,
				toolbar: toolbar,
				settings: settings,
				imageId: attributes.imageId || 0,
				link: link,
				tiltHelp: __( 'Rotates the text.', 'multisite-tools' ),
				tilt: tilt,
				wrapper: {
					className: 'mst-promo',
					cover: supportProps( attributes ),
					after: description,
				},
			} )
		);
	}

	blocks.registerBlockType( 'multisite-tools/featured-link', {
		edit: Edit,
		// The image and text are rendered on the server; only the
		// description is saved in the post.
		save: function () {
			return el( blockEditor.InnerBlocks.Content );
		},
	} );
} )( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n, window.mstFeaturedCover );
