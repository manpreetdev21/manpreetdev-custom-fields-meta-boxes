<?php
/**
 * Choice field types.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\FieldTypes;

use WPCMB\Abstracts\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Every field that picks from a fixed list of options.
 *
 * Select, checkbox, radio, toggle, button group, rating, country and state
 * all answer the same question and differ only in how the options are drawn
 * and where the list comes from. Sanitizing is the same for all of them and
 * is the important part: a submitted value is kept only if it is one of the
 * options actually offered, so a hand-built request cannot store a value the
 * form never contained.
 */
final class Choice extends FieldType {

	/**
	 * Types whose control is a dropdown.
	 *
	 * Country and region belong here for the same reason select does: their
	 * lists run to hundreds of entries, and rendering those as radio buttons
	 * produces a page of them.
	 */
	private const DROPDOWN_TYPES = array( 'select', 'country', 'state' );

	/**
	 * Types that store a single boolean.
	 */
	private const BOOLEAN_TYPES = array( 'toggle', 'true_false' );

	/**
	 * Option holding the site's country list, in `value : Label` lines.
	 */
	public const COUNTRIES_OPTION = 'wpcmb_countries';

	/**
	 * Option holding the site's region list, in `value : Label` lines.
	 */
	public const STATES_OPTION = 'wpcmb_states';

	/**
	 * Types this class handles.
	 *
	 * @return array<string, string>
	 */
	public function types(): array {
		return array(
			'select'       => __( 'Select', 'metafields-custom-fields' ),
			'checkbox'     => __( 'Checkbox', 'metafields-custom-fields' ),
			'radio'        => __( 'Radio', 'metafields-custom-fields' ),
			'toggle'       => __( 'Toggle', 'metafields-custom-fields' ),
			'true_false'   => __( 'True / False', 'metafields-custom-fields' ),
			'button_group' => __( 'Button Group', 'metafields-custom-fields' ),
			'rating'       => __( 'Rating', 'metafields-custom-fields' ),
			'country'      => __( 'Country', 'metafields-custom-fields' ),
			'state'        => __( 'State / Region', 'metafields-custom-fields' ),
		);
	}

	/**
	 * Editor group label.
	 */
	public function group_label(): string {
		return __( 'Choice', 'metafields-custom-fields' );
	}

