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

				<h2><?php esc_html_e( 'Modules', 'multisite-tools' ); ?></h2>
				<p><?php esc_html_e( 'Choose which tools are active across the network.', 'multisite-tools' ); ?></p>

				<table class="form-table" role="presentation">
					<?php foreach ( Multisite_Tools::MODULES as $slug => $class ) : ?>
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

	private function page_url() {
		return network_admin_url( 'settings.php?page=' . self::PAGE );
	}
}
