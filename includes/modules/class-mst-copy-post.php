<?php
/**
 * Copy to site: adds a "Copy to site…" row action to posts and pages that
 * creates a draft copy on another site in the network, with its categories,
 * tags, featured image and the media library files its content uses.
 *
 * Each copy remembers its original, and each original its copies, so both
 * are labelled in the post list, copying twice to one site is flagged, and
 * a copy can point search engines to its original with a canonical link.
 */

defined( 'ABSPATH' ) || exit;

class MST_Copy_Post {

	const PAGE   = 'mst-copy-post';
	const ACTION = 'mst_copy_post';

	/**
	 * Post meta on a copy: array{site: int, post: int, canonical: bool}.
	 */
	const FROM_META = '_mst_copied_from';

	/**
	 * Post meta on an original, one row per copy: array{site: int, post: int}.
	 */
	const COPIES_META = '_mst_copies';

	const META_BOX_NONCE = 'mst_copied_from_nonce';

	/**
	 * Post types that can be copied. Custom post types are left out because
	 * they may not exist on the target site.
	 */
	const POST_TYPES = array( 'post', 'page' );

	/** @var array<int, string>|null Cached target_sites() for this request. */
	private $target_sites;

	/** @var array<int, string> Site names for post states, by site ID. */
	private $site_names = array();

	/** @var array<int, string|null> original_url() by copy ID, for this request. */
	private $original_urls = array();

