<?php
/**
 * Tag alignment: compares post tags across sites under Network Admin › Sites
 * › Tags, so they can be brought into line by hand. Related posts matches
 * tags across sites by exact slug, so aligned tags give it more to match.
 *
 * Every tag links to its own site's Edit Tag screen and Posts list, where
 * renames are made by hand. The changes made here are copying a tag that
 * only one site has to another site, and merging variants into one tag.
 */

defined( 'ABSPATH' ) || exit;

class MST_Tag_Alignment {

	const PAGE         = 'mst-tags';
	const COPY_ACTION  = 'mst_copy_tag';
	const MERGE_ACTION = 'mst_merge_tags';

	/**
	 * Rows per page in each view.
	 */
	const PER_PAGE = 100;

	/**
	 * Shortest normalised tag compared with others for a one-letter
	 * difference. Shorter words differ by a letter too often to mean
	 * anything (cats, bats).
	 */
	const TYPO_MIN_LENGTH = 8;

	/**
	 * Query arguments of the current view, for building links.
	 *
	 * @var array
	 */
	private $query = array();

	public static function label() {
		return __( 'Tag alignment', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Network Admin › Sites › Tags: compares post tags across sites, listing the tags sites share, likely variants of the same tag (plurals, hyphens, typos), tags only one site uses and unused tags, each linked to its site\'s Edit Tag screen.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	public function register() {
		if ( is_network_admin() ) {
			add_action( 'network_admin_menu', array( $this, 'add_page' ) );
			add_action( 'network_admin_edit_' . self::COPY_ACTION, array( $this, 'handle_copy' ) );
			add_action( 'network_admin_edit_' . self::MERGE_ACTION, array( $this, 'handle_merge' ) );
		}
	}

	public function add_page() {
		add_submenu_page( 'sites.php', __( 'Tags', 'multisite-tools' ), __( 'Tags', 'multisite-tools' ), 'manage_network', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * @return array<string, string> View slug => label.
	 */
	private static function views() {
		return array(
			'shared'   => __( 'Shared', 'multisite-tools' ),
			'variants' => __( 'Variants', 'multisite-tools' ),
			'single'   => __( 'One site only', 'multisite-tools' ),
			'unused'   => __( 'Unused', 'multisite-tools' ),
		);
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$view   = sanitize_key( wp_unslash( $_GET['view'] ?? 'shared' ) );
		$picked = array_map( 'absint', (array) wp_unslash( $_GET['sites'] ?? array() ) );
		$search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$merge  = array_map( 'strval', (array) wp_unslash( $_GET['tags'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only used to look up existing slugs.
		// phpcs:enable

		$views = self::views();
		$view  = isset( $views[ $view ] ) || 'merge' === $view ? $view : 'shared';

		$all      = MST_Sites::details( MST_Sites::active() );
		$selected = array_intersect_key( $all, array_flip( $picked ) );
		$selected = $selected ? $selected : $all;

		$this->query = array(
			'page'  => self::PAGE,
			'view'  => $view,
			'sites' => count( $selected ) < count( $all ) ? array_keys( $selected ) : false,
			's'     => '' !== $search ? rawurlencode( $search ) : false,
		);

		$tags = $this->get_tags( $selected );

		if ( 'merge' === $view ) {
			$this->render_merge( $tags, $merge, $all );
			return;
		}

		$lists = array(
			'shared'   => $this->shared( $tags ),
			'variants' => $this->variants( $tags ),
			'single'   => $this->single( $tags ),
			'unused'   => $this->unused( $tags ),
		);

		if ( '' !== $search ) {
			foreach ( $lists as &$list ) {
				$list = array_values(
					array_filter(
						$list,
						function ( $item ) use ( $search ) {
							return $this->matches( $item, $search );
						}
					)
				);
			}
			unset( $list );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tags', 'multisite-tools' ); ?></h1>
			<?php
			$this->print_styles();
			$this->render_copy_notice( $all );
			$this->render_merge_notice();
			$this->render_filters( $all, $selected, $search, $view );
			?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $views as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->url( array( 'view' => $slug ) ) ); ?>" class="nav-tab<?php echo $slug === $view ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( count( $lists[ $slug ] ) ) ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			$this->render_intro( $view );

			if ( in_array( $view, array( 'shared', 'single' ), true ) && count( $selected ) < 2 ) {
				echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Choose at least two sites to compare.', 'multisite-tools' ) . '</p></div>';
			} elseif ( ! $lists[ $view ] ) {
				echo '<p>' . esc_html( '' !== $search ? __( 'No tags match your search.', 'multisite-tools' ) : __( 'No tags found.', 'multisite-tools' ) ) . '</p>';
			} else {
				$this->render_table( $lists[ $view ], $view, $paged, $all, $selected );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The sites to compare and a search box.
	 *
	 * @param array<int, array> $all      Every active site's details.
	 * @param array<int, array> $selected The sites being compared.
	 * @param string            $search
	 * @param string            $view
	 */
	private function render_filters( $all, $selected, $search, $view ) {
		$summary = count( $selected ) === count( $all )
			/* translators: %s: number of sites */
			? sprintf( _n( 'Comparing %s site', 'Comparing all %s sites', count( $all ), 'multisite-tools' ), number_format_i18n( count( $all ) ) )
			/* translators: 1: number of sites compared, 2: number of sites */
			: sprintf( __( 'Comparing %1$s of %2$s sites', 'multisite-tools' ), number_format_i18n( count( $selected ) ), number_format_i18n( count( $all ) ) );
		?>
		<form method="get" action="<?php echo esc_url( network_admin_url( 'sites.php' ) ); ?>" class="mst-tags-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">

			<details class="mst-sites">
				<summary><?php echo esc_html( $summary ); ?></summary>
				<p class="description"><?php esc_html_e( 'Tick the sites to compare. With none ticked, every site is compared.', 'multisite-tools' ); ?></p>
				<ul>
					<?php foreach ( $all as $id => $site ) : ?>
						<li>
							<label>
								<input type="checkbox" name="sites[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( count( $selected ) < count( $all ) && isset( $selected[ $id ] ) ); ?>>
								<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo esc_html( $site['name'] ); ?>
								<span class="mst-muted"><?php echo esc_html( $site['url'] ); ?></span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>

			<p>
				<label class="screen-reader-text" for="mst-tags-search"><?php esc_html_e( 'Search tags', 'multisite-tools' ); ?></label>
				<input type="search" id="mst-tags-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Tag name or slug', 'multisite-tools' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Apply', 'multisite-tools' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @param string $view
	 */
	private function render_intro( $view ) {
		$intros = array(
			'shared'   => __( 'Tags used on two or more of the chosen sites, matched by slug as Related posts matches them. Fill gaps by adding a tag to the sites it\'s missing from, and make the names match where they differ.', 'multisite-tools' ),
			'variants' => __( 'Tags that are probably the same but have different slugs, on one site or across sites. "Same words" means they differ only in plurals, hyphens, "and" or a -2 suffix; "Similar spelling" means one letter apart. Merge… folds a group into the one slug you keep, on every compared site.', 'multisite-tools' ),
			'single'   => __( 'Tags only one of the chosen sites uses. Well-used ones may be worth adding to other sites; rarely used ones may be worth removing.', 'multisite-tools' ),
			'unused'   => __( 'Tags with no published posts, including tags used only on drafts or scheduled posts. They\'re candidates for deleting.', 'multisite-tools' ),
		);

		echo '<p>' . esc_html( $intros[ $view ] ) . '</p>';
	}

	/**
	 * @param array[]           $items    Groups of tags, each with a 'slugs' map of slug => site ID => term.
	 * @param string            $view
	 * @param int               $paged
	 * @param array<int, array> $all      Every active site's details.
	 * @param array<int, array> $selected The sites being compared.
	 */
	private function render_table( $items, $view, $paged, $all, $selected ) {
		$pages = (int) ceil( count( $items ) / self::PER_PAGE );
		$paged = min( $paged, $pages );
		$items = array_slice( $items, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$notes = in_array( $view, array( 'shared', 'variants' ), true );
		$copy  = 'single' === $view;

		$this->render_pagination( $paged, $pages );
		?>
		<table class="widefat mst-tags mst-tags-report">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Tag', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sites', 'multisite-tools' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Posts', 'multisite-tools' ); ?></th>
					<?php if ( $notes ) : ?>
						<th scope="col"><?php esc_html_e( 'Notes', 'multisite-tools' ); ?></th>
					<?php endif; ?>
					<?php if ( $copy ) : ?>
						<th scope="col"><?php esc_html_e( 'Copy to', 'multisite-tools' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<?php foreach ( $items as $item ) : ?>
				<tbody>
					<?php
					$first = true;
					foreach ( $item['slugs'] as $slug => $terms ) :
						$name = reset( $terms )->name;
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $name ); ?></strong><br>
								<code><?php echo esc_html( rawurldecode( $slug ) ); ?></code>
							</td>
							<td><?php $this->render_terms( $slug, $terms, $name, $all ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( $this->total( $terms ) ) ); ?></td>
							<?php if ( $notes ) : ?>
								<td>
									<?php
									if ( 'shared' === $view ) {
										$this->render_shared_notes( $terms, $name, $selected );
									} elseif ( $first ) {
										$this->render_variant_notes( $item );
									}
									?>
								</td>
							<?php endif; ?>
							<?php if ( $copy ) : ?>
								<td><?php $this->render_copy_form( $terms, $selected ); ?></td>
							<?php endif; ?>
						</tr>
						<?php
						$first = false;
					endforeach;
					?>
				</tbody>
			<?php endforeach; ?>
		</table>
		<?php
		$this->render_pagination( $paged, $pages );
	}

	/**
	 * Each site's copy of a tag, with its post count and an Edit link.
	 *
	 * @param string            $slug
	 * @param object[]          $terms Site ID => term.
	 * @param string            $name  The name most posts use, to flag others against.
	 * @param array<int, array> $all   Every active site's details.
	 */
	private function render_terms( $slug, $terms, $name, $all ) {
		echo '<ul class="mst-tag-sites">';

		foreach ( $terms as $site_id => $term ) {
			$admin = $all[ $site_id ]['admin_url'];
			$count = $term->count
				? sprintf( '<a href="%s">%s</a>', esc_url( $admin . 'edit.php?tag=' . $slug ), esc_html( number_format_i18n( $term->count ) ) )
				: '0';

			printf(
				'<li>%s%s <span class="mst-muted">(%s)</span>%s <a href="%s" class="mst-edit">%s</a></li>',
				MST_Sites::swatch( $site_id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( $all[ $site_id ]['name'] ),
				$count, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$term->name !== $name ? ' <span class="mst-flag mst-flag-name">' . esc_html( $term->name ) . '</span>' : '',
				esc_url( $admin . 'term.php?taxonomy=post_tag&tag_ID=' . $term->term_id ),
				esc_html__( 'Edit', 'multisite-tools' )
			);
		}

		echo '</ul>';
	}

	/**
	 * @param object[]          $terms    Site ID => term.
	 * @param string            $name
	 * @param array<int, array> $selected The sites being compared.
	 */
	private function render_shared_notes( $terms, $name, $selected ) {
		if ( count( array_unique( wp_list_pluck( $terms, 'name' ) ) ) > 1 ) {
			echo '<span class="mst-flag mst-flag-differ">' . esc_html__( 'Names differ', 'multisite-tools' ) . '</span>';
		}

		$missing = array_diff_key( $selected, $terms );
		if ( ! $missing ) {
			return;
		}

		/* translators: %s: number of sites */
		$label = sprintf( _n( 'Not on %s site', 'Not on %s sites', count( $missing ), 'multisite-tools' ), number_format_i18n( count( $missing ) ) );

		echo '<details class="mst-sites"><summary>' . esc_html( $label ) . '</summary><ul>';
		foreach ( $missing as $site_id => $site ) {
			// Search the site's tags by name, in case it has a variant.
			printf(
				'<li>%s<a href="%s" title="%s">%s</a></li>',
				MST_Sites::swatch( $site_id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_url( $site['admin_url'] . 'edit-tags.php?taxonomy=post_tag&s=' . rawurlencode( $name ) ),
				esc_attr__( 'Search this site\'s tags', 'multisite-tools' ),
				esc_html( $site['name'] )
			);
		}
		echo '</ul></details>';
	}

	/**
	 * @param array $item A variant group.
	 */
	private function render_variant_notes( $item ) {
		$labels = array(
			'words'    => __( 'Same words', 'multisite-tools' ),
			'spelling' => __( 'Similar spelling', 'multisite-tools' ),
		);

		echo '<span class="mst-flag mst-flag-' . esc_attr( $item['reason'] ) . '">' . esc_html( $labels[ $item['reason'] ] ) . '</span> ';

		$totals = array_map( array( $this, 'total' ), $item['slugs'] );
		if ( reset( $totals ) > next( $totals ) ) {
			echo '<span class="mst-flag mst-flag-most">' . esc_html__( 'Top row is most used', 'multisite-tools' ) . '</span>';
		}

		printf(
			'<br><a href="%s" class="button button-small">%s</a>',
			esc_url( $this->url( array( 'view' => 'merge', 'tags' => array_map( 'rawurlencode', array_map( 'strval', array_keys( $item['slugs'] ) ) ) ) ) ),
			esc_html__( 'Merge…', 'multisite-tools' )
		);
	}

	/**
	 * Confirmation screen for merging a group of variants: choose the slug
	 * to keep, see each site's tags, then merge.
	 *
	 * @param array<string, object[]> $tags  Every tag on the compared sites.
	 * @param string[]                $slugs The group to merge.
	 * @param array<int, array>       $all   Every active site's details.
	 */
	private function render_merge( $tags, $slugs, $all ) {
		$group = array_intersect_key( $tags, array_flip( $slugs ) );
		$back  = $this->url( array( 'view' => 'variants' ) );

		uasort(
			$group,
			function ( $a, $b ) {
				return $this->total( $b ) <=> $this->total( $a );
			}
		);

		$sites = array();
		foreach ( $group as $slug => $terms ) {
			foreach ( $terms as $site_id => $term ) {
				$sites[ $site_id ][ $slug ] = $term;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Merge Tags', 'multisite-tools' ); ?></h1>
			<?php $this->print_styles(); ?>

			<?php if ( count( $group ) < 2 ) : ?>
				<p><?php esc_html_e( 'These tags no longer exist, or there\'s only one of them left on the compared sites.', 'multisite-tools' ); ?></p>
				<p><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← Back to Variants', 'multisite-tools' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::MERGE_ACTION ) ); ?>">
					<?php wp_nonce_field( self::MERGE_ACTION ); ?>
					<?php foreach ( array_keys( $sites ) as $site_id ) : ?>
						<input type="hidden" name="sites[]" value="<?php echo esc_attr( $site_id ); ?>">
					<?php endforeach; ?>

					<h2><?php esc_html_e( 'Tag to keep', 'multisite-tools' ); ?></h2>
					<fieldset>
						<?php foreach ( $group as $slug => $terms ) : ?>
							<input type="hidden" name="tags[]" value="<?php echo esc_attr( $slug ); ?>">
							<p>
								<label>
									<input type="radio" name="keep" value="<?php echo esc_attr( $slug ); ?>" <?php checked( array_key_first( $group ), $slug ); ?>>
									<strong><?php echo esc_html( reset( $terms )->name ); ?></strong>
									<code><?php echo esc_html( rawurldecode( $slug ) ); ?></code>
									<span class="mst-muted">
										<?php
										/* translators: 1: number of posts, 2: number of sites */
										echo esc_html( sprintf( __( '%1$s posts on %2$s sites', 'multisite-tools' ), number_format_i18n( $this->total( $terms ) ), number_format_i18n( count( $terms ) ) ) );
										?>
									</span>
								</label>
							</p>
						<?php endforeach; ?>
					</fieldset>

					<h2><?php esc_html_e( 'Sites affected', 'multisite-tools' ); ?></h2>
					<table class="widefat striped mst-tags">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Tags in this group', 'multisite-tools' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sites as $site_id => $terms ) : ?>
								<tr>
									<td><?php echo MST_Sites::swatch( $site_id ) . esc_html( $all[ $site_id ]['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
									<td>
										<ul class="mst-tag-sites">
											<?php foreach ( $terms as $slug => $term ) : ?>
												<li>
													<?php echo esc_html( $term->name ); ?>
													<code><?php echo esc_html( rawurldecode( $slug ) ); ?></code>
													<span class="mst-muted">
														<?php
														/* translators: %s: number of posts */
														echo esc_html( sprintf( _n( '(%s post)', '(%s posts)', $term->count, 'multisite-tools' ), number_format_i18n( $term->count ) ) );
														?>
													</span>
												</li>
											<?php endforeach; ?>
										</ul>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'On each site, posts with any of the other tags get the kept tag instead, and the other tags are deleted. A site without the kept tag gets it by renaming its most used tag in the group, which keeps that tag\'s description. Archive links for the deleted slugs (e.g. /tag/old-slug/) will stop working. There\'s no undo.', 'multisite-tools' ); ?></p>
					</div>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Merge tags', 'multisite-tools' ); ?></button>
						<a href="<?php echo esc_url( $back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'multisite-tools' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The result of a merge, after handle_merge() redirects back.
	 */
	private function render_merge_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['merged'] ) ) {
			return;
		}

		$merged = absint( $_GET['merged'] );
		$sites  = absint( $_GET['merged_sites'] ?? 0 );
		$failed = absint( $_GET['merge_failed'] ?? 0 );
		// phpcs:enable

		if ( $merged ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: number of tags, 2: number of sites */
						_n( 'Merged %1$s tag on %2$s sites.', 'Merged %1$s tags on %2$s sites.', $merged, 'multisite-tools' ),
						number_format_i18n( $merged ),
						number_format_i18n( $sites )
					)
				)
			);
		}

		if ( $failed ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of tags */
						_n( '%s tag couldn\'t be merged. Check it on its site\'s Tags screen.', '%s tags couldn\'t be merged. Check them on their sites\' Tags screens.', $failed, 'multisite-tools' ),
						number_format_i18n( $failed )
					)
				)
			);
		} elseif ( ! $merged ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Nothing was merged: the tags no longer exist on those sites.', 'multisite-tools' ) . '</p></div>';
		}
	}

	/**
	 * Merges a group of tags into the kept slug on each of the given sites,
	 * then returns to the Variants view.
	 */
	public function handle_merge() {
		check_admin_referer( self::MERGE_ACTION );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'multisite-tools' ), 403 );
		}

		$keep   = (string) wp_unslash( $_POST['keep'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- looked up as a slug.
		$slugs  = array_map( 'strval', (array) wp_unslash( $_POST['tags'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$posted = array_map( 'absint', (array) wp_unslash( $_POST['sites'] ?? array() ) );
		$active = array_map( 'intval', wp_list_pluck( MST_Sites::active(), 'blog_id' ) );
		$sites  = array_intersect( $posted, $active );

		if ( ! in_array( $keep, $slugs, true ) ) {
			wp_die( esc_html__( 'Choose the tag to keep.', 'multisite-tools' ) );
		}

		// Find each site's tags first, so a site without the kept tag can be
		// given the name the other sites use.
		$found = array();
		$names = array();
		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, 'post_tag' );
				if ( $term instanceof WP_Term ) {
					$found[ $site_id ][ $slug ] = $term;
					if ( $slug === $keep ) {
						$names[ $term->name ] = ( $names[ $term->name ] ?? 0 ) + $term->count;
					}
				}
			}
			restore_current_blog();
		}

		arsort( $names );
		$name   = $names ? (string) array_key_first( $names ) : null;
		$merged = 0;
		$failed = 0;
		$done   = 0;

		foreach ( $found as $site_id => $terms ) {
			if ( count( $terms ) < 2 && isset( $terms[ $keep ] ) ) {
				continue;
			}

			switch_to_blog( $site_id );

			$kept = $terms[ $keep ] ?? null;
			unset( $terms[ $keep ] );

			if ( ! $kept ) {
				// Rename the most used variant to the kept slug.
				uasort(
					$terms,
					function ( $a, $b ) {
						return $b->count <=> $a->count;
					}
				);
				$kept = array_shift( $terms );
				$args = array( 'slug' => $keep );
				if ( null !== $name ) {
					$args['name'] = $name;
				}
				$renamed = wp_update_term( $kept->term_id, 'post_tag', wp_slash( $args ) );
				if ( is_wp_error( $renamed ) ) {
					$failed += 1 + count( $terms );
					restore_current_blog();
					continue;
				}
				++$merged;
			}

			foreach ( $terms as $term ) {
				// Gives every post with the old tag the kept one, then deletes it.
				$deleted = wp_delete_term(
					$term->term_id,
					'post_tag',
					array(
						'default'       => $kept->term_id,
						'force_default' => true,
					)
				);
				if ( true === $deleted ) {
					++$merged;
				} else {
					++$failed;
				}
			}

			restore_current_blog();
			++$done;
		}

		// Related posts and Network digest show tags, and term changes don't
		// fire the post hooks that clear them.
		foreach ( array( 'MST_Related_Posts', 'MST_Network_Digest' ) as $class ) {
			$module = Multisite_Tools::module( $class );
			if ( $module ) {
				$module->flush();
			}
		}

		$back = wp_get_referer();
		$back = $back ? $back : network_admin_url( 'sites.php?page=' . self::PAGE );

		wp_safe_redirect(
			add_query_arg(
				array(
					'view'         => 'variants',
					'merged'       => $merged,
					'merged_sites' => $done,
					'merge_failed' => $failed,
				),
				remove_query_arg( array( 'tags', 'merged', 'merged_sites', 'merge_failed', 'paged' ), $back )
			)
		);
		exit;
	}

	/**
	 * A form to copy a one-site tag to another of the compared sites.
	 *
	 * @param object[]          $terms    Site ID => term, with one site.
	 * @param array<int, array> $selected The sites being compared.
	 */
	private function render_copy_form( $terms, $selected ) {
		$targets = array_diff_key( $selected, $terms );
		?>
		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::COPY_ACTION ) ); ?>" class="mst-copy-tag">
			<?php wp_nonce_field( self::COPY_ACTION ); ?>
			<input type="hidden" name="source" value="<?php echo esc_attr( key( $terms ) ); ?>">
			<input type="hidden" name="term" value="<?php echo esc_attr( reset( $terms )->term_id ); ?>">
			<label class="screen-reader-text" for="mst-copy-<?php echo esc_attr( key( $terms ) . '-' . reset( $terms )->term_id ); ?>"><?php esc_html_e( 'Site to copy the tag to', 'multisite-tools' ); ?></label>
			<select name="target" id="mst-copy-<?php echo esc_attr( key( $terms ) . '-' . reset( $terms )->term_id ); ?>">
				<?php foreach ( $targets as $id => $site ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $site['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-small"><?php esc_html_e( 'Copy', 'multisite-tools' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The result of copying a tag, after handle_copy() redirects back.
	 *
	 * @param array<int, array> $all Every active site's details.
	 */
	private function render_copy_notice( $all ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$copied  = ! empty( $_GET['copied'] );
		$error   = sanitize_key( wp_unslash( $_GET['copy_error'] ?? '' ) );
		$site_id = absint( $_GET['site'] ?? 0 );
		$term_id = absint( $_GET['term'] ?? 0 );
		// phpcs:enable

		if ( ! $copied && '' === $error ) {
			return;
		}

		$site = $all[ $site_id ] ?? null;
		$edit = $site && $term_id
			? sprintf( ' <a href="%s">%s</a>', esc_url( $site['admin_url'] . 'term.php?taxonomy=post_tag&tag_ID=' . $term_id ), esc_html__( 'Edit tag', 'multisite-tools' ) )
			: '';

		if ( $copied && $site ) {
			$class = 'success';
			/* translators: %s: site name */
			$message = sprintf( __( 'Tag copied to %s.', 'multisite-tools' ), $site['name'] );
		} elseif ( 'exists' === $error && $site ) {
			$class = 'warning';
			/* translators: %s: site name */
			$message = sprintf( __( '%s already has a tag with that name or slug, so nothing was copied.', 'multisite-tools' ), $site['name'] );
		} elseif ( 'missing' === $error ) {
			$class   = 'error';
			$message = __( 'That tag no longer exists on its site.', 'multisite-tools' );
		} elseif ( 'site' === $error ) {
			$class   = 'error';
			$message = __( 'Choose another active site to copy the tag to.', 'multisite-tools' );
		} else {
			$class   = 'error';
			$message = __( 'The tag couldn\'t be copied.', 'multisite-tools' );
		}

		printf( '<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>', esc_attr( $class ), esc_html( $message ), $edit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Copies a tag's name, slug and description from one site to another,
	 * unless the target already has a tag with that name or slug, then
	 * returns to the report.
	 */
	public function handle_copy() {
		check_admin_referer( self::COPY_ACTION );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'multisite-tools' ), 403 );
		}

		$source  = absint( $_POST['source'] ?? 0 );
		$target  = absint( $_POST['target'] ?? 0 );
		$term_id = absint( $_POST['term'] ?? 0 );
		$active  = array_map( 'intval', wp_list_pluck( MST_Sites::active(), 'blog_id' ) );
		$result  = array( 'site' => $target );

		if ( $source === $target || ! in_array( $source, $active, true ) || ! in_array( $target, $active, true ) ) {
			$result['copy_error'] = 'site';
		} else {
			switch_to_blog( $source );
			$term = get_term( $term_id, 'post_tag' );
			restore_current_blog();

			if ( ! $term instanceof WP_Term ) {
				$result['copy_error'] = 'missing';
			} else {
				switch_to_blog( $target );
				$existing = get_term_by( 'slug', $term->slug, 'post_tag' );
				$inserted = $existing ? null : wp_insert_term(
					$term->name,
					'post_tag',
					array(
						'slug'        => $term->slug,
						'description' => $term->description,
					)
				);
				restore_current_blog();

				if ( $existing ) {
					$result['copy_error'] = 'exists';
					$result['term']       = $existing->term_id;
				} elseif ( is_wp_error( $inserted ) ) {
					// A tag with the same name but another slug: the error's data is its ID.
					$result['copy_error'] = 'term_exists' === $inserted->get_error_code() ? 'exists' : 'failed';
					$result['term']       = absint( $inserted->get_error_data() );
				} else {
					$result['copied'] = 1;
					$result['term']   = $inserted['term_id'];
				}
			}
		}

		$back = wp_get_referer();
		$back = $back ? $back : network_admin_url( 'sites.php?page=' . self::PAGE . '&view=single' );

		wp_safe_redirect( add_query_arg( $result, remove_query_arg( array( 'copied', 'copy_error', 'site', 'term' ), $back ) ) );
		exit;
	}

	/**
	 * @param int $paged
	 * @param int $pages
	 */
	private function render_pagination( $paged, $pages ) {
		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'    => add_query_arg( 'paged', '%#%', $this->url() ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}

	/**
	 * @param array $args Query arguments to change.
	 * @return string The current view's URL.
	 */
	private function url( $args = array() ) {
		return add_query_arg( array_merge( $this->query, $args ), network_admin_url( 'sites.php' ) );
	}

	/**
	 * Every post tag on the given sites, with one UNION query per 100 sites.
	 *
	 * @param array<int, array> $sites Site details.
	 * @return array<string, object[]> Slug => site ID => term (term_id, name,
	 *                                 count), the most used first.
	 */
	private function get_tags( $sites ) {
		global $wpdb;

		$rows = MST_Sites::query(
			wp_list_pluck( $sites, 'site' ),
			function ( $site ) use ( $wpdb ) {
				$prefix = $wpdb->get_blog_prefix( $site->blog_id );
				return $wpdb->prepare(
					"SELECT %d AS blog_id, t.term_id, t.slug, t.name, tt.count
					FROM `{$prefix}terms` t
					INNER JOIN `{$prefix}term_taxonomy` tt ON tt.term_id = t.term_id
					WHERE tt.taxonomy = 'post_tag'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id
				);
			}
		);

		$tags = array();
		foreach ( $rows as $row ) {
			$tags[ (string) $row->slug ][ (int) $row->blog_id ] = (object) array(
				'term_id' => (int) $row->term_id,
				// Names are stored with HTML entities, e.g. &amp;.
				'name'    => html_entity_decode( $row->name, ENT_QUOTES, 'UTF-8' ),
				'count'   => (int) $row->count,
			);
		}

		foreach ( $tags as &$terms ) {
			uasort(
				$terms,
				function ( $a, $b ) {
					return $b->count <=> $a->count;
				}
			);
		}
		unset( $terms );

		return $tags;
	}

	/**
	 * Tags on two or more sites, the most widespread first.
	 *
	 * @param array<string, object[]> $tags
	 * @return array[]
	 */
	private function shared( $tags ) {
		$items = array();
		foreach ( $tags as $slug => $terms ) {
			if ( count( $terms ) > 1 ) {
				$items[] = array( 'slugs' => array( $slug => $terms ) );
			}
		}

		usort(
			$items,
			function ( $a, $b ) {
				$a = reset( $a['slugs'] );
				$b = reset( $b['slugs'] );
				return array( count( $b ), $this->total( $b ) ) <=> array( count( $a ), $this->total( $a ) );
			}
		);

		return $items;
	}

	/**
	 * Groups of tags with different slugs that are probably the same tag:
	 * equal once normalised (see variant_key()), or one letter apart.
	 *
	 * @param array<string, object[]> $tags
	 * @return array[] Each with 'reason' ('words' or 'spelling') and 'slugs',
	 *                 the most used first. Most used groups first.
	 */
	private function variants( $tags ) {
		$keys = array();
		foreach ( $tags as $slug => $terms ) {
			$keys[ $this->variant_key( (string) $slug, reset( $terms )->name ) ][] = (string) $slug;
		}

		// Join keys one letter apart, comparing keys with the same first
		// letter, sorted by length so the inner loop can stop early.
		$parent = array();
		foreach ( array_keys( $keys ) as $key ) {
			$parent[ (string) $key ] = (string) $key;
		}

		$find = function ( $key ) use ( &$parent ) {
			while ( $parent[ $key ] !== $key ) {
				$parent[ $key ] = $parent[ $parent[ $key ] ];
				$key            = $parent[ $key ];
			}
			return $key;
		};

		$buckets = array();
		foreach ( $parent as $key ) {
			if ( strlen( $key ) >= self::TYPO_MIN_LENGTH ) {
				$buckets[ $key[0] ][] = $key;
			}
		}

		foreach ( $buckets as $bucket ) {
			usort(
				$bucket,
				function ( $a, $b ) {
					return strlen( $a ) <=> strlen( $b );
				}
			);

			$size = count( $bucket );
			for ( $i = 0; $i < $size; $i++ ) {
				for ( $j = $i + 1; $j < $size && strlen( $bucket[ $j ] ) - strlen( $bucket[ $i ] ) <= 1; $j++ ) {
					if ( 1 === levenshtein( $bucket[ $i ], $bucket[ $j ] ) ) {
						$parent[ $find( $bucket[ $i ] ) ] = $find( $bucket[ $j ] );
					}
				}
			}
		}

		$groups = array();
		foreach ( $keys as $key => $slugs ) {
			$root                      = $find( (string) $key );
			$groups[ $root ]['keys'][] = $key;
			foreach ( $slugs as $slug ) {
				$groups[ $root ]['slugs'][ $slug ] = $tags[ $slug ];
			}
		}

		$items = array();
		foreach ( $groups as $group ) {
			if ( count( $group['slugs'] ) < 2 ) {
				continue;
			}

			uasort(
				$group['slugs'],
				function ( $a, $b ) {
					return $this->total( $b ) <=> $this->total( $a );
				}
			);

			$items[] = array(
				'reason' => count( $group['keys'] ) > 1 ? 'spelling' : 'words',
				'slugs'  => $group['slugs'],
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return array_sum( array_map( array( $this, 'total' ), $b['slugs'] ) ) <=> array_sum( array_map( array( $this, 'total' ), $a['slugs'] ) );
			}
		);

		return $items;
	}

	/**
	 * Tags on only one site, the most used first.
	 *
	 * @param array<string, object[]> $tags
	 * @return array[]
	 */
	private function single( $tags ) {
		$items = array();
		foreach ( $tags as $slug => $terms ) {
			if ( 1 === count( $terms ) ) {
				$items[] = array( 'slugs' => array( $slug => $terms ) );
			}
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $this->total( reset( $b['slugs'] ) ) <=> $this->total( reset( $a['slugs'] ) );
			}
		);

		return $items;
	}

	/**
	 * Tags with no published posts, by slug, listing only the sites where
	 * they're unused.
	 *
	 * @param array<string, object[]> $tags
	 * @return array[]
	 */
	private function unused( $tags ) {
		$items = array();
		foreach ( $tags as $slug => $terms ) {
			$terms = array_filter(
				$terms,
				function ( $term ) {
					return 0 === $term->count;
				}
			);
			if ( $terms ) {
				$items[ rawurldecode( $slug ) ] = array( 'slugs' => array( $slug => $terms ) );
			}
		}

		ksort( $items );

		return array_values( $items );
	}

	/**
	 * A tag's slug reduced to what it means: lowercase, without separators,
	 * "and" or WordPress's -2, -3… suffix for duplicate slugs, and with each
	 * word made singular. So "photos", "photo-2" and "Photo" match, as do
	 * "e-mail" and "email", and "rock-roll" and "rock-and-roll".
	 *
	 * @param string $slug
	 * @param string $name The tag's name, to tell a -2 suffix from a number
	 *                     that's part of the tag (covid-19).
	 * @return string
	 */
	private function variant_key( $slug, $name ) {
		$key = strtolower( rawurldecode( $slug ) );

		if ( preg_match( '/-(\d+)$/', $key, $match ) && false === strpos( $name, $match[1] ) ) {
			$key = substr( $key, 0, -strlen( $match[0] ) );
		}

		$words = preg_split( '/[^\p{L}\p{N}]+/u', $key, -1, PREG_SPLIT_NO_EMPTY );
		$words = array_diff( $words, array( 'and' ) );
		$key   = implode( '', array_map( array( $this, 'singular' ), $words ) );

		return '' !== $key ? $key : $slug;
	}

	/**
	 * A rough English singular: enough to match "stories" with "story" and
	 * "boxes" with "box", not to be correct for every word.
	 *
	 * @param string $word
	 * @return string
	 */
	private function singular( $word ) {
		if ( strlen( $word ) <= 3 || preg_match( '/(ss|us|is)$/', $word ) ) {
			return $word;
		}
		if ( preg_match( '/ies$/', $word ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( preg_match( '/(s|x|z|ch|sh)es$/', $word ) ) {
			return substr( $word, 0, -2 );
		}
		if ( 's' === substr( $word, -1 ) ) {
			return substr( $word, 0, -1 );
		}
		return $word;
	}

	/**
	 * @param object[] $terms Site ID => term.
	 * @return int Published posts across the sites.
	 */
	private function total( $terms ) {
		return array_sum( wp_list_pluck( $terms, 'count' ) );
	}

	/**
	 * @param array  $item
	 * @param string $search
	 * @return bool Whether any slug or name in the group contains the search.
	 */
	private function matches( $item, $search ) {
		foreach ( $item['slugs'] as $slug => $terms ) {
			if ( false !== stripos( rawurldecode( $slug ), $search ) ) {
				return true;
			}
			foreach ( $terms as $term ) {
				if ( false !== stripos( $term->name, $search ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function print_styles() {
		?>
		<style>
			.mst-tags-filter { margin: 1em 0; }
			.mst-tags-filter ul { columns: 3 220px; margin: 8px 0; }
			.mst-tags td, .mst-tags th { vertical-align: top; }
			.mst-tags .num { text-align: right; }
			.mst-tags code { background: none; padding: 0; }
			.mst-tags-report tbody:nth-of-type(odd) { background: #f6f7f7; }
			.mst-tags-report tbody + tbody tr:first-child td { border-top: 1px solid #dcdcde; }
			.mst-tag-sites { margin: 0; }
			.mst-tag-sites li { margin: 0 0 2px; }
			.mst-tag-sites .mst-edit { margin-left: 4px; }
			.mst-muted { color: #8c8f94; }
			.mst-flag { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; line-height: 1.6; white-space: nowrap; }
			.mst-flag-name { background: #f0f0f1; color: #50575e; margin-bottom: 0; }
			.mst-flag-differ, .mst-flag-spelling { background: #fcf9e8; color: #8a6d00; }
			.mst-flag-words { background: #f0f6fc; color: #135e96; }
			.mst-flag-most { background: #edfaef; color: #00660e; }
			.mst-copy-tag { display: flex; gap: 4px; align-items: center; }
			.mst-copy-tag select { max-width: 180px; }
			.mst-sites summary { cursor: pointer; color: #2271b1; }
			.mst-sites ul { margin: 4px 0 0; }
			.mst-sites li { margin: 0; }
		</style>
		<?php
	}
}
