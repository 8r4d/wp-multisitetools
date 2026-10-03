<?php
/**
 * Helpers for modules that report on every site in the network.
 */

defined( 'ABSPATH' ) || exit;

final class MST_Sites {

	/**
	 * Sites per UNION query when reading options straight from the database.
	 */
	const CHUNK_SIZE = 100;

	/**
	 * Every site in the current network, including archived, spam and
	 * deactivated ones.
	 *
	 * @return WP_Site[]
	 */
	public static function all() {
		return get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
			)
		);
	}

	/**
	 * Reads the given options for many sites, CHUNK_SIZE sites per query,
	 * avoiding a switch_to_blog() per site. Falls back to get_blog_option() for
	 * a chunk if its query fails, e.g. a site's tables are missing or live on
	 * another database server (HyperDB, LudicrousDB).
	 *
	 * Values may be serialized strings or, from the fallback, already
	 * unserialized, so pass them through maybe_unserialize().
	 *
	 * @param WP_Site[] $sites
	 * @param string[]  $names Option names.
	 * @return array<int, array<string, mixed>> Site ID => option name => value.
	 */
	public static function get_options( $sites, $names ) {
		$options = array();

		foreach ( array_chunk( $sites, self::CHUNK_SIZE ) as $chunk ) {
			$options += self::read_options( $chunk, $names );
		}

		return $options;
	}

	/**
	 * @param WP_Site[] $sites
	 * @param string[]  $names
	 * @return array<int, array<string, mixed>>
	 */
	private static function read_options( $sites, $names ) {
		global $wpdb;

		$in      = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$selects = array();

		foreach ( $sites as $site ) {
			$table     = $wpdb->get_blog_prefix( $site->blog_id ) . 'options';
			$selects[] = $wpdb->prepare(
				"SELECT %d AS blog_id, option_name, option_value FROM `{$table}` WHERE option_name IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $site->blog_id ), $names )
			);
		}

		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( implode( ' UNION ALL ', $selects ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$failed   = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		$options = array();

		if ( ! $failed ) {
			foreach ( $rows as $row ) {
				$options[ (int) $row->blog_id ][ $row->option_name ] = $row->option_value;
			}
			return $options;
		}

		foreach ( $sites as $site ) {
			$id = (int) $site->blog_id;
			foreach ( $names as $name ) {
				// Leave missing options out, as the query does.
				$value = get_blog_option( $id, $name );
				if ( false !== $value ) {
					$options[ $id ][ $name ] = $value;
				}
			}
		}

		return $options;
	}

	/**
	 * @param WP_Site $site
	 * @return string Domain and path, without scheme or trailing slash.
	 */
	public static function url( $site ) {
		return untrailingslashit( $site->domain . $site->path );
	}

	/**
	 * @param WP_Site $site
	 * @param string  $path Path relative to the site's wp-admin/.
	 * @return string
	 */
	public static function admin_url( $site, $path = '' ) {
		return set_url_scheme( 'http://' . $site->domain . $site->path . 'wp-admin/' . ltrim( $path, '/' ), 'admin' );
	}

	/**
	 * @param WP_Site $site
	 * @return string Comma-separated status labels, or '' for a normal public site.
	 */
	public static function status( $site ) {
		$labels = array();

		if ( (int) $site->archived ) {
			$labels[] = __( 'archived', 'multisite-tools' );
		}
		if ( (int) $site->spam ) {
			$labels[] = __( 'spam', 'multisite-tools' );
		}
		if ( (int) $site->deleted ) {
			$labels[] = __( 'deactivated', 'multisite-tools' );
		}

		return implode( ', ', $labels );
	}
}
