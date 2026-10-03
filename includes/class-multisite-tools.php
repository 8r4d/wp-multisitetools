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
		'plugin-usage'    => 'MST_Plugin_Usage',
		'theme-usage'     => 'MST_Theme_Usage',
		'queuebar'        => 'MST_Queuebar',
		'default-author'  => 'MST_Default_Author',
		'copy-post'       => 'MST_Copy_Post',
		'calendar'        => 'MST_Calendar',
		'missed-schedule' => 'MST_Missed_Schedule',
		'social-graph'    => 'MST_Social_Graph',
		'hide-usernames'  => 'MST_Hide_Usernames',
	);

	/**
	 * Network option holding slug => bool. Modules missing from it are on.
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

		return ! isset( $enabled[ $slug ] ) || (bool) $enabled[ $slug ];
	}

	/**
	 * @param string $class Module class name.
	 * @return object|null
	 */
	public static function module( $class ) {
		return self::$modules[ $class ] ?? null;
	}
}
