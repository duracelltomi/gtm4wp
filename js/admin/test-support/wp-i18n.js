/**
 * Stand-in for `@wordpress/i18n` (test only — see jest.config.js).
 *
 * Untranslated strings pass through unchanged, which is what an English-locale
 * WordPress does too, so the tests can query by the literal source string.
 */

/**
 * Returns the string unchanged (no translations loaded under test).
 *
 * @param {string} text Source string.
 * @return {string} The same string.
 */
export function __( text ) {
	return text;
}

/**
 * @param {string} text Source string.
 * @return {string} The same string.
 */
export function _x( text ) {
	return text;
}

/**
 * @param {string} single Singular form.
 * @param {string} plural Plural form.
 * @param {number} number Count deciding the form.
 * @return {string} The form matching `number`.
 */
export function _n( single, plural, number ) {
	return 1 === number ? single : plural;
}

/**
 * Minimal printf supporting the positional (`%1$s`) and sequential (`%s`, `%d`)
 * placeholders the admin app uses.
 *
 * @param {string} format Format string.
 * @param {...*}   args   Replacement values.
 * @return {string} Formatted string.
 */
export function sprintf( format, ...args ) {
	let sequential = 0;

	return String( format ).replace(
		/%(?:(\d+)\$)?([sd])/g,
		( match, position, type ) => {
			const value = position
				? args[ Number( position ) - 1 ]
				: args[ sequential++ ];

			if ( undefined === value ) {
				return match;
			}

			return 'd' === type ? String( Number( value ) ) : String( value );
		}
	);
}
