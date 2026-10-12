<?php
/**
 * Field type contract.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for field types.
 *
 * One subclass serves a family of related types rather than one type each:
 * thirty single-line inputs differ by an HTML `type` attribute and a
 * sanitizer, not by behaviour, and thirty near-identical classes would be
 * thirty places to fix the same bug. `types()` declares which names a
 * subclass answers to.
 *
 * A type has three jobs and no state: draw a control, clean what comes back
 * from the browser, and turn what is stored into what templates want.
 */
abstract class FieldType {

	/**
	 * Type names this class handles, mapped to their editor labels.
	 *
	 * @return array<string, string>
	 */
	abstract public function types(): array;

	/**
	 * The label for the group these types appear under in the field editor.
	 */
	abstract public function group_label(): string;

	/**
	 * The Dashicon shown beside a field of this type in the editor.
	 *
	 * A Dashicon rather than bundled artwork: WordPress already ships the set,
	 * it inherits the admin colour scheme, and it costs no extra request. A
	 * type may vary the icon per name — a gallery is not an image.
	 *
	 * @param string $type The specific type.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	public function icon( string $type ): string {
		return 'dashicons-editor-textcolor';
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Render the control.
	 *
	 * Implementations must escape everything they output; the renderer only
	 * owns the wrapper, label and instructions around this.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name The `name` attribute to use.
	 * @param string               $input_id   The `id` attribute to use.
	 */
	abstract public function render( array $field, mixed $value, string $input_name, string $input_id ): void;

	/**
	 * Clean a submitted value before it is stored.
	 *
	 * This is the trust boundary for field values, in the same way that
	 * FieldGroup::sanitize() is the boundary for field definitions.
	 *
	 * Implementations must be idempotent: sanitizing an already-sanitized
	 * value must return it unchanged. A value can pass through here more than
	 * once — an edit screen cleans it to validate it and the value pipeline
	 * cleans it again on write — and a non-idempotent sanitizer would
	 * double-encode or progressively strip the stored value.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		return is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}

	/**
	 * Turn a stored value into what templates should receive.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		return $value;
	}

	/**
	 * Whether this type stores a value.
	 *
	 * Structural types — tabs, accordions, messages — draw something but have
	 * nothing to save, and must not create empty meta rows.
	 */
	public function is_data(): bool {
		return true;
	}

	/**
	 * Reject a value this type cannot accept.
	 *
	 * For the rules only the type knows: a file's type and size, an image's
	 * dimensions. The generic checks — required, length, range, pattern — are
	 * the Validator's, and run before this.
	 *
	 * Called for every value on its way to storage, wherever it came from, so
	 * a limit holds for a form, the REST API and code alike.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return string Error message, or an empty string when acceptable.
	 *
	 * phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Part of the contract subclasses implement.
	 */
	public function validate( mixed $value, array $field ): string {
		return '';
	}

	/**
	 * Act on the object a value was just written to.
	 *
	 * The one place a type gets the object as well as the value, for a type
	 * whose value means something elsewhere in WordPress — a taxonomy field
	 * that also assigns its terms. Side effects only: the stored value is
	 * already decided by sanitize().
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $ref   ObjectRef for the object written to.
	 */
	public function on_save( mixed $value, array $field, mixed $ref ): void {
	}

	/**
	 * Replace a stored value with one read from somewhere else.
	 *
	 * The mirror of on_save(), for a field configured to show what WordPress
	 * holds rather than what the field stored.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $ref   ObjectRef for the object being read.
	 *
	 * @return mixed
	 */
	public function on_load( mixed $value, array $field, mixed $ref ): mixed {
		return $value;
	}

	/**
	 * Extra per-type settings the field editor should offer.
	 *
	 * Each entry is `setting_name => array( 'label' => …, 'type' => …, 'choices' => … )`
	 * where `type` is one of `text`, `number`, `textarea`, `select`, `toggle`
	 * or `choices`. The builder renders these; nothing here reaches the page
	 * being edited.
	 *
	 * @param string $type The specific type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		return array();
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * A setting's value, with a fallback.
	 *
	 * @param array<string, mixed> $field    Field definition.
	 * @param string               $name     Setting name.
	 * @param mixed                $fallback Value when unset or empty.
	 *
	 * @return mixed
	 */
	protected function setting( array $field, string $name, mixed $fallback = '' ): mixed {
		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$value    = $settings[ $name ] ?? '';

		return '' === $value || null === $value ? $fallback : $value;
	}

	/**
	 * Build an attribute string from a name => value map.
	 *
	 * Values of `false` and `null` drop the attribute; `true` renders it bare.
	 *
	 * @param array<string, mixed> $attributes Attribute map.
	 */
	protected function attributes( array $attributes ): string {
		$parts = array();

		foreach ( $attributes as $name => $value ) {
			if ( false === $value || null === $value || '' === $value ) {
				continue;
			}

			if ( true === $value ) {
				$parts[] = esc_attr( $name );
				continue;
			}

			$parts[] = sprintf( '%s="%s"', esc_attr( $name ), esc_attr( (string) $value ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Attributes every control shares: required, placeholder, and the native
	 * HTML validation the browser can enforce without any JavaScript.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 *
	 * @return array<string, mixed>
	 */
	protected function base_attributes( array $field, string $input_name, string $input_id ): array {
		return array(
			'name'        => $input_name,
			'id'          => $input_id,
			'class'       => 'wpcmb-input',
			'placeholder' => (string) ( $field['placeholder'] ?? '' ),
			'required'    => ! empty( $field['required'] ),
			'maxlength'   => $this->setting( $field, 'maxlength' ),
			'minlength'   => $this->setting( $field, 'minlength' ),
			'pattern'     => $this->setting( $field, 'pattern' ),
		);
	}
}
