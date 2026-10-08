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
	const BLOCKS = array( 'featured-excerpt', 'featured-title' );

	/** Script and style handle for the featured cover the blocks share. */
	const COVER_HANDLE = 'mst-featured-cover';

	public static function label() {
		return __( 'Design blocks', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds design blocks to the editor: Featured Excerpt and Featured Title show a post\'s excerpt or title over its featured image.', 'multisite-tools' );
	}

	public static function category() {
		return 'blocks';
	}

	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	public function register_blocks() {
		$shared = plugins_url( 'includes/blocks/shared/', MST_FILE );

		// Before the blocks, whose block.json and index.asset.php name these handles.
		wp_register_script(
			self::COVER_HANDLE,
			$shared . 'featured-cover.js',
			array( 'wp-block-editor', 'wp-components', 'wp-core-data', 'wp-data', 'wp-element', 'wp-i18n' ),
			MST_VERSION,
			true
		);
		wp_register_style( self::COVER_HANDLE, $shared . 'featured-cover.css', array(), MST_VERSION );

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

		$min_height = absint( $attributes['minHeight'] ?? 400 );
		$dim        = min( 100, absint( $attributes['dimRatio'] ?? 50 ) );
		$overlay    = sanitize_hex_color( $attributes['overlayColor'] ?? '' ) ?: '#000000';
		$position   = in_array( $attributes['contentPosition'] ?? '', array( 'top', 'bottom' ), true ) ? $attributes['contentPosition'] : 'center';
		$align      = in_array( $attributes['textAlign'] ?? '', array( 'left', 'center', 'right' ), true ) ? $attributes['textAlign'] : '';
		$highlight  = self::sanitize_color( $attributes['highlightColor'] ?? '' );
		$tag        = tag_escape( $tag );

		$classes = array( 'mst-cover', 'is-position-' . $position );
		if ( $align ) {
			$classes[] = 'has-text-align-' . $align;
		}
		if ( ! $image_id ) {
			$classes[] = 'has-no-image';
		}

		$wrapper = get_block_wrapper_attributes(
			array(
				'class' => implode( ' ', $classes ),
				'style' => 'min-height:' . $min_height . 'px;',
			)
		);

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
				if ( ! empty( $attributes['isLink'] ) ) {
					$text = '<a class="mst-cover__link" href="' . esc_url( get_permalink( $post ) ) . '">' . $text . '</a>';
				}

				// No whitespace inside the span, or it shows in the highlight.
				printf(
					'<%1$s class="mst-cover__text"><span class="mst-cover__highlight%2$s"%3$s>%4$s</span></%1$s>',
					$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- tag_escape() above.
					$highlight ? ' has-highlight' : '',
					$highlight ? ' style="background-color:' . esc_attr( $highlight ) . ';"' : '',
					$text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe HTML from the caller.
				);
			}
			?>
		</div>
		<?php
	}
}
