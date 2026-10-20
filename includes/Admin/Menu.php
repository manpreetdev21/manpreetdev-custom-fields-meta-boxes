<?php
/**
 * Admin menu and overview screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\CustomBlocks;
use WPCMB\PostTypes\CustomForms;
use WPCMB\PostTypes\CustomOptionsPages;
use WPCMB\PostTypes\CustomPostTypes;
use WPCMB\PostTypes\CustomTaxonomies;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level menu and renders the overview screen.
 *
 * The field group post type hangs its own submenus off this menu via
 * `show_in_menu`, so only the pages that are not post-type screens are
 * registered here.
 */
final class Menu extends Module {

	/**
	 * Top-level menu slug. Also the overview screen's page slug.
	 */
	public const SLUG = 'wpcmb';

	/**
	 * Only load in the admin.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );

		/*
		 * Last, once everything that belongs in this menu has registered.
		 *
		 * The order a submenu ends up in is the order things happened to be
		 * registered: post types register on `init`, pages on `admin_menu`,
		 * and a submissions type only exists from its first submission — so
		 * the menu rearranged itself as a site was used. One sort at the end
		 * is the only way the order is the same on every screen.
		 */
		add_action( 'admin_menu', array( $this, 'order_submenu' ), 100 );

		add_filter( 'submenu_file', array( $this, 'highlight_parent' ) );
	}

	/**
	 * Put this plugin's submenu into a fixed order.
	 *
	 * Overview first, because it is the screen that explains the rest. The
	 * things a site makes — forms and options pages, both of which there can
	 * be any number of — go after the fixed screens, so the menu does not
	 * grow in its middle.
	 */
	public function order_submenu(): void {
		if ( empty( $GLOBALS['submenu'][ self::SLUG ] ) || ! is_array( $GLOBALS['submenu'][ self::SLUG ] ) ) {
			return;
		}

		/*
		 * What is built here comes first, in the order somebody builds it:
		 * fields, then the post types and taxonomies they go on. The plugin's
		 * own screens follow. What a site accumulates — a form's submissions,
		 * an options page, and there can be any number of either — goes last,
		 * so the menu grows at the bottom rather than in the middle.
		 */
		$order = array(
			self::SLUG                                     => 0,
			'edit.php?post_type=' . FieldGroupPostType::POST_TYPE => 1,
			'edit.php?post_type=' . CustomPostTypes::POST_TYPE => 2,
			'edit.php?post_type=' . CustomTaxonomies::POST_TYPE => 3,
			'edit.php?post_type=' . CustomBlocks::POST_TYPE => 4,
			'edit.php?post_type=' . CustomForms::POST_TYPE => 5,
			'edit.php?post_type=' . CustomOptionsPages::POST_TYPE => 6,
			'wpcmb-settings'                               => 7,
			'wpcmb-tools'                                  => 8,
		);

		/**
		 * Rank one submenu entry. Lower sorts higher.
		 *
		 * @param array<int, string> $item Submenu entry; index 2 is its slug.
		 */
		$rank = static function ( array $item ) use ( $order ): int {
			$slug = (string) ( $item[2] ?? '' );

			if ( isset( $order[ $slug ] ) ) {
				return $order[ $slug ];
			}

			/*
			 * Everything else is something a site accumulated rather than
			 * something it was given: one entry per form that has taken a
			 * submission, one per options page that exists. Both go below
			 * Settings and Tools, however many of them there are.
			 */
			return match ( true ) {
				str_starts_with( $slug, 'wpcmb-options-' )            => 10,
				str_starts_with( $slug, 'edit.php?post_type=wpcmb_' ) => 9,
				default                                               => 9,
			};
		};

		$items = $GLOBALS['submenu'][ self::SLUG ];

		// A stable sort, so two forms keep the order they registered in
		// rather than swapping places between page loads.
		$ordered = array_values( $items );

		usort(
			$ordered,
			static function ( array $a, array $b ) use ( $rank, $items ): int {
				$difference = $rank( $a ) <=> $rank( $b );

				return 0 !== $difference ? $difference : array_search( $a, $items, true ) <=> array_search( $b, $items, true );
			}
		);

		/*
		 * Writing to $submenu is the only way to order one.
		 *
		 * `custom_menu_order` and `menu_order` reorder top-level menus only;
		 * core exposes no filter for the items inside one. Only this plugin's
		 * own key is touched, and the entries are the ones already in it,
		 * reordered — nothing is added, removed or rewritten.
		 */
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reordering this plugin's own submenu; see above.
		$GLOBALS['submenu'][ self::SLUG ] = $ordered;
	}

	/**
	 * Register the menu and its non-post-type pages.
	 */
	public function register(): void {
		$capability = FieldGroupPostType::capability();

		add_menu_page(
			__( 'Manpreetdev Custom Fields', 'manpreetdev-custom-fields-meta-boxes' ),
			__( 'Meta Boxes', 'manpreetdev-custom-fields-meta-boxes' ),
			$capability,
			self::SLUG,
			array( $this, 'render_overview' ),
			'dashicons-feedback',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Overview', 'manpreetdev-custom-fields-meta-boxes' ),
			__( 'Overview', 'manpreetdev-custom-fields-meta-boxes' ),
			$capability,
			self::SLUG,
			array( $this, 'render_overview' )
		);
	}

	/**
	 * Keep the Overview submenu unhighlighted while editing a field group.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 *
	 * @return string|null
	 */
	public function highlight_parent( $submenu_file ) {
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && FieldGroupPostType::POST_TYPE === $screen->post_type ) {
			return 'edit.php?post_type=' . FieldGroupPostType::POST_TYPE;
		}

		return $submenu_file;
	}

	/**
	 * Render the overview screen.
	 */
	public function render_overview(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'manpreetdev-custom-fields-meta-boxes' ) );
		}

		$repository = $this->container->get( Repository::class );
		$all        = $repository->all( true );
		$active     = $repository->all();

		$cards = array(
			array(
				'title' => __( 'Create a field group', 'manpreetdev-custom-fields-meta-boxes' ),
				'text'  => __( 'Define the fields you need, then choose the screens they appear on.', 'manpreetdev-custom-fields-meta-boxes' ),
				'url'   => admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ),
				'label' => __( 'Add Field Group', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			array(
				'title' => __( 'Import and export', 'manpreetdev-custom-fields-meta-boxes' ),
				'text'  => __( 'Move field groups between sites as JSON, or export them as PHP for version control.', 'manpreetdev-custom-fields-meta-boxes' ),
				'url'   => admin_url( 'admin.php?page=' . ToolsPage::SLUG ),
				'label' => __( 'Open Tools', 'manpreetdev-custom-fields-meta-boxes' ),
			),
			array(
				'title' => __( 'Settings', 'manpreetdev-custom-fields-meta-boxes' ),
				'text'  => __( 'Control the admin theme and what happens to your data when the plugin is deleted.', 'manpreetdev-custom-fields-meta-boxes' ),
				'url'   => admin_url( 'admin.php?page=' . SettingsPage::SLUG ),
				'label' => __( 'Open Settings', 'manpreetdev-custom-fields-meta-boxes' ),
			),
		);

		$inactive = count( $all ) - count( $active );
		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Manpreetdev Custom Fields', 'manpreetdev-custom-fields-meta-boxes' ); ?></h1>

			<?php if ( array() === $all ) : ?>
				<div class="wpcmb-empty wpcmb-empty--page">
					<span class="wpcmb-empty__title">
						<?php esc_html_e( 'No field groups yet', 'manpreetdev-custom-fields-meta-boxes' ); ?>
					</span>
					<p>
						<?php esc_html_e( 'A field group is a set of fields plus the rules for where they appear. Create one to start adding fields to your content.', 'manpreetdev-custom-fields-meta-boxes' ); ?>
					</p>
					<p>
						<a class="wpcmb-btn wpcmb-btn--primary wpcmb-btn--lg" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . FieldGroupPostType::POST_TYPE ) ); ?>">
							<?php esc_html_e( 'Create your first field group', 'manpreetdev-custom-fields-meta-boxes' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( 0 !== $inactive ) : ?>
				<p class="wpcmb-hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of inactive field groups. */
							_n(
								'%d field group is saved as a draft, so its fields are not showing yet.',
								'%d field groups are saved as drafts, so their fields are not showing yet.',
								$inactive,
								'manpreetdev-custom-fields-meta-boxes'
							),
							$inactive
						)
					);
					?>
				</p>
			<?php endif; ?>

			<div class="wpcmb-stats">
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) count( $all ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Field groups', 'manpreetdev-custom-fields-meta-boxes' ); ?></span>
				</div>
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) count( $active ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Active', 'manpreetdev-custom-fields-meta-boxes' ); ?></span>
				</div>
				<div class="wpcmb-stat">
					<span class="wpcmb-stat__value"><?php echo esc_html( (string) array_sum( array_map( static fn( $g ): int => count( $g->fields ), $all ) ) ); ?></span>
					<span class="wpcmb-stat__label"><?php esc_html_e( 'Fields', 'manpreetdev-custom-fields-meta-boxes' ); ?></span>
				</div>
			</div>

			<div class="wpcmb-cards">
				<?php foreach ( $cards as $card ) : ?>
					<div class="wpcmb-card">
						<h2><?php echo esc_html( $card['title'] ); ?></h2>
						<p><?php echo esc_html( $card['text'] ); ?></p>
						<a class="wpcmb-btn wpcmb-btn--primary" href="<?php echo esc_url( $card['url'] ); ?>">
							<?php echo esc_html( $card['label'] ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			/**
			 * Fires at the end of the overview screen.
			 *
			 * @since 1.0.0
			 */
			do_action( 'wpcmb/admin/overview' );
			?>
		</div>
		<?php
	}
}
