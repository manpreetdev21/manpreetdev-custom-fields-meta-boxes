<?php
/**
 * Conditional logic evaluation.
 *
 * @package WPCMB
 */

declare( strict_types=1 );

namespace WPCMB\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a field is visible, given the values around it.
 *
 * The browser hides and shows fields live; this is the same decision made on
 * the server. Both are needed and for different reasons: without the server
 * copy, a "required" rule on a hidden field would reject a save the user
 * could not see how to fix, and anyone could post values for fields the form
 * never showed them.
 */
final class Conditional {

	/**
	 * Whether a field is visible.
	 *
	 * A field with no conditional logic is always visible. A rule pointing at
	 * a field key that does not exist is treated as unmet rather than as an
	 * error, so deleting a field cannot make an unrelated one vanish.
	 *
	 * @param array<string, mixed> $field  Field definition.
	 * @param callable             $lookup Given a field key, returns that field's current value.
	 */
	public static function is_visible( array $field, callable $lookup ): bool {
		$conditional = $field['conditional'] ?? array();

		if ( ! is_array( $conditional ) ) {
			return true;
		}

		$groups = self::groups( $conditional );

		if ( array() === $groups ) {
			return true;
		}

		$satisfied = self::groups_met( $groups, $lookup );
		$visible   = 'hide' === ( $conditional['action'] ?? 'show' ) ? ! $satisfied : $satisfied;

		/**
		 * Filters whether conditional logic makes a field visible.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                 $visible Whether the field is visible.
		 * @param array<string, mixed> $field   Field definition.
		 */
		return (bool) apply_filters( 'wpcmb/conditional/visible', $visible, $field );
	}

	/**
	 * Keep only the submitted values whose field was actually on the form.
	 *
	 * A field hidden by conditional logic was never shown, so a value posted
	 * for it did not come from the form — it was added to the request. The
	 * browser disables hidden inputs, which stops an honest submission from
	 * carrying them, but a disabled attribute is advice to a browser and not a
	 * rule about what may arrive. Without this, anybody could store values in
	 * fields a form never offered them: on a public form that means a visitor
	 * writing to a field the site only reveals to some answers.
	 *
	 * Both save paths hand their submission through here before validating and
	 * writing, so the decision is made once and the front-end form and the
	 * edit screen cannot disagree about it.
	 *
	 * @param array<string, array<string, mixed>> $fields Field definitions, keyed by name.
	 * @param array<string, mixed>                $values Submitted values, keyed by name.
	 *
	 * @return array<string, mixed> The values whose field is visible.
	 */
	public static function visible_values( array $fields, array $values ): array {
		/*
		 * Rules reference a field by key while values arrive by name, so the
		 * lookup bridges the two — against the submission being judged, which
		 * is what makes "show this when that other answer is X" resolve to
		 * what the person actually answered.
		 */
		$lookup = static function ( string $key ) use ( $fields, $values ) {
			foreach ( $fields as $name => $field ) {
				if ( ( $field['key'] ?? '' ) === $key ) {
					return $values[ $name ] ?? null;
				}
			}

			return null;
		};

		$visible = array();

		foreach ( $values as $name => $value ) {
			$field = $fields[ $name ] ?? null;

			// A value with no field behind it is somebody else's business; the
			// callers have already narrowed to fields that apply here.
			if ( ! is_array( $field ) || self::is_visible( $field, $lookup ) ) {
				$visible[ $name ] = $value;
			}
		}

		return $visible;
	}

