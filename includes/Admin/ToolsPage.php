<?php
/**
 * Import and export screen.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Database\Portability;
use WPCMB\Fields\FieldGroup;
use WPCMB\Fields\Repository;
use WPCMB\PostTypes\FieldGroupPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Exports field groups as JSON or PHP, and imports them back from JSON.
 *
 * JSON is the interchange format because it is exactly what is stored; PHP
 * export is a one-way convenience for putting groups under version control.
 */
final class ToolsPage extends Module {

	/**
	 * Page slug.
	 */
	public const SLUG = 'wpcmb-tools';

	/**
	 * Nonce action for both forms.
	 */
	private const NONCE = 'wpcmb_tools';

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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_request' ) );
	}

	/**
	 * Add the tools submenu.
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::SLUG,
			__( 'Tools', 'metafields-custom-fields' ),
			__( 'Tools', 'metafields-custom-fields' ),
			FieldGroupPostType::capability(),
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Dispatch an export or import request.
	 *
	 * Runs on admin_init so a JSON download can be streamed before any output.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below, once we know a request is ours.
		$action = isset( $_POST['wpcmb_tool'] ) ? sanitize_key( wp_unslash( $_POST['wpcmb_tool'] ) ) : '';

		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::NONCE );

		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to use these tools.', 'metafields-custom-fields' ) );
		}

		match ( $action ) {
			'export_json' => $this->export_json(),
			'import_json' => $this->import_json(),
			default       => null,
		};
	}

	/**
	 * Stream the selected groups as a JSON download.
	 */
	private function export_json(): void {
		$payload = $this->payload();

		if ( array() === Portability::summarise( $payload ) ) {
			$this->redirect( 'nothing_selected' );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpcmb-export-' . gmdate( 'Y-m-d' ) . '.json' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * The export the current request asked for.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$sections = $this->selected_sections();
		$groups   = in_array( 'field_groups', $sections, true ) ? array_keys( $this->selected_groups() ) : array();

		return $this->container->get( Portability::class )->export( $sections, $groups );
	}

	/**
	 * Which sections the request asked to export.
	 *
	 * Nothing ticked means nothing, not everything: an export with no boxes
	 * ticked is a mistake, and the screen says so rather than quietly
	 * downloading the entire site.
	 *
	 * @return array<int, string>
	 */
	private function selected_sections(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_request() verified the nonce before dispatching here.
		$sections = isset( $_POST['wpcmb_sections'] ) && is_array( $_POST['wpcmb_sections'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['wpcmb_sections'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$known = array_merge( array( 'field_groups' ), array_keys( Portability::sections() ) );

		return array_values( array_intersect( $known, $sections ) );
	}

	/**
	 * Import groups from an uploaded JSON file.
	 *
	 * A group whose key already exists is updated in place; anything else is
	 * created as a draft, so an import can never silently activate a group.
	 */
	private function import_json(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_request() verified the nonce before dispatching here.
		if ( empty( $_FILES['wpcmb_import']['tmp_name'] ) || ! is_uploaded_file( $_FILES['wpcmb_import']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A path, validated by is_uploaded_file().
			$this->redirect( 'import_failed' );
		}

		$contents = file_get_contents( $_FILES['wpcmb_import']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Local upload, not a remote request.
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$payload = Portability::normalise( is_string( $contents ) ? json_decode( $contents, true ) : null );

		if ( null === $payload ) {
			$this->redirect( 'import_failed' );
		}

		$written = $this->container->get( Portability::class )->import( $payload );
		$total   = array_sum( $written );

		$this->redirect( 0 === $total ? 'import_failed' : 'imported', $total );
	}

	/**
	 * The groups checked on the export form.
	 *
	 * @return array<string, FieldGroup>
	 */
	private function selected_groups(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_request() verified the nonce before dispatching here.
		$keys = isset( $_POST['wpcmb_groups'] ) && is_array( $_POST['wpcmb_groups'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['wpcmb_groups'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$all = $this->container->get( Repository::class )->all( true );

		return array_intersect_key( $all, array_flip( $keys ) );
	}

	/**
	 * Redirect back to this screen with a result code.
	 *
	 * @param string $result Result code.
	 * @param int    $count  Optional count to report.
	 */
	private function redirect( string $result, int $count = 0 ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::SLUG,
					'wpcmb_result' => $result,
					'wpcmb_count'  => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the tools screen.
	 */
	public function render(): void {
		if ( ! current_user_can( FieldGroupPostType::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'metafields-custom-fields' ) );
		}

		$groups = $this->container->get( Repository::class )->all( true );

		// Asked once: the export is a few queries, and the view reads the
		// counts twice and the payload again for the PHP.
		$portability = $this->container->get( Portability::class );
		$counts      = Portability::summarise( $portability->export() );

		$this->render_notice();
		?>
		<div class="wrap wpcmb-wrap">
			<h1><?php esc_html_e( 'Tools', 'metafields-custom-fields' ); ?></h1>

			<div class="wpcmb-cards">
				<div class="wpcmb-card">
					<h2><?php esc_html_e( 'Export', 'metafields-custom-fields' ); ?></h2>
					<p><?php esc_html_e( 'Everything this plugin holds as configuration, in one file: field groups, and the post types, taxonomies, block types, forms and options pages built from them.', 'metafields-custom-fields' ); ?></p>

					<form method="post">
						<?php wp_nonce_field( self::NONCE ); ?>

						<fieldset class="wpcmb-fieldset"><legend><?php esc_html_e( 'Include', 'metafields-custom-fields' ); ?></legend>
							<?php foreach ( $counts as $section => $count ) : ?>
								<label class="wpcmb-checkbox">
									<input type="checkbox" name="wpcmb_sections[]" value="<?php echo esc_attr( $section ); ?>"<?php checked( $count > 0 ); ?><?php disabled( 0 === $count ); ?> />
									<?php echo esc_html( sprintf( '%s (%d)', self::section_label( (string) $section ), $count ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>

						<?php if ( array() !== $groups ) : ?>
							<details class="wpcmb-details">
								<summary><?php esc_html_e( 'Choose which field groups', 'metafields-custom-fields' ); ?></summary>
								<fieldset class="wpcmb-fieldset">
									<?php foreach ( $groups as $group ) : ?>
										<label class="wpcmb-checkbox">
											<input type="checkbox" name="wpcmb_groups[]" value="<?php echo esc_attr( $group->key ); ?>" checked />
											<?php echo esc_html( '' !== $group->title ? $group->title : $group->key ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</details>
						<?php endif; ?>

						<p>
							<button type="submit" class="wpcmb-btn wpcmb-btn--primary" name="wpcmb_tool" value="export_json">
								<?php esc_html_e( 'Download JSON', 'metafields-custom-fields' ); ?>
							</button>
						</p>
					</form>
				</div>

				<div class="wpcmb-card">
					<h2><?php esc_html_e( 'Import', 'metafields-custom-fields' ); ?></h2>
					<p><?php esc_html_e( 'Upload a JSON export from this plugin, at any version. Anything already here is updated in place; anything new arrives inactive, so uploading a file never starts changing your screens on its own.', 'metafields-custom-fields' ); ?></p>

					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field( self::NONCE ); ?>
						<p><input type="file" name="wpcmb_import" accept="application/json,.json" required /></p>
						<p>
							<button type="submit" class="wpcmb-btn wpcmb-btn--primary" name="wpcmb_tool" value="import_json">
								<?php esc_html_e( 'Import', 'metafields-custom-fields' ); ?>
							</button>
						</p>
					</form>
				</div>
			</div>

			<?php if ( array() !== array_filter( $counts ) ) : ?>
				<h2><?php esc_html_e( 'PHP export', 'metafields-custom-fields' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The same configuration as code. Drop it into a theme or a small plugin and the records it replaces can be deleted — the screens still show everything, and it deploys with your code instead of being carried by hand.', 'metafields-custom-fields' ); ?></p>
				<textarea class="widefat code wpcmb-php-export" rows="16" readonly><?php echo esc_textarea( $portability->php( $portability->export() ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A section's name, for the export checkboxes.
	 *
	 * @param string $section Section name.
	 */
	private static function section_label( string $section ): string {
		$labels = array(
			'field_groups'  => __( 'Field groups', 'metafields-custom-fields' ),
			'post_types'    => __( 'Post types', 'metafields-custom-fields' ),
			'taxonomies'    => __( 'Taxonomies', 'metafields-custom-fields' ),
			'blocks'        => __( 'Block types', 'metafields-custom-fields' ),
			'forms'         => __( 'Forms', 'metafields-custom-fields' ),
			'options_pages' => __( 'Options pages', 'metafields-custom-fields' ),
		);

		return $labels[ $section ] ?? $section;
	}

	/**
	 * Show the result of the last import or export attempt.
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only result flags.
		$result = isset( $_GET['wpcmb_result'] ) ? sanitize_key( wp_unslash( $_GET['wpcmb_result'] ) ) : '';
		$count  = isset( $_GET['wpcmb_count'] ) ? absint( wp_unslash( $_GET['wpcmb_count'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'imported'         => array(
				'success',
				/* translators: %d: number of field groups imported. */
				sprintf( _n( '%d field group imported.', '%d field groups imported.', $count, 'metafields-custom-fields' ), $count ),
			),
			'import_failed'    => array( 'error', __( 'That file could not be read as a field group export.', 'metafields-custom-fields' ) ),
			'nothing_selected' => array( 'warning', __( 'Select at least one field group to export.', 'metafields-custom-fields' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}
}
