<?php
/**
 * Helpers for modules that report on every site in the network.
 */

defined( 'ABSPATH' ) || exit;

final class MST_Sites {

	/**
	 * Sites per UNION query when querying many sites at once.
	 */
	const CHUNK_SIZE = 100;

	/**
	 * Network option holding custom site colours, as site ID => hex colour.
	 */
	const COLORS_OPTION = 'mst_site_colors';

	/**
	 * Default site colours, assigned by site ID so a site keeps its colour
	 * everywhere and for everyone.
	 */
	const PALETTE = array( '#2271b1', '#d63638', '#00a32a', '#dba617', '#8c5cc7', '#e26f2a', '#1aa3a3', '#c7307c', '#5b6e1f', '#646970' );

	/**
	 * Sites in the current network. By default every site, including
	 * archived, spam and deactivated ones.
	 *
	 * @param array $args Extra get_sites() arguments.
	 * @return WP_Site[]
	 */
	public static function all( $args = array() ) {
		return get_sites(
			array_merge(
				array(
					'network_id' => get_current_network_id(),
					'number'     => 0,
				),
				$args
			)
		);
	}

	/**
	 * Sites in the current network that are public-facing: not archived,
	 * spam or deactivated.
	 *
	 * @return WP_Site[]
	 */
	public static function active() {
		return self::all(
			array(
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);
	}

	/**
	 * Runs one SELECT per site, CHUNK_SIZE sites per UNION query, avoiding a
	 * switch_to_blog() per site. If a chunk's query fails, e.g. a site's
	 * tables are missing or live on another database server (HyperDB,
	 * LudicrousDB), its sites are queried one at a time and any that still
	 * fail are skipped.
	 *
	 * @param WP_Site[] $sites
	 * @param callable  $select Given a WP_Site, returns a prepared SELECT for
	 *                          that site's tables. Every site's SELECT must
	 *                          return the same columns.
	 * @return object[] Rows from every site.
	 */
	public static function query( $sites, $select ) {
		global $wpdb;

		$rows     = array();
		$suppress = $wpdb->suppress_errors();

		foreach ( array_chunk( $sites, self::CHUNK_SIZE ) as $chunk ) {
			$selects = array_map( $select, $chunk );

			// Parentheses let each SELECT have its own LIMIT.
			$result = $wpdb->get_results( '(' . implode( ') UNION ALL (', $selects ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( '' !== $wpdb->last_error ) {
				$result = array();
				foreach ( $selects as $sql ) {
					$site_rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( '' === $wpdb->last_error ) {
						$result = array_merge( $result, $site_rows );
					}
				}
			}

			$rows = array_merge( $rows, $result );
		}

		$wpdb->suppress_errors( $suppress );

		return $rows;
	}

	/**
	 * Reads the given options for many sites. Values are raw, so pass
	 * serialized ones through maybe_unserialize(). Missing options are left
	 * out.
	 *
	 * @param WP_Site[] $sites
	 * @param string[]  $names Option names.
	 * @return array<int, array<string, string>> Site ID => option name => raw value.
	 */
	public static function get_options( $sites, $names ) {
		global $wpdb;

		$in   = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$rows = self::query(
			$sites,
			function ( $site ) use ( $wpdb, $names, $in ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'options';
				return $wpdb->prepare(
					"SELECT %d AS blog_id, option_name, option_value FROM `{$table}` WHERE option_name IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $site->blog_id ), $names )
				);
			}
		);

		$options = array();
		foreach ( $rows as $row ) {
			$options[ (int) $row->blog_id ][ $row->option_name ] = $row->option_value;
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

	/**
	 * @param int $site_id
	 * @return string Hex colour.
	 */
	public static function default_color( $site_id ) {
		return self::PALETTE[ ( max( 1, (int) $site_id ) - 1 ) % count( self::PALETTE ) ];
	}

	/**
	 * The site's custom colour, or its default.
	 *
	 * @param int $site_id
	 * @return string Hex colour.
	 */
	public static function color( $site_id ) {
		$colors = (array) get_site_option( self::COLORS_OPTION, array() );
		$color  = sanitize_hex_color( $colors[ (int) $site_id ] ?? '' );

		return $color ? $color : self::default_color( $site_id );
	}

	/**
	 * A small square in the site's colour, to put before its name.
	 *
	 * @param int $site_id
	 * @return string HTML.
	 */
	public static function swatch( $site_id ) {
		return sprintf(
			'<span class="mst-swatch" style="display: inline-block; width: 10px; height: 10px; margin-right: 6px; border-radius: 2px; vertical-align: baseline; background: %s;" aria-hidden="true"></span>',
			esc_attr( self::color( $site_id ) )
		);
	}

	/**
	 * Drops a deleted site's custom colour.
	 *
	 * @param WP_Site $site
	 */
	public static function forget_color( $site ) {
		$colors = (array) get_site_option( self::COLORS_OPTION, array() );

		if ( isset( $colors[ (int) $site->blog_id ] ) ) {
			unset( $colors[ (int) $site->blog_id ] );
			update_site_option( self::COLORS_OPTION, $colors );
		}
	}
}
