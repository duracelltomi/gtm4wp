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
 * Builds the option key => UI value map for every field of every module,
 * coercing each raw value into the shape its control expects. Used both for
 * the initial load (no overrides) and after an import, where the freshly
 * stored values returned by the server replace the current UI state.
 *
 * @param {Array}  modules   Module descriptions from the bootstrap data.
 * @param {Object} overrides Optional option key => raw value map that takes
 *                           precedence over each field's own `value`.
 * @return {Object} Option key => coerced UI value map.
 */
export function buildValueMap( modules, overrides = {} ) {
	const values = {};

	modules.forEach( ( module ) => {
		module.fields.forEach( ( field ) => {
			const raw = Object.prototype.hasOwnProperty.call(
				overrides,
				field.key
			)
				? overrides[ field.key ]
				: field.value;

			values[ field.key ] = coerceValue( field, raw );
		} );
	} );

	return values;
}

/**
 * Builds the file name a settings export is downloaded as. The date is
 * injected so the value is deterministic and testable.
 *
 * @param {Date} date Date used for the file name stamp.
 * @return {string} File name, e.g. `gtm4wp-settings-2026-07-15.json`.
 */
export function exportFilename( date = new Date() ) {
	const stamp = ( date instanceof Date ? date : new Date() )
		.toISOString()
		.slice( 0, 10 );

	return `gtm4wp-settings-${ stamp }.json`;
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

/**
 * Builds the SelectControl option list for the Axeptio cookies-version field
 * from the versions fetched from the Axeptio project, keeping the currently
 * saved value representable even when the fetch fails or the version was
 * removed from the project (so a save never silently drops it).
 *
 * @param {Array}  cookies          The `cookies` array of the Axeptio project JSON.
 * @param {string} currentValue     The currently saved cookies version.
 * @param {string} placeholderLabel Label of the leading empty option, shown only
 *                                  when no version is selected yet.
 * @return {Array} `{ value, label }` options for `SelectControl`.
 */
export function axeptioVersionOptions(
	cookies,
	currentValue,
	placeholderLabel
) {
	const current = String( currentValue ?? '' );
	const list = Array.isArray( cookies ) ? cookies : [];
	const options = [];
	const names = new Set();

	// A leading empty option so an unset value stays representable.
	if ( '' === current ) {
		options.push( { value: '', label: placeholderLabel ?? '' } );
	}

	list.forEach( ( cookie ) => {
		if ( ! cookie || ! cookie.name ) {
			return;
		}

		const name = String( cookie.name );
		if ( names.has( name ) ) {
			return;
		}

		names.add( name );
		options.push( {
			value: name,
			label: String( cookie.title || cookie.name ),
		} );
	} );

	// Preserve a saved value that is no longer published (or that could not be
	// loaded) so submitting the form does not wipe it.
	if ( '' !== current && ! names.has( current ) ) {
		options.push( { value: current, label: current } );
	}

	return options;
}
