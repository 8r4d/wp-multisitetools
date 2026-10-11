<?php
/**
 * Design blocks: registers the plugin's blocks for the block editor on every
 * site. Each block lives in includes/blocks/<name>/ with a block.json, an
 * editor script and a render.php that outputs it on the front end. Code
 * shared between blocks lives in includes/blocks/shared/.
 */

defined( 'ABSPATH' ) || exit;

class MST_Blocks {

	/** Block directory names in includes/blocks/. */
	const BLOCKS = array( 'featured-excerpt', 'featured-title', 'featured-link' );

	/** Script and style handle for the featured cover the blocks share. */
	const COVER_HANDLE = 'mst-featured-cover';

	public static function label() {
		return __( 'Design blocks', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds design blocks to the editor: Featured Excerpt and Featured Title show a post\'s excerpt or title over its featured image, and Featured Link shows linked text over a chosen image with a description below.', 'multisite-tools' );
	}

	public static function category() {
		return 'blocks';
	}

	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	public function register_blocks() {
		$shared = plugins_url( 'includes/blocks/shared/', MST_FILE );
		$dir    = MST_DIR . 'includes/blocks/shared/';

		// Before the blocks, whose block.json and index.asset.php name these
		// handles. Versioned by file time so browsers pick up every edit.
		wp_register_script(
			self::COVER_HANDLE,
			$shared . 'featured-cover.js',
			array( 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-i18n' ),
			(string) filemtime( $dir . 'featured-cover.js' ),
			true
		);
		wp_register_style( self::COVER_HANDLE, $shared . 'featured-cover.css', array(), (string) filemtime( $dir . 'featured-cover.css' ) );

		foreach ( self::BLOCKS as $name ) {
			$dir = MST_DIR . 'includes/blocks/' . $name;

			register_block_type(
				$dir,
				array(
					'render_callback' => function ( $attributes, $content, $block ) use ( $dir ) {
						ob_start();
						include $dir . '/render.php';
						return ob_get_clean();
					},
				)
			);
		}
	}

	/**
	 * @param string $color Hex colour, optionally with alpha (#rgba or #rrggbbaa).
	 * @return string The colour, or '' if it isn't one.
	 */
	public static function sanitize_color( $color ) {
		return preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', (string) $color ) ? $color : '';
	}

	/**
	 * A tilt in degrees, from -$max to $max, picked from the post ID so each
	 * post keeps its angle. Matches tilt() in shared/featured-cover.js.
	 *
	 * @param int $post_id
	 * @param int $max     Maximum tilt in degrees.
	 * @return int Angle.
	 */
	public static function tilt( $post_id, $max ) {
		return ( ( $post_id * 7919 ) % ( 2 * $max + 1 ) ) - $max;
	}

	/**
	 * Outputs a featured cover: the post's featured image as a background,
	 * under an overlay, with the given text on top. Outputs nothing when
	 * there's neither an image nor text.
	 *
	 * @param WP_Post $post       Post whose featured image to use.
	 * @param array   $attributes Block attributes.
	 * @param string  $tag        Text element, e.g. 'p' or 'h2'.
	 * @param string  $text       Text, already safe HTML; '' for none.
	 */
	public static function render_cover( $post, $attributes, $tag, $text ) {
		$image_id = (int) get_post_thumbnail_id( $post );

		if ( ! $image_id && '' === $text ) {
			return;
		}

		self::cover(
			$image_id,
			$attributes,
			$tag,
			$text,
			array(
				'angle'         => empty( $attributes['tilt'] ) ? 0 : self::tilt( $post->ID, min( 45, max( 1, absint( $attributes['maxTilt'] ?? 15 ) ) ) ),
				'link'          => empty( $attributes['isLink'] ) ? null : array( 'href' => get_permalink( $post ) ),
				'block_wrapper' => true,
			)
		);
	}

	/**
	 * Outputs a cover: an image as a background, under an overlay, with text
	 * on top. The image, overlay, position, alignment and highlight come from
	 * the block attributes; the rest from $args.
	 *
	 * @param int    $image_id   Attachment ID, or 0 for none.
	 * @param array  $attributes Block attributes.
	 * @param string $tag        Text element, e.g. 'p' or 'h2'.
	 * @param string $text       Text, already safe HTML; '' for none.
	 * @param array  $args {
	 *     @type int    $angle         Text rotation in degrees. Default 0.
	 *     @type array  $link          Link for the text, or null for none:
	 *                                 'href', plus 'new_tab' (bool) and 'whole'
	 *                                 (bool, default true) to make the whole
	 *                                 cover clickable rather than just the text.
	 *     @type bool   $block_wrapper Whether the cover is the block's wrapper,
	 *                                 so it gets the block support attributes.
	 *     @type array  $class         Extra classes.
	 *     @type string $style         Extra inline CSS.
	 * }
	 */
	public static function cover( $image_id, $attributes, $tag, $text, $args = array() ) {
		$min_height = absint( $attributes['minHeight'] ?? 400 );
		$dim        = min( 100, absint( $attributes['dimRatio'] ?? 50 ) );
		$overlay    = sanitize_hex_color( $attributes['overlayColor'] ?? '' ) ?: '#000000';
		$position   = in_array( $attributes['contentPosition'] ?? '', array( 'top', 'bottom' ), true ) ? $attributes['contentPosition'] : 'center';
		$align      = in_array( $attributes['textAlign'] ?? '', array( 'left', 'center', 'right' ), true ) ? $attributes['textAlign'] : '';
		$highlight  = self::sanitize_color( $attributes['highlightColor'] ?? '' );
		$tag        = tag_escape( $tag );
		$angle      = (int) ( $args['angle'] ?? 0 );
		$link       = $args['link'] ?? null;

		$classes = array_merge( array( 'mst-cover', 'is-position-' . $position ), (array) ( $args['class'] ?? array() ) );
		if ( $align ) {
			$classes[] = 'has-text-align-' . $align;
		}
		if ( ! $image_id ) {
			$classes[] = 'has-no-image';
		}

		$wrapper = array(
			'class' => implode( ' ', $classes ),
			'style' => 'min-height:' . $min_height . 'px;' . ( $args['style'] ?? '' ),
		);

		if ( ! empty( $args['block_wrapper'] ) ) {
			$wrapper = get_block_wrapper_attributes( $wrapper );
		} else {
			$wrapper = sprintf( 'class="%s" style="%s"', esc_attr( $wrapper['class'] ), esc_attr( $wrapper['style'] ) );
		}

		$focal = (array) ( $attributes['focalPoint'] ?? array() );
		$focal = sprintf(
			'object-position:%s%% %s%%;',
			round( max( 0, min( 1, (float) ( $focal['x'] ?? 0.5 ) ) ) * 100, 2 ),
			round( max( 0, min( 1, (float) ( $focal['y'] ?? 0.5 ) ) ) * 100, 2 )
		);
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php
			if ( $image_id ) {
				// Decorative: the text carries the meaning.
				echo wp_get_attachment_image(
					$image_id,
					'full',
					false,
					array(
						'class' => 'mst-cover__image',
						'alt'   => '',
						'style' => $focal,
					)
				);
			}
			?>
			<span class="mst-cover__overlay" style="background-color:<?php echo esc_attr( $overlay ); ?>;opacity:<?php echo esc_attr( $dim / 100 ); ?>;" aria-hidden="true"></span>
			<?php
			if ( '' !== $text ) {
				$whole = $link && ( $link['whole'] ?? true );

				if ( $link ) {
					$link_attributes = ' href="' . esc_url( $link['href'] ) . '"' . ( empty( $link['new_tab'] ) ? '' : ' target="_blank" rel="noopener"' );
				}

				if ( $link && ! $whole ) {
					$text = '<a class="mst-cover__link is-text-link"' . $link_attributes . '>' . $text . '</a>';
				}

				// No whitespace inside the span, or it shows in the highlight.
				$html = sprintf(
					'<%1$s class="mst-cover__text"%5$s><span class="mst-cover__highlight%2$s"%3$s>%4$s</span></%1$s>',
					$tag,
					$highlight ? ' has-highlight' : '',
					$highlight ? ' style="background-color:' . esc_attr( $highlight ) . ';"' : '',
					$text,
					$angle ? ' style="transform:rotate(' . $angle . 'deg);"' : ''
				);

				// Outside the text, since a rotated element would contain the
				// link's full-cover click area and shrink it to the text.
				if ( $whole ) {
					$html = '<a class="mst-cover__link"' . $link_attributes . '>' . $html . '</a>';
				}

				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape() above; text is safe HTML from the caller.
			}
			?>
		</div>
		<?php
	}

	/**
	 * Classes and inline CSS for a block's text colour, typography and
	 * padding, for blocks that skip their serialization on the wrapper to
	 * apply them to an inner element instead.
	 *
	 * @param array $attributes Block attributes.
	 * @return array{class: string[], style: string}
	 */
	public static function support_styles( $attributes ) {
		$style   = (array) ( $attributes['style'] ?? array() );
		$classes = array();

		if ( ! empty( $attributes['textColor'] ) ) {
			$classes[] = 'has-text-color';
			$classes[] = 'has-' . _wp_to_kebab_case( $attributes['textColor'] ) . '-color';
		} elseif ( ! empty( $style['color']['text'] ) ) {
			$classes[] = 'has-text-color';
		}
		if ( ! empty( $attributes['fontSize'] ) ) {
			$classes[] = 'has-' . _wp_to_kebab_case( $attributes['fontSize'] ) . '-font-size';
		}
		if ( ! empty( $attributes['fontFamily'] ) ) {
			$classes[] = 'has-' . _wp_to_kebab_case( $attributes['fontFamily'] ) . '-font-family';
		}

		// The style engine turns preset references into CSS variables. It's
		// in WordPress 6.1 and later; before that only the classes apply.
		$css = '';
		if ( function_exists( 'wp_style_engine_get_styles' ) ) {
			$typography = (array) ( $style['typography'] ?? array() );
			if ( isset( $typography['fontSize'] ) && function_exists( 'wp_get_typography_font_size_value' ) ) {
				$typography['fontSize'] = wp_get_typography_font_size_value( array( 'size' => $typography['fontSize'] ) );
			}

			$styles = wp_style_engine_get_styles(
				array(
					'color'      => array( 'text' => $style['color']['text'] ?? null ),
					'typography' => $typography,
					'spacing'    => array( 'padding' => $style['spacing']['padding'] ?? null ),
				)
			);
			$css = (string) ( $styles['css'] ?? '' );
		}

		return array(
			'class' => $classes,
			'style' => $css,
		);
	}
}
