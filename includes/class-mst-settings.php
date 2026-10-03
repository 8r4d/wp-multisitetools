<?php
/**
 * Network Admin › Settings › Multisite Multitools: switch modules on and off,
 * and choose each site's colour.
 */

defined( 'ABSPATH' ) || exit;

class MST_Settings {

	const PAGE          = 'multisite-tools';
	const ACTION        = 'mst_save_settings';
	const COLORS_ACTION = 'mst_save_colors';

	/** @var string|false Settings page hook suffix. */
	private $hook = false;

	public function register() {
		if ( ! is_network_admin() ) {
			return;
		}

		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'network_admin_edit_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'network_admin_edit_' . self::COLORS_ACTION, array( $this, 'save_colors' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( MST_FILE ), array( $this, 'add_action_link' ) );
	}

	public function add_page() {
		$this->hook = add_submenu_page(
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

	/**
	 * @param string $hook_suffix
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery( function ( $ ) { $( ".mst-color-field" ).wpColorPicker(); } );' );
	}

	/**
	 * Tab slug => label.
	 *
	 * @return array<string, string>
	 */
	private function tabs() {
		return array(
			'modules' => __( 'Modules', 'multisite-tools' ),
			'colors'  => __( 'Site colours', 'multisite-tools' ),
		);
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$tab     = sanitize_key( $_GET['tab'] ?? '' );
		$tab     = isset( $this->tabs()[ $tab ] ) ? $tab : 'modules';
		$updated = isset( $_GET['updated'] );
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Multisite Multitools', 'multisite-tools' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'multisite-tools' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper wp-clearfix">
				<?php foreach ( $this->tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->page_url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'colors' === $tab ) {
				$this->render_colors_tab();
			} else {
				$this->render_modules_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_modules_tab() {
		?>
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
		<?php
	}

	private function render_colors_tab() {
		$sites   = MST_Sites::active();
		$options = MST_Sites::get_options( $sites, array( 'blogname' ) );
		$colors  = (array) get_site_option( MST_Sites::COLORS_OPTION, array() );
		?>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::COLORS_ACTION ) ); ?>">
			<?php wp_nonce_field( self::COLORS_ACTION ); ?>

			<p><?php esc_html_e( 'Each site\'s colour marks it wherever Multisite Multitools lists sites: the Calendar, the Posts by Site widget, and the Plugin and Theme usage columns. Sites without a custom colour get a default based on their ID; use Default to go back to it.', 'multisite-tools' ); ?></p>

			<table class="form-table" role="presentation">
				<?php
				foreach ( $sites as $site ) :
					$id   = (int) $site->blog_id;
					$name = (string) ( $options[ $id ]['blogname'] ?? '' );
					?>
					<tr>
						<th scope="row">
							<label for="mst-site-color-<?php echo esc_attr( $id ); ?>">
								<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo esc_html( '' !== $name ? $name : MST_Sites::url( $site ) ); ?>
							</label>
							<p class="description"><?php echo esc_html( MST_Sites::url( $site ) ); ?></p>
						</th>
						<td>
							<input type="text" class="mst-color-field" id="mst-site-color-<?php echo esc_attr( $id ); ?>" name="mst_site_colors[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $colors[ $id ] ?? MST_Sites::default_color( $id ) ); ?>" data-default-color="<?php echo esc_attr( MST_Sites::default_color( $id ) ); ?>" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	public function save_colors() {
		check_admin_referer( self::COLORS_ACTION );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'multisite-tools' ), 403 );
		}

		$submitted = (array) wp_unslash( $_POST['mst_site_colors'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$colors    = (array) get_site_option( MST_Sites::COLORS_OPTION, array() );

		// Only sites shown on the form; others (e.g. archived) keep their colour.
		foreach ( MST_Sites::active() as $site ) {
			$id    = (int) $site->blog_id;
			$color = sanitize_hex_color( strtolower( trim( (string) ( $submitted[ $id ] ?? '' ) ) ) );

			// Store only real overrides, so defaults can change later.
			if ( $color && MST_Sites::default_color( $id ) !== $color ) {
				$colors[ $id ] = $color;
			} else {
				unset( $colors[ $id ] );
			}
		}

		update_site_option( MST_Sites::COLORS_OPTION, $colors );

		wp_safe_redirect( add_query_arg( 'updated', 'true', $this->page_url( 'colors' ) ) );
		exit;
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
			'security'   => array(
				'label'       => __( 'Security & privacy', 'multisite-tools' ),
				'description' => __( 'Protections that apply to every site.', 'multisite-tools' ),
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

	/**
	 * @param string $tab Tab slug, or '' for the first tab.
	 * @return string
	 */
	private function page_url( $tab = '' ) {
		$url = network_admin_url( 'settings.php?page=' . self::PAGE );
		return $tab && 'modules' !== $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}
}