	public static function label() {
		return __( 'Copy to site', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds a "Copy to site…" link to posts and pages that creates a draft copy, with its categories, tags, featured image and media, on another site you can edit. Copies and originals are labelled, and a copy can name its original as the canonical version.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		// Trash can be emptied by cron, so this runs everywhere.
		add_action( 'before_delete_post', array( $this, 'forget_copy' ) );

		if ( ! is_admin() ) {
			add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 10, 2 );
			add_filter( 'wpseo_canonical', array( $this, 'filter_canonical' ) );
			add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_canonical' ) );
			return;
		}

		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'display_post_states', array( $this, 'add_post_states' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_copy' ) );
		add_action( 'admin_notices', array( $this, 'print_notice' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save_meta_box' ) );
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

		$copies = $this->existing_copies( $post->ID );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Copy to Site', 'multisite-tools' ); ?></h1>

			<?php if ( $copies ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'This has already been copied to:', 'multisite-tools' ); ?></p>
					<ul>
						<?php foreach ( $copies as $site_id => $copy_ids ) : ?>
							<?php foreach ( $copy_ids as $copy_id ) : ?>
								<li>
									<?php echo MST_Sites::swatch( $site_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<strong><?php echo esc_html( $this->site_name( $site_id ) ); ?></strong>
									<a href="<?php echo esc_url( get_admin_url( $site_id, 'post.php?post=' . $copy_id . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit the copy', 'multisite-tools' ); ?></a>
								</li>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</ul>
					<p><?php esc_html_e( 'Copying again makes another, separate copy.', 'multisite-tools' ); ?></p>
				</div>
			<?php endif; ?>

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
										<option value="<?php echo esc_attr( $site_id ); ?>">
											<?php
											echo esc_html( $label );
											if ( isset( $copies[ $site_id ] ) ) {
												echo ' ' . esc_html__( '(already has a copy)', 'multisite-tools' );
											}
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The copy is created as a draft, with the same categories, tags and featured image.', 'multisite-tools' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Options', 'multisite-tools' ); ?></th>
							<td>
								<p>
									<label>
										<input type="checkbox" name="media" value="1" checked>
										<?php esc_html_e( 'Copy media in the content', 'multisite-tools' ); ?>
									</label>
									<br><span class="description"><?php esc_html_e( 'Images and other files from this site\'s media library that the content uses are copied to the other site\'s media library, and the content is updated to use them. Untick to leave them pointing to this site.', 'multisite-tools' ); ?></span>
								</p>
								<p>
									<label>
										<input type="checkbox" name="canonical" value="1">
										<?php esc_html_e( 'Point search engines to this original', 'multisite-tools' ); ?>
									</label>
									<br><span class="description"><?php esc_html_e( 'Once published, the copy gives this post as its canonical URL, so search engines treat it as a duplicate and rank the original. Can be changed later in the copy\'s Copied from box.', 'multisite-tools' ); ?></span>
								</p>
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

		$result = $this->copy( $post, $site_id, ! empty( $_POST['media'] ), ! empty( $_POST['canonical'] ) );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		add_post_meta(
			$post->ID,
			self::COPIES_META,
			array(
				'site' => $site_id,
				'post' => $result['post_id'],
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'          => $post->post_type,
					'mst_copied'         => $site_id . '-' . $result['post_id'],
					'mst_copied_noimg'   => $result['image_failed'] ? 1 : false,
					'mst_copied_nomedia' => $result['media_failed'] ? $result['media_failed'] : false,
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
	 * @param bool    $media     Whether to copy media library files the content uses.
	 * @param bool    $canonical Whether the copy names the original as canonical.
	 * @return array{post_id: int, image_failed: bool, media_failed: int}|WP_Error
	 */
	private function copy( $post, $site_id, $media, $canonical ) {
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

		$content  = $media ? $this->content_media( $post->post_content ) : array(
			'files'   => array(),
			'urls'    => array(),
			'missing' => 0,
		);
		$files    = $content['files'];
		$thumb_id = get_post_thumbnail_id( $post );
		if ( $thumb_id && ! isset( $files[ $thumb_id ] ) ) {
			$files[ $thumb_id ] = $this->attachment_data( $thumb_id );
		}

		$source_id = get_current_blog_id();

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

			update_post_meta(
				$new_id,
				self::FROM_META,
				array(
					'site'      => $source_id,
					'post'      => $post->ID,
					'canonical' => $canonical,
				)
			);

			// Old attachment ID => new, or false if it couldn't be copied.
			$copied = array();
			foreach ( $files as $old_id => $file ) {
				$copied[ $old_id ] = $this->copy_attachment( $file, $new_id );
			}

			$image_failed = $thumb_id && ! ( $copied[ $thumb_id ] && set_post_thumbnail( $new_id, $copied[ $thumb_id ] ) );
			$media_failed = $content['missing'];

			if ( $content['urls'] ) {
				$updated = $this->rewrite_content( $post->post_content, $content['urls'], $copied );
				foreach ( array_keys( $content['files'] ) as $old_id ) {
					$media_failed += $copied[ $old_id ] ? 0 : 1;
				}
				if ( $updated !== $post->post_content ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'           => $new_id,
								'post_content' => $updated,
							)
						)
					);
				}
			}

			return array(
				'post_id'      => $new_id,
				'image_failed' => $image_failed,
				'media_failed' => $media_failed,
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
	 * Media library files on this site that the content links to or shows,
	 * found by URL under this site's uploads folder. Resized images
	 * (photo-300x200.jpg) are traced back to their attachment.
	 *
	 * @param string $content
	 * @return array{files: array<int, array>, urls: array<string, array{id: int, width: int, height: int}>, missing: int}
	 *         Attachment ID => attachment_data(); each URL found with its
	 *         attachment and size (0 × 0 for the full size); and how many
	 *         uploads URLs had no attachment.
	 */
	private function content_media( $content ) {
		global $wpdb;

		$result = array(
			'files'   => array(),
			'urls'    => array(),
			'missing' => 0,
		);

		$uploads = wp_get_upload_dir();
		$base    = preg_quote( preg_replace( '#^https?:#i', '', $uploads['baseurl'] ), '#' );

		if ( ! preg_match_all( '#https?:' . $base . '/([^\s"\'<>()?\#]+)#i', $content, $matches, PREG_SET_ORDER ) ) {
			return $result;
		}

		// URL => relative file names that may be its attachment's, best
		// first, with the size it shows. A resized image (photo-300x200.jpg)
		// belongs to photo.jpg, or photo-scaled.jpg if WordPress scaled
		// the upload down; a link to the unscaled original likewise.
		$found = array();
		foreach ( $matches as $match ) {
			$file = rawurldecode( $match[1] );

			// The main site's uploads folder holds other sites' too.
			if ( is_main_site() && preg_match( '#^sites/\d+/#', $file ) ) {
				continue;
			}

			$names = array( $file );
			$size  = array( 0, 0 );

			if ( preg_match( '/^(.+)-(\d+)x(\d+)(\.\w+)$/', $file, $parts ) ) {
				$names[] = $parts[1] . $parts[4];
				$names[] = $parts[1] . '-scaled' . $parts[4];
				$size    = array( (int) $parts[2], (int) $parts[3] );
			} elseif ( preg_match( '/^(.+)(\.\w+)$/', $file, $parts ) ) {
				$names[] = $parts[1] . '-scaled' . $parts[2];
			}

			$found[ $match[0] ] = array( $names, $size );
		}

		if ( ! $found ) {
			return $result;
		}

		$lookup = array_values( array_unique( array_merge( ...array_column( $found, 0 ) ) ) );
		$in     = implode( ', ', array_fill( 0, count( $lookup ), '%s' ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$lookup
			)
		);
		$ids    = array_column( $rows, 'post_id', 'meta_value' );

		foreach ( $found as $url => list( $names, $size ) ) {
			$id = 0;
			foreach ( $names as $i => $name ) {
				if ( isset( $ids[ $name ] ) ) {
					$id = (int) $ids[ $name ];
					// The exact file is the full size, even if its name
					// ends in -WxH.
					$size = 0 === $i ? array( 0, 0 ) : $size;
					break;
				}
			}

			if ( ! $id ) {
				++$result['missing'];
				continue;
			}

			if ( ! isset( $result['files'][ $id ] ) ) {
				$result['files'][ $id ] = $this->attachment_data( $id );
			}

			$result['urls'][ $url ] = array(
				'id'     => $id,
				'width'  => $size[0],
				'height' => $size[1],
			);
		}

		return $result;
	}

	/**
	 * What's needed to recreate an attachment on another site.
	 *
	 * @param int $id Attachment ID on the current site.
	 * @return array{path: string|false, title: string, alt: string, caption: string, description: string}
	 */
	private function attachment_data( $id ) {
		// The original upload, not the -scaled copy, so the target site
		// makes its own sizes from the best file.
		$path = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $id ) : false;
		$post = get_post( $id );

		return array(
			'path'        => $path && is_readable( $path ) ? $path : get_attached_file( $id ),
			'title'       => $post ? $post->post_title : '',
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post ? $post->post_excerpt : '',
			'description' => $post ? $post->post_content : '',
		);
	}

	/**
	 * Copies a file into the current site's media library, attached to a post.
	 *
	 * @param array{path: string|false, title: string, alt: string, caption: string, description: string} $file
	 * @param int                                                                                          $post_id
	 * @return int|false The new attachment ID, or false if the file is
	 *                   missing (e.g. offloaded to S3) or the upload failed.
	 */
	private function copy_attachment( $file, $post_id ) {
		if ( ! $file['path'] || ! is_readable( $file['path'] ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves the file, so give it a copy.
		$tmp = wp_tempnam( $file['path'] );
		if ( ! $tmp || ! copy( $file['path'], $tmp ) ) {
			return false;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => wp_basename( $file['path'] ),
				'tmp_name' => $tmp,
			),
			$post_id,
			null,
			wp_slash(
				array(
					'post_title'   => $file['title'],
					'post_excerpt' => $file['caption'],
					'post_content' => $file['description'],
				)
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return false;
		}

		if ( '' !== $file['alt'] ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $file['alt'] ) );
		}

		return $attachment_id;
	}

	/**
	 * Points the content's media at the copied attachments on the current
	 * site: file URLs, wp-image-N classes, data-id attributes and the media
	 * IDs in block comments.
	 *
	 * @param string                                                  $content
	 * @param array<string, array{id: int, width: int, height: int}> $urls   From content_media().
	 * @param array<int, int|false>                                   $copied Old attachment ID => new.
	 * @return string
	 */
	private function rewrite_content( $content, $urls, $copied ) {
		$copied = array_filter( $copied );
		if ( ! $copied ) {
			return $content;
		}

		$replace = array();
		foreach ( $urls as $url => $image ) {
			if ( isset( $copied[ $image['id'] ] ) ) {
				$replace[ $url ] = $this->sized_url( $copied[ $image['id'] ], $image['width'], $image['height'] );
			}
		}

		$swap = function ( $id ) use ( $copied ) {
			return $copied[ (int) $id ] ?? (int) $id;
		};

		$content = preg_replace_callback(
			'/(\bwp-image-|<img\b[^>]*\bdata-id=")(\d+)/',
			function ( $match ) use ( $swap ) {
				return $match[1] . $swap( $match[2] );
			},
			$content
		);

		$content = preg_replace_callback(
			'#<!-- wp:((?:core/)?(?:image|cover|media-text|gallery|file|video|audio)) (\{.*?\}) (/)?-->#s',
			function ( $match ) use ( $swap ) {
				$attrs = json_decode( $match[2], true );
				if ( ! is_array( $attrs ) ) {
					return $match[0];
				}
				foreach ( array( 'id', 'mediaId' ) as $key ) {
					if ( isset( $attrs[ $key ] ) && is_numeric( $attrs[ $key ] ) ) {
						$attrs[ $key ] = $swap( $attrs[ $key ] );
					}
				}
				if ( isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
					$attrs['ids'] = array_map( $swap, $attrs['ids'] );
				}
				return '<!-- wp:' . $match[1] . ' ' . ( function_exists( 'serialize_block_attributes' ) ? serialize_block_attributes( $attrs ) : wp_json_encode( $attrs ) ) . ' ' . ( $match[3] ?? '' ) . '-->';
			},
			$content
		);

		return strtr( $content, $replace );
	}

	/**
	 * The URL of an attachment at the given size, or its full size if the
	 * current site has no size with those dimensions.
	 *
	 * @param int $id
	 * @param int $width  0 for the full size.
	 * @param int $height
	 * @return string
	 */
	private function sized_url( $id, $width, $height ) {
		$url  = wp_get_attachment_url( $id );
		$meta = wp_get_attachment_metadata( $id );

		if ( $width && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( (int) $size['width'] === $width && (int) $size['height'] === $height ) {
					return path_join( dirname( $url ), $size['file'] );
				}
			}
		}

		return $url;
	}

	/**
	 * Copies of a post that still exist, dropping records of deleted ones.
	 *
	 * @param int $post_id
	 * @return array<int, int[]> Site ID => copy IDs.
	 */
	private function existing_copies( $post_id ) {
		$copies = array();

		foreach ( get_post_meta( $post_id, self::COPIES_META ) as $copy ) {
			$site = get_site( (int) ( $copy['site'] ?? 0 ) );
			$copy_post = null;
			if ( $site ) {
				switch_to_blog( $site->blog_id );
				$copy_post = get_post( (int) $copy['post'] );
				restore_current_blog();
			}

			if ( $copy_post ) {
				$copies[ (int) $copy['site'] ][] = (int) $copy['post'];
			} else {
				delete_post_meta( $post_id, self::COPIES_META, $copy );
			}
		}

		return $copies;
	}

	/**
	 * When a copy is deleted, removes it from its original's list of copies.
	 *
	 * @param int $post_id
	 */
	public function forget_copy( $post_id ) {
		$from = get_post_meta( $post_id, self::FROM_META, true );

		if ( ! is_array( $from ) || ! get_site( (int) ( $from['site'] ?? 0 ) ) ) {
			return;
		}

		$copy = array(
			'site' => get_current_blog_id(),
			'post' => (int) $post_id,
		);

		switch_to_blog( (int) $from['site'] );
		delete_post_meta( (int) $from['post'], self::COPIES_META, $copy );
		restore_current_blog();
	}

	/**
	 * Labels copies and originals in the Posts and Pages lists.
	 *
	 * @param string[] $states
	 * @param WP_Post  $post
	 * @return string[]
	 */
	public function add_post_states( $states, $post ) {
		$from = get_post_meta( $post->ID, self::FROM_META, true );
		if ( is_array( $from ) && ! empty( $from['site'] ) ) {
			/* translators: %s: site name */
			$states['mst_copy'] = sprintf( __( 'Copy from %s', 'multisite-tools' ), $this->site_name( (int) $from['site'] ) );
		}

		$sites = array_unique( wp_list_pluck( get_post_meta( $post->ID, self::COPIES_META ), 'site' ) );
		if ( 1 === count( $sites ) ) {
			/* translators: %s: site name */
			$states['mst_copies'] = sprintf( __( 'Copied to %s', 'multisite-tools' ), $this->site_name( (int) reset( $sites ) ) );
		} elseif ( $sites ) {
			/* translators: %s: number of sites */
			$states['mst_copies'] = sprintf( __( 'Copied to %s sites', 'multisite-tools' ), number_format_i18n( count( $sites ) ) );
		}

		return $states;
	}

	/**
	 * @param int $site_id
	 * @return string
	 */
	private function site_name( $site_id ) {
		if ( ! isset( $this->site_names[ $site_id ] ) ) {
			$name = get_site( $site_id ) ? (string) get_blog_option( $site_id, 'blogname' ) : '';

			$this->site_names[ $site_id ] = '' !== $name ? $name : __( 'a deleted site', 'multisite-tools' );
		}

		return $this->site_names[ $site_id ];
	}

	/**
	 * @param string  $post_type
	 * @param WP_Post $post
	 */
	public function add_meta_box( $post_type, $post ) {
		if ( ! $post instanceof WP_Post || ! is_array( get_post_meta( $post->ID, self::FROM_META, true ) ) ) {
			return;
		}

		add_meta_box( 'mst-copied-from', __( 'Copied from', 'multisite-tools' ), array( $this, 'render_meta_box' ), null, 'side' );
	}

	/**
	 * @param WP_Post $post
	 */
	public function render_meta_box( $post ) {
		$from    = get_post_meta( $post->ID, self::FROM_META, true );
		$site_id = (int) $from['site'];
		$exists  = false;

		if ( get_site( $site_id ) ) {
			switch_to_blog( $site_id );
			$original = get_post( (int) $from['post'] );
			if ( $original ) {
				$exists = true;
				$title  = _draft_or_post_title( $original );
				$link   = current_user_can( 'edit_post', $original->ID ) ? get_edit_post_link( $original->ID, 'raw' ) : get_permalink( $original );
			}
			restore_current_blog();
		}

		wp_nonce_field( self::META_BOX_NONCE, self::META_BOX_NONCE );
		?>
		<p>
			<?php echo MST_Sites::swatch( $site_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<strong><?php echo esc_html( $this->site_name( $site_id ) ); ?></strong><br>
			<?php if ( $exists ) : ?>
				<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'The original has been deleted.', 'multisite-tools' ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<label>
				<input type="checkbox" name="mst_canonical" value="1" <?php checked( ! empty( $from['canonical'] ) ); ?>>
				<?php esc_html_e( 'Point search engines to the original', 'multisite-tools' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Gives the original as this post\'s canonical URL, while the original is published.', 'multisite-tools' ); ?></p>
		<?php
	}

	/**
	 * @param int $post_id
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST[ self::META_BOX_NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::META_BOX_NONCE ] ), self::META_BOX_NONCE ) ) {
			return;
		}

		$from = get_post_meta( $post_id, self::FROM_META, true );

		if ( wp_is_post_revision( $post_id ) || ! is_array( $from ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$from['canonical'] = ! empty( $_POST['mst_canonical'] );
		update_post_meta( $post_id, self::FROM_META, $from );
	}

	/**
	 * Gives a copy's original as its canonical URL, if the copy asks for it
	 * and the original is published. Handles core's rel_canonical(), Yoast
	 * SEO and Rank Math.
	 *
	 * @param string       $url
	 * @param WP_Post|null $post Passed by core's filter only.
	 * @return string
	 */
	public function filter_canonical( $url, $post = null ) {
		$post = $post instanceof WP_Post ? $post : ( is_singular() ? get_queried_object() : null );

		if ( ! $post instanceof WP_Post ) {
			return $url;
		}

		$original = $this->original_url( $post->ID );

		return $original ? $original : $url;
	}

	/**
	 * @param int $post_id
	 * @return string|null The original's permalink, if the copy names it as
	 *                     canonical and it's published on an active site.
	 */
	private function original_url( $post_id ) {
		if ( array_key_exists( $post_id, $this->original_urls ) ) {
			return $this->original_urls[ $post_id ];
		}

		$from = get_post_meta( $post_id, self::FROM_META, true );
		$url  = null;
		$site = is_array( $from ) && ! empty( $from['canonical'] ) ? get_site( (int) $from['site'] ) : null;

		if ( $site && ! $site->archived && ! $site->spam && ! $site->deleted ) {
			switch_to_blog( $site->blog_id );
			$original = get_post( (int) $from['post'] );
			if ( $original && 'publish' === $original->post_status && '' === $original->post_password ) {
				$url = get_permalink( $original );
			}
			restore_current_blog();
		}

		$this->original_urls[ $post_id ] = $url ? $url : null;

		return $this->original_urls[ $post_id ];
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
			$all     = MST_Sites::active();
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
					MST_Sites::swatch( $site_id ) . '<strong>' . esc_html( $name ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
				<a href="<?php echo esc_url( get_admin_url( $site_id, 'post.php?post=' . $post_id . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit the copy', 'multisite-tools' ); ?></a>
			</p>
			<?php if ( ! empty( $_GET['mst_copied_noimg'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<p><?php esc_html_e( 'The featured image could not be copied, so set it on the copy by hand.', 'multisite-tools' ); ?></p>
			<?php endif; ?>
			<?php
			$missing = absint( $_GET['mst_copied_nomedia'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $missing ) :
				?>
				<p>
					<?php
					/* translators: %s: number of files */
					echo esc_html( sprintf( _n( '%s file in the content could not be copied, so it still points to this site.', '%s files in the content could not be copied, so they still point to this site.', $missing, 'multisite-tools' ), number_format_i18n( $missing ) ) );
					?>
				</p>
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
		$args[] = 'mst_copied_nomedia';
		return $args;
	}
}
