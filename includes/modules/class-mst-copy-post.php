<?php
/**
 * Copy to site: adds a "Copy to site…" row action to posts and pages that
 * creates a draft copy on another site in the network, with its categories,
 * tags and featured image.
 */

defined( 'ABSPATH' ) || exit;

class MST_Copy_Post {

	const PAGE   = 'mst-copy-post';
	const ACTION = 'mst_copy_post';

	/**
	 * Post types that can be copied. Custom post types are left out because
	 * they may not exist on the target site.
	 */
	const POST_TYPES = array( 'post', 'page' );

	/** @var array<int, string>|null Cached target_sites() for this request. */
	private $target_sites;

	public static function label() {
		return __( 'Copy to site', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds a "Copy to site…" link to posts and pages that creates a draft copy, with its categories, tags and featured image, on another site you can edit.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		if ( ! is_admin() || is_network_admin() || is_user_admin() ) {
			return;
		}

		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_copy' ) );
		add_action( 'admin_notices', array( $this, 'print_notice' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * @param string[] $actions
	 * @param WP_Post  $post
	 * @return string[]
	 */
	public function add_row_action( $actions, $post ) {
		if ( ! in_array( $post->post_type, self::POST_TYPES, true ) || ! current_user_can( 'edit_post', $post->ID ) || ! $this->target_sites() ) {
			return $actions;
		}

		$actions['mst_copy_post'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&post=' . $post->ID ) ),
			esc_html__( 'Copy to site…', 'multisite-tools' )
		);

		return $actions;
	}

	/**
	 * Registered under options.php so it has no menu item; it's reached from
	 * the row action.
	 */
	public function add_page() {
		add_submenu_page(
			'options.php',
			__( 'Copy to Site', 'multisite-tools' ),
			__( 'Copy to Site', 'multisite-tools' ),
			'edit_posts',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		$post  = get_post( absint( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sites = $this->target_sites();

		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to copy this item.', 'multisite-tools' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Copy to Site', 'multisite-tools' ); ?></h1>

			<?php if ( ! $sites ) : ?>
				<p><?php esc_html_e( 'You are not a member of any other site to copy to.', 'multisite-tools' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
					<input type="hidden" name="post_id" value="<?php echo esc_attr( $post->ID ); ?>" />
					<?php wp_nonce_field( self::ACTION . '_' . $post->ID ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html( get_post_type_object( $post->post_type )->labels->singular_name ); ?></th>
							<td><strong><?php echo esc_html( _draft_or_post_title( $post ) ); ?></strong></td>
						</tr>
						<tr>
							<th scope="row"><label for="mst-copy-site"><?php esc_html_e( 'Copy to', 'multisite-tools' ); ?></label></th>
							<td>
								<select name="site_id" id="mst-copy-site">
									<?php foreach ( $sites as $site_id => $label ) : ?>
										<option value="<?php echo esc_attr( $site_id ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The copy is created as a draft, with the same categories, tags and featured image. Images inside the content still point to this site.', 'multisite-tools' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Copy', 'multisite-tools' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_copy() {
		$post_id = absint( $_POST['post_id'] ?? 0 );
		$site_id = absint( $_POST['site_id'] ?? 0 );

		check_admin_referer( self::ACTION . '_' . $post_id );

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to copy this item.', 'multisite-tools' ), 403 );
		}

		if ( ! isset( $this->target_sites()[ $site_id ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to copy to that site.', 'multisite-tools' ), 403 );
		}

		$result = $this->copy( $post, $site_id );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'        => $post->post_type,
					'mst_copied'       => $site_id . '-' . $result['post_id'],
					'mst_copied_noimg' => $result['image_failed'] ? 1 : false,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Creates a draft copy of $post on another site.
	 *
	 * @param WP_Post $post
	 * @param int     $site_id
	 * @return array{post_id: int, image_failed: bool}|WP_Error
	 */
	private function copy( $post, $site_id ) {
		// Gather everything from this site before switching.
		$terms = array();
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			if ( is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
				$names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $names ) ) {
					$terms[ $taxonomy ] = $names;
				}
			}
		}

		$image    = null;
		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id ) {
			$image = array(
				'path'  => get_attached_file( $thumb_id ),
				'title' => get_the_title( $thumb_id ),
				'alt'   => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
			);
		}

		switch_to_blog( $site_id );

		try {
			if ( ! current_user_can( get_post_type_object( $post->post_type )->cap->create_posts ) ) {
				return new WP_Error( 'mst_copy_forbidden', __( 'Sorry, you are not allowed to create this kind of content on that site.', 'multisite-tools' ) );
			}

			$new_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => $post->post_type,
						'post_status'    => 'draft',
						'post_author'    => get_current_user_id(),
						'post_title'     => $post->post_title,
						'post_content'   => $post->post_content,
						'post_excerpt'   => $post->post_excerpt,
						'comment_status' => $post->comment_status,
						'ping_status'    => $post->ping_status,
						'menu_order'     => $post->menu_order,
					)
				),
				true
			);

			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}

			if ( ! empty( $terms['category'] ) ) {
				wp_set_post_terms( $new_id, $this->category_ids( $terms['category'] ), 'category' );
			}
			if ( ! empty( $terms['post_tag'] ) ) {
				wp_set_post_terms( $new_id, $terms['post_tag'], 'post_tag' );
			}

			return array(
				'post_id'      => $new_id,
				'image_failed' => $image && ! $this->copy_image( $image, $new_id ),
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Category IDs on the current site for the given names, creating any that
	 * don't exist yet.
	 *
	 * @param string[] $names
	 * @return int[]
	 */
	private function category_ids( $names ) {
		$ids = array();

		foreach ( $names as $name ) {
			$term = term_exists( $name, 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'category' );
			}
			if ( ! is_wp_error( $term ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}

		return $ids;
	}

	/**
	 * Copies an image file into the current site's media library and sets it
	 * as the post's featured image.
	 *
	 * @param array{path: string|false, title: string, alt: string} $image
	 * @param int                                                    $post_id
	 * @return bool False if the file is missing (e.g. offloaded to S3) or the upload failed.
	 */
	private function copy_image( $image, $post_id ) {
		if ( ! $image['path'] || ! is_readable( $image['path'] ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves the file, so give it a copy.
		$tmp = wp_tempnam( $image['path'] );
		if ( ! $tmp || ! copy( $image['path'], $tmp ) ) {
			return false;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => wp_basename( $image['path'] ),
				'tmp_name' => $tmp,
			),
			$post_id,
			$image['title']
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		if ( '' !== $image['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $image['alt'] ) );
		}

		return (bool) set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Other active sites the current user can copy to, as site ID => label.
	 * Super admins get every site; everyone else gets the sites they belong to.
	 * Whether they can create posts there is checked when copying.
	 *
	 * @return array<int, string>
	 */
	private function target_sites() {
		if ( null !== $this->target_sites ) {
			return $this->target_sites;
		}

		$current = get_current_blog_id();
		$sites   = array();

		if ( is_super_admin() ) {
			$all     = get_sites(
				array(
					'network_id' => get_current_network_id(),
					'number'     => 0,
					'archived'   => 0,
					'deleted'    => 0,
					'spam'       => 0,
				)
			);
			$options = MST_Sites::get_options( $all, array( 'blogname' ) );

			foreach ( $all as $site ) {
				$sites[ (int) $site->blog_id ] = $this->site_label( $options[ (int) $site->blog_id ]['blogname'] ?? '', MST_Sites::url( $site ) );
			}
		} else {
			foreach ( get_blogs_of_user( get_current_user_id() ) as $site ) {
				if ( ! $site->archived && ! $site->spam && ! $site->deleted ) {
					$sites[ (int) $site->userblog_id ] = $this->site_label( $site->blogname, untrailingslashit( $site->domain . $site->path ) );
				}
			}
		}

		unset( $sites[ $current ] );

		$this->target_sites = $sites;

		return $sites;
	}

	/**
	 * @param string $name
	 * @param string $url
	 * @return string
	 */
	private function site_label( $name, $url ) {
		return '' !== $name ? $name . ' (' . $url . ')' : $url;
	}

	public function print_notice() {
		$copied = sanitize_text_field( wp_unslash( $_GET['mst_copied'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$screen = get_current_screen();

		if ( ! $screen || 'edit' !== $screen->base || ! preg_match( '/^(\d+)-(\d+)$/', $copied, $ids ) ) {
			return;
		}

		$site_id = (int) $ids[1];
		$post_id = (int) $ids[2];
		$name    = get_blog_option( $site_id, 'blogname' );
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: site name */
					esc_html__( 'Copied as a draft to %s.', 'multisite-tools' ),
					'<strong>' . esc_html( $name ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit the copy', 'multisite-tools' ); ?></a>
			</p>
			<?php if ( ! empty( $_GET['mst_copied_noimg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<p><?php esc_html_e( 'The featured image could not be copied, so set it on the copy by hand.', 'multisite-tools' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string[] $args
	 * @return string[]
	 */
	public function removable_query_args( $args ) {
		$args[] = 'mst_copied';
		$args[] = 'mst_copied_noimg';
		return $args;
	}
}
