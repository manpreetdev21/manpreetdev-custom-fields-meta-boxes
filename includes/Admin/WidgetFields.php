<?php
/**
 * Field groups on classic widgets.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Admin;

use WPCMB\Abstracts\Module;
use WPCMB\Fields\Context;
use WPCMB\Fields\ObjectRef;
use WPCMB\Fields\Renderer;
use WPCMB\Fields\Resolver;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves field groups located on a widget.
 *
 * The `widget` location rule has existed since the first release and never
 * drew anything: the rule matched, and nothing was ever hooked to the widget
 * form. This is that hook.
 *
 * Values live inside the widget instance, where WordPress keeps the rest of a
 * widget's settings. That is the only place they can live and still behave
 * like a widget's settings — copied when a widget is duplicated, carried when
 * it moves between sidebars, exported with the theme, and gone when the
 * widget is deleted. A parallel set of options would survive the widget and
 * rot.
 *
 * One hook covers both screens. `in_widget_form` fires on Appearance →
 * Widgets and inside the Customizer, and `widget_update_callback` is how both
 * of them save — which is all "Customizer support" has ever meant here.
 */
final class WidgetFields extends Module {

	/**
	 * The key a widget instance stores this plugin's values under.
	 */
	private const INSTANCE_KEY = 'wpcmb_values';

	/**
	 * Only load in the admin.
	 *
	 * The Customizer is an admin screen, and a widget's form is never
	 * rendered on the front of the site.
	 */
	public function is_enabled(): bool {
		return is_admin();
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'in_widget_form', array( $this, 'render' ), 10, 3 );
		add_filter( 'widget_update_callback', array( $this, 'save' ), 10, 4 );
		add_action( 'delete_widget', array( $this, 'forget' ), 10, 3 );