	/**
	 * The Dashicon shown beside a field of this type.
	 *
	 * @param string $type The specific type.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'button_group' => 'dashicons-grid-view',
			'checkbox'     => 'dashicons-yes-alt',
			'country'      => 'dashicons-admin-site',
			'radio'        => 'dashicons-marker',
			'rating'       => 'dashicons-star-filled',
			'select'       => 'dashicons-menu-alt',
			'state'        => 'dashicons-location',
			'toggle'       => 'dashicons-controls-play',
			'true_false'   => 'dashicons-yes',
		);

		return $icons[ $type ] ?? 'dashicons-menu-alt';
	}

	/**
	 * Render the control.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param mixed                $value      Current value.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	public function render( array $field, mixed $value, string $input_name, string $input_id ): void {
		$type     = (string) ( $field['type'] ?? 'select' );
		$multiple = $this->is_multiple( $field );
		$selected = $this->as_list( $value );
		$choices  = $this->choices_with_custom( $field, $selected );

		if ( in_array( $type, self::BOOLEAN_TYPES, true ) ) {
			$this->render_toggle( $field, $type, $selected, $input_name, $input_id );
			return;
		}

		if ( in_array( $type, self::DROPDOWN_TYPES, true ) ) {
			$this->render_select( $field, $choices, $selected, $input_name, $input_id, $multiple );
			return;
		}

		$this->render_options( $field, $type, $choices, $selected, $input_name, $input_id, $multiple );
	}

	/**
	 * Render a select element.
	 *
	 * @param array<string, mixed>  $field      Field definition.
	 * @param array<string, string> $choices    Available options.
	 * @param array<int, string>    $selected   Selected values.
	 * @param string                $input_name Input name.
	 * @param string                $input_id   Input id.
	 * @param bool                  $multiple   Whether multiple values are allowed.
	 */
	private function render_select( array $field, array $choices, array $selected, string $input_name, string $input_id, bool $multiple ): void {
		$attributes = array(
			'name'     => $multiple ? $input_name . '[]' : $input_name,
			'id'       => $input_id,
			'class'    => 'wpcmb-input',
			'required' => ! empty( $field['required'] ),
			'multiple' => $multiple,
			'size'     => $multiple ? '6' : '',
		);

		printf( '<select %s>', $this->attributes( $attributes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes() escapes every name and value.

		/*
		 * The empty option is what makes "nothing chosen" reachable. Turning
		 * it off is for a field that must always hold one of the choices and
		 * has a default to start from — without a default, the browser simply
		 * preselects the first choice, which is a value nobody chose.
		 */
		if ( ! $multiple && '0' !== (string) $this->setting( $field, 'allow_null', '1' ) ) {
			printf(
				'<option value="">%s</option>',
				esc_html( (string) $this->setting( $field, 'empty_label', __( '— Select —', 'metafields-custom-fields' ) ) )
			);
		}

		foreach ( $choices as $option => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' selected' : '',
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a list of checkboxes or radios, including the button group and
	 * rating variants, which are the same inputs styled differently.
	 *
	 * @param array<string, mixed>  $field      Field definition.
	 * @param string                $type       Type name.
	 * @param array<string, string> $choices    Available options.
	 * @param array<int, string>    $selected   Selected values.
	 * @param string                $input_name Input name.
	 * @param string                $input_id   Input id.
	 * @param bool                  $multiple   Whether multiple values are allowed.
	 */
	private function render_options( array $field, string $type, array $choices, array $selected, string $input_name, string $input_id, bool $multiple ): void {
		$control = 'checkbox' === $type ? 'checkbox' : 'radio';
		$index   = 0;

		// A rating is emitted highest-first so that the stylesheet can light
		// up every star below the hovered one. CSS can only reach *later*
		// siblings, so "the stars before this one" has to mean later in the
		// markup; the row is then displayed reversed to read 1..n again.
		if ( 'rating' === $type ) {
			$choices = array_reverse( $choices, true );
		}

		// A rating already lays itself out in a row; the rest follow the
		// setting, which defaults to the column they have always been in.
		$layout = 'rating' === $type ? '' : (string) $this->setting( $field, 'layout', 'vertical' );

		printf(
			'<div class="wpcmb-choices wpcmb-choices--%s%s" role="group" aria-labelledby="%s-label">',
			esc_attr( $type ),
			'horizontal' === $layout ? ' wpcmb-choices--row' : '',
			esc_attr( $input_id )
		);

		// An unchecked checkbox list posts nothing at all, which is
		// indistinguishable from "the field was not in the form". This tells
		// the save handler the field was present and deliberately cleared.
		if ( 'checkbox' === $control ) {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $input_name ) );
		}

		// Select-all is only ever right for a multi-value list.
		if ( 'checkbox' === $control && $multiple && ! empty( $this->setting( $field, 'toggle_all', '' ) ) ) {
			printf(
				'<label class="wpcmb-choice wpcmb-choice--toggle-all"><input type="checkbox" data-wpcmb-toggle-all="1" /> <span>%s</span></label>',
				esc_html__( 'Select all', 'metafields-custom-fields' )
			);
		}

		foreach ( $choices as $option => $label ) {
			$option_id = $input_id . '-' . $index;
			++$index;

			printf(
				'<label class="wpcmb-choice" for="%1$s"><input type="%2$s" id="%1$s" name="%3$s" value="%4$s"%5$s%6$s /> <span>%7$s</span></label>',
				esc_attr( $option_id ),
				esc_attr( $control ),
				esc_attr( 'checkbox' === $control ? $input_name . '[]' : $input_name ),
				esc_attr( (string) $option ),
				in_array( (string) $option, $selected, true ) ? ' checked' : '',
				! empty( $field['required'] ) && 'radio' === $control ? ' required' : '',
				esc_html( $label )
			);
		}

		/*
		 * "Other": a box whose contents join the list of values.
		 *
		 * It posts into the same array as the checkboxes, so what is typed is
		 * simply another value — there is no "other" marker to decode on the
		 * way back, and a stored value reads the same whether it was ticked
		 * or typed. A value typed once is offered as a normal ticked choice
		 * next time, because choices() folds stored values into the list.
		 *
		 * Offered for checkbox lists only. On a radio or a dropdown the box
		 * and the control fight over one value, which needs script to arbitrate
		 * and leaves the field unusable if that script does not load.
		 */
		if ( $multiple && 'checkbox' === $control && ! empty( $this->setting( $field, 'allow_custom', '' ) ) ) {
			$custom = array_values( array_diff( $selected, array_map( 'strval', array_keys( $choices ) ) ) );

			printf(
				'<div class="wpcmb-choice-other">
					<label class="wpcmb-label wpcmb-choice-other__label" for="%1$s-other">%2$s</label>
					<input type="text" class="wpcmb-input wpcmb-choice-other__text" id="%1$s-other" name="%3$s" value="%4$s" placeholder="%5$s" />
				</div>',
				esc_attr( $input_id ),
				esc_html__( 'Other', 'metafields-custom-fields' ),
				esc_attr( $input_name . '[]' ),
				esc_attr( implode( ', ', $custom ) ),
				esc_attr__( 'Separate several with a comma', 'metafields-custom-fields' )
			);
		}

		echo '</div>';
	}

	/**
	 * Render a single on/off switch.
	 *
	 * Toggle and true/false store the same boolean and differ only in what
	 * they say beside the switch: a toggle carries whatever label the field
	 * sets, true/false names both states so the off position reads as a
	 * deliberate "no" rather than an unanswered question.
	 *
	 * The switch is a real checkbox with the box visually hidden, so keyboard
	 * operation, the label association and the form value are all the
	 * browser's own.
	 *
	 * @param array<string, mixed> $field      Field definition.
	 * @param string               $type       Type name.
	 * @param array<int, string>   $selected   Selected values.
	 * @param string               $input_name Input name.
	 * @param string               $input_id   Input id.
	 */
	private function render_toggle( array $field, string $type, array $selected, string $input_name, string $input_id ): void {
		$on = in_array( '1', $selected, true );

		$label = 'true_false' === $type
			? (string) $this->setting( $field, $on ? 'on_label' : 'off_label', $on ? __( 'True', 'metafields-custom-fields' ) : __( 'False', 'metafields-custom-fields' ) )
			: (string) $this->setting( $field, 'toggle_label', '' );

		// An unchecked checkbox posts nothing, which is indistinguishable from
		// "the field was not on the form". This says it was, and was left off.
		printf(
			'<input type="hidden" name="%s" value="0" />
			<label class="wpcmb-switch" for="%s"><input type="checkbox" class="wpcmb-switch__input" id="%s" name="%s" value="1"%s /><span class="wpcmb-switch__track" aria-hidden="true"></span><span class="wpcmb-switch__label" data-wpcmb-on="%s" data-wpcmb-off="%s">%s</span></label>',
			esc_attr( $input_name ),
			esc_attr( $input_id ),
			esc_attr( $input_id ),
			esc_attr( $input_name ),
			$on ? ' checked' : '',
			esc_attr( 'true_false' === $type ? (string) $this->setting( $field, 'on_label', __( 'True', 'metafields-custom-fields' ) ) : '' ),
			esc_attr( 'true_false' === $type ? (string) $this->setting( $field, 'off_label', __( 'False', 'metafields-custom-fields' ) ) : '' ),
			esc_html( $label )
		);
	}

	/**
	 * Keep only values that were actually offered.
	 *
	 * @param mixed                $value Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, array $field ): mixed {
		$type = (string) ( $field['type'] ?? 'select' );

		if ( in_array( $type, self::BOOLEAN_TYPES, true ) ) {
			return ! empty( $value ) && '0' !== $value;
		}

		$allowed   = array_map( 'strval', array_keys( $this->choices( $field ) ) );
		$multiple  = $this->is_multiple( $field );
		$submitted = $this->as_list( $value );
		$values    = array_values( array_intersect( $submitted, $allowed ) );

		/*
		 * With "Other" on, a value that is not one of the choices is kept
		 * rather than discarded — that is the whole point of the setting — but
		 * it is the only place a choice field accepts free text, so it is
		 * cleaned as text and the comma-separated box is split back out.
		 */
		if ( $multiple && ! empty( $this->setting( $field, 'allow_custom', '' ) ) ) {
			/**
			 * Filters how many values one field may hold.
			 *
			 * "Other" is the one place a choice field takes free text, so it
			 * is the one place a single request can decide how much goes into
			 * a meta row. A thousand is far more than a list anybody reads and
			 * far less than a row anybody would notice growing.
			 *
			 * @since 1.9.2
			 *
			 * @param int                  $limit Maximum number of values.
			 * @param array<string, mixed> $field Field definition.
			 */
			$limit = (int) apply_filters( 'wpcmb/field/max_choices', 1000, $field );

			foreach ( array_diff( $submitted, $allowed ) as $custom ) {
				foreach ( explode( ',', (string) $custom ) as $part ) {
					if ( count( $values ) >= $limit ) {
						break 2;
					}

					$part = sanitize_text_field( trim( $part ) );

					if ( '' !== $part && ! in_array( $part, $values, true ) ) {
						$values[] = $part;
					}
				}
			}
		}

		if ( $multiple ) {
			return $values;
		}

		return $values[0] ?? '';
	}

	/**
	 * Optionally return the option label rather than its value.
	 *
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return mixed
	 */
	public function format( mixed $value, array $field ): mixed {
		if ( in_array( (string) ( $field['type'] ?? '' ), self::BOOLEAN_TYPES, true ) ) {
			return (bool) $value;
		}

		if ( 'label' !== $this->setting( $field, 'return_format', 'value' ) ) {
			return $value;
		}

		$choices = $this->choices( $field );

		if ( is_array( $value ) ) {
			return array_map( static fn( $item ) => $choices[ (string) $item ] ?? $item, $value );
		}

		return $choices[ (string) $value ] ?? $value;
	}

	/**
	 * Extra settings for the field editor.
	 *
	 * @param string $type Type being configured.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function settings_schema( string $type ): array {
		if ( 'toggle' === $type ) {
			return array(
				'toggle_label' => array(
					'label' => __( 'Toggle label', 'metafields-custom-fields' ),
					'type'  => 'text',
				),
			);
		}

		if ( 'true_false' === $type ) {
			return array(
				'on_label'  => array(
					'label' => __( 'Label when on', 'metafields-custom-fields' ),
					'type'  => 'text',
					'help'  => __( 'Defaults to True.', 'metafields-custom-fields' ),
				),
				'off_label' => array(
					'label' => __( 'Label when off', 'metafields-custom-fields' ),
					'type'  => 'text',
					'help'  => __( 'Defaults to False.', 'metafields-custom-fields' ),
				),
			);
		}

		$schema = array();

		if ( ! in_array( $type, array( 'country', 'state', 'rating' ), true ) ) {
			$schema['choices'] = array(
				'label' => __( 'Choices', 'metafields-custom-fields' ),
				'type'  => 'choices',
				'help'  => __( 'One per line. Use value : Label to set a value separately from its label.', 'metafields-custom-fields' ),
			);
		}

		if ( 'rating' === $type ) {
			$schema['max'] = array(
				'label' => __( 'Maximum rating', 'metafields-custom-fields' ),
				'type'  => 'number',
			);
		}

		if ( in_array( $type, array( 'select', 'checkbox', 'country', 'state' ), true ) ) {
			$schema['multiple'] = array(
				'label' => __( 'Allow multiple', 'metafields-custom-fields' ),
				'type'  => 'toggle',
			);
			$schema['min']      = array(
				'label' => __( 'Minimum selections', 'metafields-custom-fields' ),
				'type'  => 'number',
			);
			$schema['max']      = array(
				'label' => __( 'Maximum selections', 'metafields-custom-fields' ),
				'type'  => 'number',
			);
		}

		// The empty option only exists on a single-value dropdown; a checkbox
		// list expresses "nothing" by having nothing ticked.
		if ( in_array( $type, self::DROPDOWN_TYPES, true ) ) {
			$schema['allow_null']  = array(
				'label' => __( 'Offer an empty choice', 'metafields-custom-fields' ),
				'type'  => 'toggle',
				'help'  => __( 'On by default. Off needs a default value, or the browser preselects the first choice.', 'metafields-custom-fields' ),
			);
			$schema['empty_label'] = array(
				'label' => __( 'Empty choice label', 'metafields-custom-fields' ),
				'type'  => 'text',
				'tab'   => 'appearance',
			);
		}

		if ( in_array( $type, array( 'checkbox', 'radio', 'button_group' ), true ) ) {
			$schema['layout'] = array(
				'label'   => __( 'Layout', 'metafields-custom-fields' ),
				'type'    => 'select',
				'tab'     => 'appearance',
				'choices' => array(
					'vertical'   => __( 'One per line', 'metafields-custom-fields' ),
					'horizontal' => __( 'In a row', 'metafields-custom-fields' ),
				),
			);
		}

		if ( 'checkbox' === $type ) {
			$schema['toggle_all']   = array(
				'label' => __( 'Offer "Select all"', 'metafields-custom-fields' ),
				'type'  => 'toggle',
			);
			$schema['allow_custom'] = array(
				'label' => __( 'Allow other values', 'metafields-custom-fields' ),
				'type'  => 'toggle',
				'help'  => __( 'Adds a box for values that are not in the list. Multi-value lists only.', 'metafields-custom-fields' ),
			);
		}

		$schema['return_format'] = array(
			'label'   => __( 'Return format', 'metafields-custom-fields' ),
			'type'    => 'select',
			'choices' => array(
				'value' => __( 'Value', 'metafields-custom-fields' ),
				'label' => __( 'Label', 'metafields-custom-fields' ),
			),
		);

		$schema['validation_message'] = array(
			'label' => __( 'Validation message', 'metafields-custom-fields' ),
			'type'  => 'text',
		);

		return $schema;
	}

	/**
	 * Whether the field accepts more than one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 */
	private function is_multiple( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );

		if ( 'checkbox' === $type ) {
			// A checkbox list is multi-value unless explicitly single.
			return '0' !== (string) $this->setting( $field, 'multiple', '1' );
		}

		return in_array( $type, self::DROPDOWN_TYPES, true ) && ! empty( $this->setting( $field, 'multiple', '' ) );
	}

