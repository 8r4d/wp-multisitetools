<?php
/**
 * Social graph: adds Open Graph and Twitter image meta tags to single posts,
 * pages and custom post types, so links shared on Threads, Facebook, X etc.
 * show the featured image. Each site can choose a default image for posts
 * without one, under Settings › Reading.
 */

defined( 'ABSPATH' ) || exit;

class MST_Social_Graph {

	/**
	 * Per-site option holding the default image's attachment ID.
	 */
	const OPTION = 'mst_social_image';

	public static function label() {
		return __( 'Social graph', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Open Graph and Twitter image tags to single posts and pages using the featured image, falling back to a default image each site can set under Settings › Reading, then the site icon. Skipped on sites where an SEO plugin already adds them.', 'multisite-tools' );
	}

	public static function category() {
		return 'sharing';
	}

	public function register() {
		add_action( 'wp_head', array( $this, 'print_meta' ), 5 );

		if ( is_admin() && ! is_network_admin() && ! is_user_admin() ) {
			add_action( 'admin_init', array( $this, 'register_setting' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media' ) );
		}
	}

	public function register_setting() {
		register_setting(
			'reading',
			self::OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);

		add_settings_section(
			'mst_social_graph',
			__( 'Social sharing', 'multisite-tools' ),
			'__return_null',
			'reading'
		);

		add_settings_field(
			self::OPTION,
			__( 'Default sharing image', 'multisite-tools' ),
			array( $this, 'render_field' ),
			'reading',
			'mst_social_graph'
		);
	}

	/**
	 * @param string $hook_suffix
	 */
	public function enqueue_media( $hook_suffix ) {
		if ( 'options-reading.php' === $hook_suffix ) {
			wp_enqueue_media();
		}
	}

	public function render_field() {
		$attachment_id = (int) get_option( self::OPTION, 0 );
		$preview       = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'medium' ) : '';
		?>
		<div id="mst-social-image">
			<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" />
			<p class="mst-social-image-preview"<?php echo $preview ? '' : ' hidden'; ?>>
				<img src="<?php echo esc_url( $preview ); ?>" alt="" style="max-width: 300px; height: auto; display: block;" />
			</p>
			<p>
				<button type="button" class="button mst-social-image-choose"><?php esc_html_e( 'Choose image', 'multisite-tools' ); ?></button>
				<button type="button" class="button-link mst-social-image-remove"<?php echo $preview ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'multisite-tools' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Used when a post or page shared on social media has no featured image. 1200 × 630 pixels works best. If none is set, the site icon is used.', 'multisite-tools' ); ?></p>
		</div>
		<script>
			jQuery( function ( $ ) {
				var $field = $( '#mst-social-image' ),
					$input = $field.find( 'input' ),
					$preview = $field.find( '.mst-social-image-preview' ),
					$remove = $field.find( '.mst-social-image-remove' ),
					frame;

				$field.on( 'click', '.mst-social-image-choose', function () {
					frame = frame || wp.media( {
						title: <?php echo wp_json_encode( __( 'Default sharing image', 'multisite-tools' ) ); ?>,
						button: { text: <?php echo wp_json_encode( __( 'Use this image', 'multisite-tools' ) ); ?> },
						library: { type: 'image' },
						multiple: false
					} );

					frame.off( 'select' ).on( 'select', function () {
						var image = frame.state().get( 'selection' ).first().toJSON(),
							size = image.sizes && ( image.sizes.medium || image.sizes.full );

						$input.val( image.id );
						$preview.prop( 'hidden', false ).find( 'img' ).attr( 'src', size ? size.url : image.url );
						$remove.prop( 'hidden', false );
					} );

					frame.open();
				} );

				$remove.on( 'click', function () {
					$input.val( 0 );
					$preview.prop( 'hidden', true );
					$remove.prop( 'hidden', true );
				} );
			} );
		</script>
		<?php
	}

	public function print_meta() {
		if ( ! is_singular() || $this->seo_plugin_active() ) {
			return;
		}

		$image = $this->get_image();

		if ( ! $image ) {
			return;
		}

		echo "\n<!-- Social Sharing Image -->\n";
		echo '<meta property="og:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";
		echo '<meta property="og:image:secure_url" content="' . esc_url( set_url_scheme( $image['url'], 'https' ) ) . '" />' . "\n";

		if ( $image['width'] && $image['height'] ) {
			echo '<meta property="og:image:width" content="' . absint( $image['width'] ) . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . absint( $image['height'] ) . '" />' . "\n";
		}

		echo '<meta name="twitter:image" content="' . esc_url( $image['url'] ) . '" />' . "\n";
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
	}

	/**
	 * The featured image, else the site's default sharing image, else the
	 * site icon.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function get_image() {
		$candidates = array(
			get_post_thumbnail_id( get_queried_object_id() ),
			(int) get_option( self::OPTION, 0 ),
			(int) get_option( 'site_icon', 0 ),
		);

		// Skip any that have since been deleted from the media library.
		$data = false;
		foreach ( array_filter( $candidates ) as $candidate ) {
			$data = wp_get_attachment_image_src( $candidate, 'full' );
			if ( $data ) {
				break;
			}
		}

		if ( ! $data ) {
			return null;
		}

		return array(
			'url'    => $data[0],
			'width'  => (int) $data[1],
			'height' => (int) $data[2],
		);
	}

	/**
	 * Whether a plugin that already outputs og:image is active on this site.
	 * Filter with mst_social_graph_skip to cover others.
	 *
	 * @return bool
	 */
	private function seo_plugin_active() {
		$active = defined( 'WPSEO_VERSION' )       // Yoast SEO.
			|| class_exists( 'RankMath' )           // Rank Math.
			|| defined( 'AIOSEO_VERSION' )          // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )        // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' ); // The SEO Framework.

		return (bool) apply_filters( 'mst_social_graph_skip', $active );
	}
}
