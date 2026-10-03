<?php
/**
 * Network Admin › Settings › Multisite Multitools: switch modules on and off.
 */

defined( 'ABSPATH' ) || exit;

class MST_Settings {

	const PAGE   = 'multisite-tools';
	const ACTION = 'mst_save_settings';

	public function register() {
		if ( ! is_network_admin() ) {
			return;
		}

		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_edit_' . self::ACTION, array( $this, 'save' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( MST_FILE ), array( $this, 'add_action_link' ) );
	}

	public function add_page() {
		add_submenu_page(
			'settings.php',
			__( 'Multisite Multitools', 'multisite-tools' ),
			__( 'Multisite Multitools', 'multisite-tools' ),
			'manage_network_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string[] $links
	 * @return string[]
	 */
	public function add_action_link( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $this->page_url() ), esc_html__( 'Settings', 'multisite-tools' ) )
		);
		return $links;
	}

	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Multisite Multitools', 'multisite-tools' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'multisite-tools' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<p><?php esc_html_e( 'Choose which tools are active across the network.', 'multisite-tools' ); ?></p>

				<?php foreach ( $this->modules_by_category() as $category ) : ?>
					<h2><?php echo esc_html( $category['label'] ); ?></h2>
					<p><?php echo esc_html( $category['description'] ); ?></p>

					<table class="form-table" role="presentation">
						<?php foreach ( $category['modules'] as $slug => $class ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $class::label() ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="mst_modules[<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( Multisite_Tools::is_enabled( $slug ) ); ?> />
										<?php esc_html_e( 'Enabled', 'multisite-tools' ); ?>
									</label>
									<p class="description"><?php echo esc_html( $class::description() ); ?></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function save() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'multisite-tools' ), 403 );
		}

		$submitted = (array) ( $_POST['mst_modules'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$enabled   = array();

		// Store every known module explicitly; unchecked boxes aren't submitted.
		foreach ( Multisite_Tools::MODULES as $slug => $class ) {
			$enabled[ $slug ] = ! empty( $submitted[ $slug ] );

			// A module that was off didn't see the hooks that keep its state
			// fresh, so let it reset when it comes back on.
			if ( $enabled[ $slug ] && ! Multisite_Tools::is_enabled( $slug ) && method_exists( $class, 'enable' ) ) {
				( new $class() )->enable();
			}
		}

		update_site_option( Multisite_Tools::OPTION, $enabled );

		wp_safe_redirect( add_query_arg( 'updated', 'true', $this->page_url() ) );
		exit;
	}

	/**
	 * Module categories, in display order. A module names one of these from
	 * its static category() method; anything else lands under "Other".
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function categories() {
		return array(
			'network'    => array(
				'label'       => __( 'Network administration', 'multisite-tools' ),
				'description' => __( 'Tools for super admins managing the network.', 'multisite-tools' ),
			),
			'publishing' => array(
				'label'       => __( 'Content & publishing', 'multisite-tools' ),
				'description' => __( 'Tools for writing and scheduling posts on each site.', 'multisite-tools' ),
			),
			'sharing'    => array(
				'label'       => __( 'Sharing & SEO', 'multisite-tools' ),
				'description' => __( 'How each site\'s pages appear when shared or found.', 'multisite-tools' ),
			),
			'other'      => array(
				'label'       => __( 'Other', 'multisite-tools' ),
				'description' => '',
			),
		);
	}

	/**
	 * @return array<string, array{label: string, description: string, modules: array<string, string>}>
	 *               Non-empty categories in display order, each with slug => class.
	 */
	private function modules_by_category() {
		$categories = self::categories();

		foreach ( Multisite_Tools::MODULES as $slug => $class ) {
			$category = method_exists( $class, 'category' ) ? $class::category() : 'other';
			$category = isset( $categories[ $category ] ) ? $category : 'other';

			$categories[ $category ]['modules'][ $slug ] = $class;
		}

		return array_filter(
			$categories,
			function ( $category ) {
				return ! empty( $category['modules'] );
			}
		);
	}

	private function page_url() {
		return network_admin_url( 'settings.php?page=' . self::PAGE );
	}
}
