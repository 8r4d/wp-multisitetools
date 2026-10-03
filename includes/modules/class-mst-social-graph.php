<?php
/**
 * Social graph: adds Open Graph and Twitter image meta tags to single posts,
 * pages and custom post types, so links shared on Threads, Facebook, X etc.
 * show the featured image.
 */

defined( 'ABSPATH' ) || exit;

class MST_Social_Graph {

	public static function label() {
		return __( 'Social graph', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Open Graph and Twitter image tags to single posts and pages using the featured image, falling back to the site icon. Skipped on sites where an SEO plugin already adds them.', 'multisite-tools' );
	}

	public static function category() {
		return 'sharing';
	}

	public function register() {
		add_action( 'wp_head', array( $this, 'print_meta' ), 5 );
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
	 * The featured image, else the site icon.
	 *
	 * @return array{url: string, width: int, height: int}|null
	 */
	private function get_image() {
		$attachment_id = get_post_thumbnail_id( get_queried_object_id() );

		if ( ! $attachment_id ) {
			$attachment_id = (int) get_option( 'site_icon' );
		}

		$data = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'full' ) : false;

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
