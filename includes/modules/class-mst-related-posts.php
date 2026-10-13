<?php
/**
 * Related posts: shows posts sharing tags or categories with the current
 * post, drawn from sites each site chooses under Settings › Reading. Shown
 * after single posts, or wherever the [related_posts] shortcode is placed.
 */

defined( 'ABSPATH' ) || exit;

class MST_Related_Posts {

	/**
	 * Per-site option: array{sites: int[], count: int, auto: bool}.
	 */
	const OPTION = 'mst_related_posts';

	/**
	 * Network option bumped whenever a post goes live, changes or leaves on
	 * any site, which invalidates every cached list at once.
	 */
	const GENERATION = 'mst_related_posts_generation';

	/**
	 * Post meta caching each post's list, with the key it was built for.
	 */
	const META = '_mst_related_posts';

	const DEFAULT_COUNT = 3;
	const MAX_COUNT     = 12;

	/**
	 * Most recent matching posts read from each source site before ranking.
	 */
	const POOL = 20;

	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const SHORTCODE = 'related_posts';
	const HANDLE    = 'mst-related-posts';

	public static function label() {
		return __( 'Related posts', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Shows posts that share tags or categories with the current post, from sites each site chooses under Settings › Reading. Shown after single posts or with the [related_posts] shortcode.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	/**
	 * Called when the module is switched back on. Cached lists may have gone
	 * stale while the invalidation hooks below weren't registered.
	 */
	public function enable() {
		$this->flush();
	}

	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_filter( 'the_content', array( $this, 'append_to_content' ), 20 );

		// A post going live, changing or leaving, on any site, may change
		// another site's lists.
		add_action( 'transition_post_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'delete_post', array( $this, 'flush_on_delete' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'flush' ) );
		add_action( 'wp_update_site', array( $this, 'flush' ) );

		if ( is_admin() && ! is_network_admin() && ! is_user_admin() ) {
			add_action( 'admin_init', array( $this, 'register_setting' ) );
		}
	}

	public function flush() {
		update_site_option( self::GENERATION, (int) get_site_option( self::GENERATION, 0 ) + 1 );
	}

	/**
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public function flush_on_transition( $new_status, $old_status, $post ) {
		// Includes publish → publish, i.e. an edit to a live post.
		if ( 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->flush();
		}
	}

	/**
	 * @param int $post_id
	 */
	public function flush_on_delete( $post_id ) {
		if ( 'post' === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
			$this->flush();
		}
	}

	/* Settings ------------------------------------------------------------ */

	public function register_setting() {
		register_setting(
			'reading',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'mst_related_posts',
			__( 'Related posts', 'multisite-tools' ),
			array( $this, 'render_section' ),
			'reading'
		);

		add_settings_field(
			'mst_related_posts_sites',
			__( 'Show related posts from', 'multisite-tools' ),
			array( $this, 'render_sites_field' ),
			'reading',
			'mst_related_posts'
		);

		add_settings_field(
			'mst_related_posts_count',
			__( 'Number of related posts', 'multisite-tools' ),
			array( $this, 'render_count_field' ),
			'reading',
			'mst_related_posts',
			array( 'label_for' => 'mst-related-count' )
		);

		add_settings_field(
			'mst_related_posts_auto',
			__( 'Placement', 'multisite-tools' ),
			array( $this, 'render_auto_field' ),
			'reading',
			'mst_related_posts'
		);
	}

	public function render_section() {
		echo '<p>' . esc_html__( 'Posts sharing tags or categories with the one being read, from the sites you choose. Nothing is shown until at least one site is chosen.', 'multisite-tools' ) . '</p>';
	}

	public function render_sites_field() {
		$settings = $this->settings();
		$current  = get_current_blog_id();
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Show related posts from', 'multisite-tools' ); ?></legend>
			<?php foreach ( MST_Sites::details( MST_Sites::active() ) as $id => $site ) : ?>
				<label style="display: block; margin-bottom: 6px;">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[sites][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $settings['sites'], true ) ); ?> />
					<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $site['name'] ); ?>
					<span class="description">(<?php echo esc_html( $current === $id ? __( 'this site', 'multisite-tools' ) : $site['url'] ); ?>)</span>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Archived, spam and deactivated sites aren\'t listed.', 'multisite-tools' ); ?></p>
		<?php
	}

	public function render_count_field() {
		$settings = $this->settings();
		?>
		<input type="number" id="mst-related-count" name="<?php echo esc_attr( self::OPTION ); ?>[count]" value="<?php echo esc_attr( $settings['count'] ); ?>" min="1" max="<?php echo esc_attr( self::MAX_COUNT ); ?>" class="small-text" />
		<?php
	}

	public function render_auto_field() {
		$settings = $this->settings();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[auto]" value="1" <?php checked( $settings['auto'] ); ?> />
			<?php esc_html_e( 'Show after each post', 'multisite-tools' ); ?>
		</label>
		<p class="description">
			<?php
			printf(
				/* translators: %s: shortcode */
				esc_html__( 'Or untick this and place %s where you want them.', 'multisite-tools' ),
				'<code>[related_posts]</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Also given already-sanitized values, since WordPress runs it twice when
	 * the option is first added.
	 *
	 * @param mixed $input
	 * @return array{sites: int[], count: int, auto: bool}
	 */
	public function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$submitted = array_map( 'absint', (array) ( $input['sites'] ?? array() ) );

		// Keep chosen sites that aren't on the form (e.g. since archived), so
		// they come back if the site does.
		$active = array_map( 'intval', wp_list_pluck( MST_Sites::active(), 'blog_id' ) );
		$hidden = array_diff( $this->settings()['sites'], $active );
		$sites  = array_values( array_unique( array_merge( $hidden, array_intersect( $submitted, $active ) ) ) );
		sort( $sites );

		return array(
			'sites' => $sites,
			'count' => min( self::MAX_COUNT, max( 1, absint( $input['count'] ?? self::DEFAULT_COUNT ) ) ),
			'auto'  => ! empty( $input['auto'] ),
		);
	}

	/**
	 * @return array{sites: int[], count: int, auto: bool}
	 */
	private static function defaults() {
		return array(
			'sites' => array(),
			'count' => self::DEFAULT_COUNT,
			'auto'  => true,
		);
	}

	/**
	 * The current site's settings.
	 *
	 * @return array{sites: int[], count: int, auto: bool}
	 */
	private function settings() {
		$settings = get_option( self::OPTION );
		$settings = is_array( $settings ) ? array_merge( self::defaults(), $settings ) : self::defaults();

		$settings['sites'] = array_values( array_unique( array_filter( array_map( 'absint', (array) $settings['sites'] ) ) ) );
		$settings['count'] = min( self::MAX_COUNT, max( 1, (int) $settings['count'] ) );
		$settings['auto']  = (bool) $settings['auto'];

		return $settings;
	}

	/* Finding related posts ---------------------------------------------- */

	/**
	 * The post's related posts, from cache if it's still current.
	 *
	 * @param WP_Post $post
	 * @return array[]
	 */
	private function get_related( $post ) {
		$settings = $this->settings();

		if ( empty( $settings['sites'] ) ) {
			return array();
		}

		$key    = md5( wp_json_encode( array( $settings['sites'], $settings['count'], (int) get_site_option( self::GENERATION, 0 ) ) ) );
		$cached = get_post_meta( $post->ID, self::META, true );

		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && ( $cached['expires'] ?? 0 ) > time() ) {
			return (array) $cached['posts'];
		}

		$posts = $this->find_related( $post, $settings['sites'], $settings['count'] );

		update_post_meta(
			$post->ID,
			self::META,
			array(
				'key'     => $key,
				'expires' => time() + self::CACHE_TTL,
				'posts'   => $posts,
			)
		);

		return $posts;
	}

	/**
	 * Scores each source site's most recent POOL posts sharing a tag or
	 * category with $post: 2 points per shared tag, 1 per shared category.
	 * Terms are matched by slug, since each site has its own terms. Each
	 * site's default category ("Uncategorized") is ignored.
	 *
	 * @param WP_Post $post
	 * @param int[]   $site_ids
	 * @param int     $count
	 * @return array[] Highest score first, then newest.
	 */
	private function find_related( $post, $site_ids, $count ) {
		$tags = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
		$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );

		$tags = is_wp_error( $tags ) ? array() : $tags;
		$cats = is_wp_error( $cats ) ? array() : array_diff( $cats, array( $this->default_category_slug() ) );

		if ( empty( $tags ) && empty( $cats ) ) {
			return array();
		}

		$home    = get_current_blog_id();
		$related = array();

		foreach ( $site_ids as $site_id ) {
			$site = get_site( $site_id );

			// Skip sites that no longer exist or aren't public-facing.
			if ( ! $site || (int) $site->archived || (int) $site->spam || (int) $site->deleted ) {
				continue;
			}

			// Permalinks, terms and thumbnails need the site's own context.
			switch_to_blog( $site_id );

			$site_cats = array_values( array_diff( $cats, array( $this->default_category_slug() ) ) );
			$tax_query = array( 'relation' => 'OR' );

			if ( $tags ) {
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'slug',
					'terms'    => $tags,
				);
			}
			if ( $site_cats ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => $site_cats,
				);
			}

			if ( count( $tax_query ) > 1 ) {
				$query = new WP_Query(
					array(
						'post_type'           => 'post',
						'post_status'         => 'publish',
						'has_password'        => false,
						'posts_per_page'      => self::POOL,
						'post__not_in'        => $home === $site_id ? array( $post->ID ) : array(),
						'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						'orderby'             => 'date',
						'order'               => 'DESC',
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
					)
				);

				$site_name = get_bloginfo( 'name' );
				$site_url  = home_url( '/' );

				while ( $query->have_posts() ) {
					$query->the_post();

					$related[] = array(
						'title'     => get_the_title(),
						'permalink' => get_permalink(),
						'date'      => (int) get_post_time( 'U', true ),
						'date_fmt'  => get_the_date(),
						'thumbnail' => get_the_post_thumbnail_url( get_the_ID(), 'medium' ),
						'site_id'   => $site_id,
						'site_name' => $site_name,
						'site_url'  => $site_url,
						'score'     => 2 * $this->shared( 'post_tag', $tags ) + $this->shared( 'category', $site_cats ),
					);
				}

				wp_reset_postdata();
			}

			restore_current_blog();
		}

		usort(
			$related,
			function ( $a, $b ) {
				return array( $b['score'], $b['date'] ) <=> array( $a['score'], $a['date'] );
			}
		);

		return array_slice( $related, 0, $count );
	}

	/**
	 * How many of $slugs the current post in the loop has in $taxonomy.
	 * WP_Query has already primed the term cache.
	 *
	 * @param string   $taxonomy
	 * @param string[] $slugs
	 * @return int
	 */
	private function shared( $taxonomy, $slugs ) {
		$terms = get_the_terms( get_the_ID(), $taxonomy );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return 0;
		}

		return count( array_intersect( wp_list_pluck( $terms, 'slug' ), $slugs ) );
	}

	/**
	 * @return string The current site's default category slug, or ''.
	 */
	private function default_category_slug() {
		$term = get_term( (int) get_option( 'default_category' ), 'category' );

		return $term instanceof WP_Term ? $term->slug : '';
	}

	/* Front end ------------------------------------------------------------ */

	public function register_assets() {
		$file = MST_DIR . 'assets/related-posts.css';

		// Enqueued only when the list renders. Versioned by file time so
		// browsers pick up every edit.
		wp_register_style( self::HANDLE, plugins_url( 'assets/related-posts.css', MST_FILE ), array(), (string) filemtime( $file ) );
	}

	/**
	 * Adds the list after single posts when the site has "Show after each
	 * post" ticked and the post doesn't place it with the shortcode.
	 *
	 * @param string $content
	 * @return string
	 */
	public function append_to_content( $content ) {
		if ( ! is_singular( 'post' ) || is_feed() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();

		// Shortcodes have already been expanded in $content, so check the
		// post's own content.
		if ( ! $post || $post->ID !== get_queried_object_id() || has_shortcode( $post->post_content, self::SHORTCODE ) || ! $this->settings()['auto'] ) {
			return $content;
		}

		return $content . $this->render( $post, __( 'Related posts', 'multisite-tools' ) );
	}

	/**
	 * @param array|string $atts
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'title' => __( 'Related posts', 'multisite-tools' ) ),
			$atts,
			self::SHORTCODE
		);

		$post = get_post();

		return $post ? $this->render( $post, $atts['title'] ) : '';
	}

	/**
	 * @param WP_Post $post
	 * @param string  $title Heading, or '' for none.
	 * @return string HTML, or '' when there's nothing related.
	 */
	private function render( $post, $title ) {
		$posts = $this->get_related( $post );

		if ( empty( $posts ) ) {
			return '';
		}

		wp_enqueue_style( self::HANDLE );

		$current = get_current_blog_id();

		ob_start();
		?>
		<aside class="mst-related" aria-label="<?php echo esc_attr( '' !== $title ? $title : __( 'Related posts', 'multisite-tools' ) ); ?>">
			<?php if ( '' !== $title ) : ?>
				<h2 class="mst-related-heading"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<div class="mst-related-items">
				<?php foreach ( $posts as $item ) : ?>
					<article class="mst-related-item">
						<a class="mst-related-link" href="<?php echo esc_url( $item['permalink'] ); ?>">
							<?php if ( ! empty( $item['thumbnail'] ) ) : ?>
								<img class="mst-related-thumb" src="<?php echo esc_url( $item['thumbnail'] ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
							<span class="mst-related-title"><?php echo esc_html( $item['title'] ); ?></span>
						</a>
						<p class="mst-related-meta">
							<?php if ( $current !== $item['site_id'] ) : ?>
								<a class="mst-related-source" href="<?php echo esc_url( $item['site_url'] ); ?>"><?php echo esc_html( $item['site_name'] ); ?></a>
								&middot;
							<?php endif; ?>
							<span class="mst-related-date"><?php echo esc_html( $item['date_fmt'] ); ?></span>
						</p>
					</article>
				<?php endforeach; ?>
			</div>
		</aside>
		<?php
		return ob_get_clean();
	}
}