	/**
	 * Read conditional logic as an OR list of AND groups.
	 *
	 * Converting here rather than only in the sanitizer, because a stored
	 * field group is not re-sanitized on the way out: `FieldGroup::from_post()`
	 * hands the JSON straight to the model, so a group last saved by an
	 * earlier version arrives with its original `rules` and `logic` and never
	 * passes through `sanitize()` again. Reading only `groups` would therefore
	 * have made every existing condition vanish — and a condition that stops
	 * applying does not error, it just shows a field that should be hidden.
	 *
	 * `all` is one group holding every rule; `any` is one group per rule.
	 * Both say exactly what the old switch said.
	 *
	 * @param array<string, mixed> $conditional Stored conditional logic.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	public static function groups( array $conditional ): array {
		if ( isset( $conditional['groups'] ) && is_array( $conditional['groups'] ) ) {
			return array_values( array_filter( $conditional['groups'], 'is_array' ) );
		}

		if ( empty( $conditional['rules'] ) || ! is_array( $conditional['rules'] ) ) {
			return array();
		}

		$rules = array_values( array_filter( $conditional['rules'], 'is_array' ) );

		if ( 'any' === ( $conditional['logic'] ?? 'all' ) ) {
			return array_map( static fn( array $rule ): array => array( $rule ), $rules );
		}

		return array( $rules );
	}

	/**
	 * Whether any group of rules is satisfied.
	 *
	 * Rules within a group all have to hold; any one group holding is enough.
	 * That is the same OR-of-ANDs shape location rules use, so "A and B, or C"
	 * is sayable — which a single all/any switch never could.
	 *
	 * An empty group is not a condition anybody wrote, so it counts as unmet
	 * rather than as vacuously true. Otherwise one stray group would make a
	 * field permanently visible whatever the other groups said.
	 *
	 * @param array<int, mixed> $groups OR list of AND groups.
	 * @param callable          $lookup Given a field key, returns its value.
	 */
	private static function groups_met( array $groups, callable $lookup ): bool {
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) || array() === $group ) {
				continue;
			}

			$met = true;

			foreach ( $group as $rule ) {
				if ( ! is_array( $rule ) || ! self::rule_met( $rule, $lookup( (string) ( $rule['field'] ?? '' ) ) ) ) {
					$met = false;
					break;
				}
			}

			if ( $met ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one rule is met by a value.
	 *
	 * Comparisons are string-based except for the numeric operators, because
	 * stored values arrive as strings from form input and as their original
	 * types from code. `>` and `<` on non-numeric values are never met rather
	 * than falling back to PHP's string comparison, which would make
	 * "greater than 10" true for the value "9".
	 *
	 * @param array<string, mixed> $rule  Rule definition.
	 * @param mixed                $value Current value of the referenced field.
	 */
	private static function rule_met( array $rule, mixed $value ): bool {
		$operator = (string) ( $rule['operator'] ?? '==' );
		$expected = (string) ( $rule['value'] ?? '' );

		// A multi-value field satisfies a comparison if any of its values do.
		if ( is_array( $value ) && ! in_array( $operator, array( 'empty', 'not_empty' ), true ) ) {
			foreach ( $value as $item ) {
				if ( self::rule_met( $rule, $item ) ) {
					return true;
				}
			}

			return false;
		}

		$actual = is_scalar( $value ) ? (string) $value : '';

		return match ( $operator ) {
			'!='           => $actual !== $expected,
			'>'            => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual > (float) $expected,
			'<'            => is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual < (float) $expected,
			'contains'     => '' !== $expected && str_contains( $actual, $expected ),
			'not_contains' => '' === $expected || ! str_contains( $actual, $expected ),
			'empty'        => self::is_empty( $value ),
			'not_empty'    => ! self::is_empty( $value ),
			'pattern'      => '' !== $expected && 1 === preg_match( self::delimit( $expected ), $actual ),
			default        => $actual === $expected,
		};
	}

	/**
	 * Whether a value counts as empty.
	 *
	 * `0` and `'0'` are real answers, so PHP's empty() is the wrong test here.
	 *
	 * @param mixed $value Value to test.
	 */
	private static function is_empty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}

		return null === $value || false === $value || '' === $value;
	}

	/**
	 * Wrap a user-supplied pattern in delimiters and disable backtracking risk.
	 *
	 * Patterns come from the field group editor, which only an administrator
	 * can reach, but they are still quoted so a stray delimiter cannot change
	 * the pattern's meaning or enable modifiers such as `e`.
	 *
	 * @param string $pattern Raw pattern.
	 */
	public static function delimit( string $pattern ): string {
		return '/' . str_replace( '/', '\/', $pattern ) . '/';
	}
}
