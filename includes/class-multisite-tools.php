<?php
/**
 * Module loader.
 *
 * Each module is a self-contained class in includes/modules/ with a
 * register() method that hooks itself into WordPress, plus static label(),
 * description() and category() methods for the settings screen. Modules can
 * be switched on and off network-wide from Network Admin › Settings ›
 * Multisite Multitools.
 */

defined( 'ABSPATH' ) || exit;

final class Multisite_Tools {

	/**
	 * Module slug => class name. The class lives in
	 * includes/modules/class-mst-<slug>.php.
	 */
	const MODULES = array(
		'plugin-usage'        => 'MST_Plugin_Usage',
		'theme-usage'         => 'MST_Theme_Usage',
		'site-overview'       => 'MST_Site_Overview',
		'post-type-inventory' => 'MST_Post_Type_Inventory',
		'network-search'      => 'MST_Network_Search',
		'toolbar-colors'      => 'MST_Toolbar_Colors',
		'queuebar'            => 'MST_Queuebar',
		'default-author'      => 'MST_Default_Author',
		'copy-post'           => 'MST_Copy_Post',
		'calendar'            => 'MST_Calendar',
		'missed-schedule'     => 'MST_Missed_Schedule',
		'social-graph'        => 'MST_Social_Graph',
		'blocks'              => 'MST_Blocks',
		'hide-usernames'      => 'MST_Hide_Usernames',
		'disable-xmlrpc'      => 'MST_Disable_Xmlrpc',
		'disable-file-editor' => 'MST_Disable_File_Editor',
		'hide-wp-version'     => 'MST_Hide_Wp_Version',
	);

	/**
	 * Network option holding slug => bool. Modules missing from it use their
	 * default: on, unless the class has a static default_enabled() returning
	 * false.
	 */
	const OPTION = 'mst_modules';

	/** @var object[] */
	private static $modules = array();

	public static function boot() {
		require_once MST_DIR . 'includes/class-mst-settings.php';
		require_once MST_DIR . 'includes/class-mst-sites.php';

		add_action( 'wp_uninitialize_site', array( 'MST_Sites', 'forget_color' ) );

		// Load every module class so the settings screen can describe them,
		// but only register the enabled ones.
		foreach ( self::MODULES as $slug => $class ) {
			require_once MST_DIR . 'includes/modules/class-mst-' . $slug . '.php';

			if ( ! self::is_enabled( $slug ) ) {
				continue;
			}

			$module = new $class();
			$module->register();

			self::$modules[ $class ] = $module;
		}

		( new MST_Settings() )->register();
	}

	/**
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		$enabled = get_site_option( self::OPTION, array() );

		if ( isset( $enabled[ $slug ] ) ) {
			return (bool) $enabled[ $slug ];
		}

		$class = self::MODULES[ $slug ] ?? '';

		return ! method_exists( $class, 'default_enabled' ) || $class::default_enabled();
	}

	/**
	 * @param string $class Module class name.
	 * @return object|null
	 */
	public static function module( $class ) {
		return self::$modules[ $class ] ?? null;
	}
}
