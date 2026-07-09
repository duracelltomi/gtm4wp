/**
 * Pure helper functions of the GTM4WP admin app.
 */

/**
 * Coerces a raw stored option value into the shape the UI control expects.
 *
 * @param {Object} field Field description from the bootstrap data.
 * @param {*}      raw   Raw stored value.
 * @return {*} Value for the UI control.
 */
export function coerceValue( field, raw ) {
	switch ( field.type ) {
		case 'checkbox':
			return Boolean( raw );

		case 'integer':
			return Number.isFinite( Number( raw ) ) ? Number( raw ) : 0;

		case 'select':
			return String( raw ?? '' );

		case 'multiselect':
			if ( Array.isArray( raw ) ) {
				return raw.filter( ( entry ) => '' !== entry );
			}
			return String( raw ?? '' )
				.split( ',' )
				.filter( ( entry ) => '' !== entry );

		case 'table': {
			const columns = Array.isArray( field.columns ) ? field.columns : [];
			const rows = Array.isArray( raw ) ? raw : [];

			return rows.map( ( row ) => {
				const entry = {};

				columns.forEach( ( column ) => {
					entry[ column.key ] = String(
						( row ? row[ column.key ] : '' ) ?? ''
					);
				} );

				return entry;
			} );
		}

		default:
			return String( raw ?? '' );
	}
}

/**
 * Returns the map of values that differ between the initial and the
 * current state, ready to be submitted to the REST endpoint.
 *
 * @param {Object} initialValues Option key => value map at load/save time.
 * @param {Object} currentValues Option key => value map of the UI state.
 * @return {Object} Only the changed entries.
 */
export function changedValues( initialValues, currentValues ) {
	const changed = {};

	Object.keys( currentValues ).forEach( ( key ) => {
		const a = JSON.stringify( initialValues[ key ] );
		const b = JSON.stringify( currentValues[ key ] );

		if ( a !== b ) {
			changed[ key ] = currentValues[ key ];
		}
	} );

	return changed;
}

/**
 * Whether a module matches a search term: its title or any field label /
 * description contains the term (case insensitive).
 *
 * @param {Object} module Module description from the bootstrap data.
 * @param {string} term   Search term.
 * @return {boolean} True when the module should stay visible.
 */
export function moduleMatchesSearch( module, term ) {
	const needle = term.trim().toLowerCase();

	if ( '' === needle ) {
		return true;
	}

	if ( module.title.toLowerCase().includes( needle ) ) {
		return true;
	}

	return module.fields.some(
		( field ) =>
			field.label.toLowerCase().includes( needle ) ||
			stripTags( field.description ).toLowerCase().includes( needle )
	);
}

/**
 * Removes HTML tags from a translated description string.
 *
 * @param {string} html HTML string.
 * @return {string} Plain text.
 */
export function stripTags( html ) {
	return String( html ?? '' ).replace( /<[^>]*>/g, '' );
}