	/**
	 * The options for a field.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function choices( array $field ): array {
		$type = (string) ( $field['type'] ?? '' );

		$choices = match ( $type ) {
			'country' => $this->countries(),
			'state'   => $this->states(),
			'rating'  => $this->rating_scale( $field ),
			default   => $this->parse_choices( $this->setting( $field, 'choices', array() ) ),
		};

		/**
		 * Filters the options offered by a choice field.
		 *
		 * Also the hook to use for a country or region list that suits the
		 * site, since the built-in lists are deliberately short.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $choices Options keyed by value.
		 * @param array<string, mixed>  $field   Field definition.
		 */
		return (array) apply_filters( 'wpcmb/field/choices', $choices, $field );
	}

	/**
	 * The options to draw, including any custom value already stored.
	 *
	 * A value typed into the "Other" box is a real stored value but not a
	 * declared choice. Leaving it out of the list would show the field as
	 * empty while it holds something, and the next save would then drop it.
	 *
	 * @param array<string, mixed> $field    Field definition.
	 * @param array<int, string>   $selected Values currently stored.
	 *
	 * @return array<string, string>
	 */
	private function choices_with_custom( array $field, array $selected ): array {
		$choices = $this->choices( $field );

		if ( empty( $this->setting( $field, 'allow_custom', '' ) ) ) {
			return $choices;
		}

		foreach ( array_diff( $selected, array_map( 'strval', array_keys( $choices ) ) ) as $custom ) {
			$choices[ (string) $custom ] = (string) $custom;
		}

		return $choices;
	}

	/**
	 * Parse the editor's choice input into value => label pairs.
	 *
	 * Accepts either a list of lines or an already-keyed array, so a group
	 * registered in code can pass a map directly.
	 *
	 * @param mixed $raw Stored choices.
	 *
	 * @return array<string, string>
	 */
	private function parse_choices( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', $raw );
			$raw   = is_array( $lines ) ? $lines : array();
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$choices = array();

		foreach ( $raw as $key => $line ) {
			if ( is_string( $key ) && ! is_array( $line ) ) {
				$choices[ $key ] = (string) $line;
				continue;
			}

			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, ':' ) ) {
				list( $value, $label ) = array_map( 'trim', explode( ':', $line, 2 ) );
				$choices[ $value ]     = $label;
				continue;
			}

			$choices[ $line ] = $line;
		}

		return $choices;
	}

	/**
	 * A 1..n rating scale.
	 *
	 * @param array<string, mixed> $field Field definition.
	 *
	 * @return array<string, string>
	 */
	private function rating_scale( array $field ): array {
		$max   = max( 2, min( 10, (int) $this->setting( $field, 'max', 5 ) ) );
		$scale = array();

		for ( $i = 1; $i <= $max; $i++ ) {
			$scale[ (string) $i ] = (string) $i;
		}

		return $scale;
	}

	/**
	 * The country list.
	 *
	 * Three sources in order of authority: the list edited on the settings
	 * screen, then WooCommerce if it is running its own store countries, then
	 * the bundled ISO 3166-1 table. The site's own list wins over WooCommerce
	 * because someone who typed a list meant it.
	 *
	 * @return array<string, string>
	 */
	private function countries(): array {
		$managed = $this->managed_list( self::COUNTRIES_OPTION );

		if ( array() !== $managed ) {
			return $managed;
		}

		if ( function_exists( 'WC' ) && method_exists( WC()->countries, 'get_countries' ) ) {
			$countries = WC()->countries->get_countries();

			if ( is_array( $countries ) && array() !== $countries ) {
				return $countries;
			}
		}

		return (array) require __DIR__ . '/../Fields/data/countries.php';
	}

	/**
	 * The region list, resolved the same way as countries.
	 *
	 * @return array<string, string>
	 */
	private function states(): array {
		$managed = $this->managed_list( self::STATES_OPTION );

		if ( array() !== $managed ) {
			return $managed;
		}

		if ( function_exists( 'WC' ) && method_exists( WC()->countries, 'get_states' ) ) {
			$states = WC()->countries->get_states( (string) WC()->countries->get_base_country() );

			if ( is_array( $states ) && array() !== $states ) {
				return $states;
			}
		}

		return (array) require __DIR__ . '/../Fields/data/states.php';
	}

	/**
	 * Read one of the site-managed lists.
	 *
	 * Stored as the same `value : Label` lines the choices setting uses, so
	 * there is one format to learn and one parser to trust.
	 *
	 * @param string $option Option name.
	 *
	 * @return array<string, string>
	 */
	private function managed_list( string $option ): array {
		$raw = get_option( $option, '' );

		return is_string( $raw ) ? $this->parse_choices( $raw ) : array();
	}

	/**
	 * Normalise a value to a list of strings.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array<int, string>
	 */
	private function as_list( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}

		if ( null === $value || '' === $value || false === $value ) {
			return array();
		}

		return array( is_bool( $value ) ? '1' : (string) $value );
	}
}