		// Values live in the instance, which is nowhere this plugin would
		// look for them on its own.
		add_filter( 'wpcmb/storage/read', array( $this, 'read' ), 10, 3 );
		add_filter( 'wpcmb/storage/write', array( $this, 'write' ), 10, 4 );
	}

	/**
	 * The object reference for one widget.
	 *
	 * An options reference, because a widget is not a post, a term, a user or
	 * a comment — and `options_widget_text-3` is a thing a template can ask
	 * for by hand.
	 *
	 * @param string $widget_id Widget id, e.g. `text-3`.
	 */
	public static function ref( string $widget_id ): ObjectRef {
		return new ObjectRef( ObjectRef::OPTION, 'widget_' . $widget_id );
	}

	/**
	 * The widget id inside one of our references, or an empty string.
	 *
	 * @param ObjectRef $ref Object reference.
	 */
	private static function widget_id( ObjectRef $ref ): string {
		if ( ObjectRef::OPTION !== $ref->type ) {
			return '';
		}

		$id = (string) $ref->id;

		return str_starts_with( $id, 'widget_' ) ? substr( $id, 7 ) : '';
	}

	/**
	 * Field groups that apply to one widget.
	 *
	 * @param string $id_base   Widget type, e.g. `text`.
	 * @param string $widget_id Widget instance id, e.g. `text-3`.
	 *
	 * @return array<int, \WPCMB\Fields\FieldGroup>
	 */
	private function groups( string $id_base, string $widget_id ): array {
		return $this->container->get( Resolver::class )->groups(
			new Context( self::ref( $widget_id ), array( 'widget' => $id_base ) )
		);
	}

	/**
	 * Render matching groups inside a widget's form.
	 *
	 * @param \WP_Widget           $widget   Widget instance.
	 * @param null|bool            $return   Whether the form returned markup.
	 * @param array<string, mixed> $instance Widget settings.
	 *
	 * @return null|bool
	 */
	public function render( $widget, $return = null, $instance = array() ) {
		if ( ! $widget instanceof \WP_Widget ) {
			return $return;
		}

		$groups = $this->groups( (string) $widget->id_base, (string) $widget->id );

		if ( array() === $groups ) {
			return $return;
		}

		$ref      = self::ref( (string) $widget->id );
		$renderer = $this->container->get( Renderer::class );

		/*
		 * The inputs have to sit inside the widget's own namespace.
		 *
		 * The widgets screen posts the whole form and would find them
		 * anywhere, but the Customizer serialises only the inputs belonging
		 * to the widget it is saving — so anything named outside that
		 * namespace is silently dropped there and the field appears to save
		 * on one screen and not the other.
		 */
		$prefix = $widget->get_field_name( self::INSTANCE_KEY );

		foreach ( $groups as $group ) {
			echo '<div class="wpcmb-widget-fields">';

			if ( '' !== $group->title ) {
				printf( '<p class="wpcmb-widget-fields__title"><strong>%s</strong></p>', esc_html( $group->title ) );
			}

			$renderer->group( $group, $ref, $prefix );

			echo '</div>';
		}

		return $return;
	}

	/**
	 * Store submitted values in the widget instance.
	 *
	 * @param array<string, mixed> $instance     The instance about to be saved.
	 * @param array<string, mixed> $new_instance The submitted instance.
	 * @param array<string, mixed> $old_instance The instance before this save.
	 * @param \WP_Widget           $widget       Widget instance.
	 *
	 * @return array<string, mixed>
	 */
	public function save( $instance, $new_instance = array(), $old_instance = array(), $widget = null ) {
		if ( ! is_array( $instance ) || ! $widget instanceof \WP_Widget ) {
			return $instance;
		}

		$submitted = (array) ( $new_instance[ self::INSTANCE_KEY ] ?? array() );

		$groups = $this->groups( (string) $widget->id_base, (string) $widget->id );

		if ( array() === $groups ) {
			return $instance;
		}

		$renderer = $this->container->get( Renderer::class );
		$fields   = $this->container->get( Resolver::class )->fields(
			new Context( self::ref( (string) $widget->id ), array( 'widget' => (string) $widget->id_base ) )
		);

		$stored = (array) ( $old_instance[ self::INSTANCE_KEY ] ?? array() );

		foreach ( $fields as $name => $field ) {
			if ( ! $renderer->stores_value( $field ) ) {
				continue;
			}

			/*
			 * A field absent from the submission was not on the form. Keeping
			 * what was there is the same rule the post, term and user screens
			 * follow, and it is what stops a widget saved from a narrower
			 * form wiping values the person never saw.
			 */
			if ( ! array_key_exists( $name, $submitted ) ) {
				continue;
			}

			$stored[ $name ] = $renderer->sanitize( wp_unslash( $submitted[ $name ] ), $field );
		}

		$instance[ self::INSTANCE_KEY ] = $stored;

		return $instance;
	}

	/**
	 * Read a value out of the widget instance it lives in.
	 *
	 * @param mixed     $value Null to read normally.
	 * @param string    $name  Field name.
	 * @param ObjectRef $ref   Object reference.
	 *
	 * @return mixed
	 */
	public function read( $value, $name = '', $ref = null ) {
		if ( null !== $value || ! $ref instanceof ObjectRef ) {
			return $value;
		}

		$widget_id = self::widget_id( $ref );

		if ( '' === $widget_id ) {
			return $value;
		}

		$instance = $this->instance( $widget_id );

		return $instance[ self::INSTANCE_KEY ][ (string) $name ] ?? null;
	}

	/**
	 * Write a value into the widget instance it lives in.
	 *
	 * For code and the REST API; the widget form itself goes through save(),
	 * because WordPress hands it the whole instance to return.
	 *
	 * @param bool|null $written Null to write normally.
	 * @param string    $name    Field name.
	 * @param mixed     $value   Value to store.
	 * @param ObjectRef $ref     Object reference.
	 *
	 * @return bool|null
	 */
	public function write( $written, $name = '', $value = null, $ref = null ) {
		if ( null !== $written || ! $ref instanceof ObjectRef ) {
			return $written;
		}

		$widget_id = self::widget_id( $ref );

		if ( '' === $widget_id ) {
			return $written;
		}

		list( $id_base, $number ) = self::split( $widget_id );

		if ( '' === $id_base ) {
			return $written;
		}

		$option    = (array) get_option( 'widget_' . $id_base, array() );
		$instances = $option;

		if ( ! isset( $instances[ $number ] ) || ! is_array( $instances[ $number ] ) ) {
			return false;
		}

		$instances[ $number ][ self::INSTANCE_KEY ][ (string) $name ] = $value;

		return update_option( 'widget_' . $id_base, $instances, false );
	}

	/**
	 * Drop a deleted widget's values with it.
	 *
	 * Fired by both the widgets screen and the Customizer. Without this the
	 * values would sit in the option forever, under an instance number
	 * WordPress will eventually hand to a different widget.
	 *
	 * @param string $widget_id  Widget id.
	 * @param string $sidebar_id Sidebar it was removed from.
	 * @param string $id_base    Widget type.
	 */
	public function forget( $widget_id, $sidebar_id = '', $id_base = '' ): void {
		list( $base, $number ) = self::split( (string) $widget_id );

		if ( '' === $base ) {
			return;
		}

		$instances = (array) get_option( 'widget_' . $base, array() );

		if ( ! isset( $instances[ $number ][ self::INSTANCE_KEY ] ) ) {
			return;
		}

		unset( $instances[ $number ][ self::INSTANCE_KEY ] );

		update_option( 'widget_' . $base, $instances, false );
	}

	/**
	 * One widget's stored instance.
	 *
	 * @param string $widget_id Widget id, e.g. `text-3`.
	 *
	 * @return array<string, mixed>
	 */
	private function instance( string $widget_id ): array {
		list( $id_base, $number ) = self::split( $widget_id );

		if ( '' === $id_base ) {
			return array();
		}

		$instances = (array) get_option( 'widget_' . $id_base, array() );

		return isset( $instances[ $number ] ) && is_array( $instances[ $number ] ) ? $instances[ $number ] : array();
	}

	/**
	 * Split a widget id into its type and its instance number.
	 *
	 * `text-3` is the third instance of the text widget. The type can itself
	 * contain dashes — `my-plugin-widget-2` — so the number is taken from the
	 * end rather than the type from the start.
	 *
	 * @param string $widget_id Widget id.
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function split( string $widget_id ): array {
		if ( 1 !== preg_match( '/^(.+)-(\d+)$/', $widget_id, $parts ) ) {
			return array( '', 0 );
		}

		return array( $parts[1], (int) $parts[2] );
	}
}
