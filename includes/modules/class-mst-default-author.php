<?php
/**
 * Default author: each site can choose an author that's automatically
 * assigned to newly created posts, so admins can write under a
 * lower-privileged account's name without remembering to switch it.
 */

defined( 'ABSPATH' ) || exit;

class MST_Default_Author {

	/**
	 * Same option as the standalone Default Post Author plugin, so sites that
	 * used it keep their setting.
	 */
	const OPTION = 'dpa_default_author';

	const PAGE  = 'mst-default-author';
	const GROUP = 'mst_default_author';

	public static function label() {
		return __( 'Default author', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Lets each site choose an author that is automatically assigned to new posts, under Settings › Default Post Author on that site.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		add_filter( 'wp_insert_post_data', array( $this, 'set_author' ), PHP_INT_MAX, 2 );

		if ( is_admin() && ! is_network_admin() && ! is_user_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_page' ) );
			add_action( 'admin_init', array( $this, 'register_setting' ) );
		}
	}

	public function add_page() {
		add_options_page(
			__( 'Default Post Author', 'multisite-tools' ),
			__( 'Default Post Author', 'multisite-tools' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$default_author = (int) get_option( self::OPTION, 0 );

		// Users of this site who can write posts.
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'display_name', 'user_login' ),
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Default Post Author', 'multisite-tools' ); ?></h1>

			<p><?php esc_html_e( 'Choose the author that should automatically be assigned to newly created blog posts. Existing posts are not affected.', 'multisite-tools' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mst_default_author"><?php esc_html_e( 'Default author', 'multisite-tools' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION ); ?>" id="mst_default_author">
								<option value="0"><?php esc_html_e( '— No default —', 'multisite-tools' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $default_author, (int) $user->ID ); ?>>
										<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description"><?php esc_html_e( 'New posts will use this author by default. You can still change the author manually in the editor.', 'multisite-tools' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sets the configured author on a newly created post.
	 *
	 * The block editor commonly supplies the current user as post_author when
	 * creating a post, so on a new post that value (or no value) is replaced.
	 * An explicitly chosen different author is respected, and existing posts
	 * are never changed.
	 *
	 * @param array $data    Slashed, sanitized post data.
	 * @param array $postarr Slashed, sanitized post data as submitted.
	 * @return array
	 */
	public function set_author( $data, $postarr ) {
		// A post ID means this is an update, not a new post.
		if ( ! empty( $postarr['ID'] ) ) {
			return $data;
		}

		if ( empty( $data['post_type'] ) || 'post' !== $data['post_type'] ) {
			return $data;
		}

		$default_author = (int) get_option( self::OPTION, 0 );

		// The user may have been deleted or removed from this site since.
		if ( $default_author <= 0 || ! is_user_member_of_blog( $default_author ) ) {
			return $data;
		}

		$submitted_author = (int) ( $postarr['post_author'] ?? 0 );

		if ( 0 === $submitted_author || get_current_user_id() === $submitted_author ) {
			$data['post_author'] = $default_author;
		}

		return $data;
	}
}
