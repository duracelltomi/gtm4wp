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
 * Builds the option key => coerced UI value map for every field of every
 * module; `overrides` carries the freshly stored values after an import.
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
 * Splits a multiselect field's choices into the labelled sections its schema
 * declares (`field.sections`, the Field's `choice_sections`). Presentation
 * only: the stored value stays flat and a section carries choice KEYS, so
 * labels keep their single definition in `field.choices`. No choice may
 * disappear from the screen: an unclaimed key lands in a trailing unlabelled
 * section, a claimed key `choices` does not define is skipped.
 *
 * @param {Object} field Field description from the bootstrap data.
 * @return {Array} `{ label, entries: [ [ value, label ], … ] }` list, in declared
 *                 order; empty sections dropped, no sections = one unlabelled.
 */
export function choiceSections( field ) {
	const choices = field && field.choices ? field.choices : {};
	const entries = Object.entries( choices );
	const declared =
		field && Array.isArray( field.sections ) ? field.sections : [];

	if ( 0 === declared.length ) {
		return entries.length > 0 ? [ { label: '', entries } ] : [];
	}

	const claimed = new Set();
	const sections = [];

	declared.forEach( ( section ) => {
		const keys =
			section && Array.isArray( section.choices ) ? section.choices : [];
		const sectionEntries = [];

		keys.forEach( ( key ) => {
			if (
				! Object.prototype.hasOwnProperty.call( choices, key ) ||
				claimed.has( key )
			) {
				return;
			}

			claimed.add( key );
			sectionEntries.push( [ key, choices[ key ] ] );
		} );

		if ( sectionEntries.length > 0 ) {
			sections.push( {
				label: String( ( section && section.label ) ?? '' ),
				entries: sectionEntries,
			} );
		}
	} );

	const unclaimed = entries.filter( ( [ key ] ) => ! claimed.has( key ) );

	if ( unclaimed.length > 0 ) {
		sections.push( { label: '', entries: unclaimed } );
	}

	return sections;
}

/**
 * Whether an option value counts as set for a dependency. Not plain
 * truthiness: an empty array is truthy, so a field depending on an emptied
 * table or multiselect stayed enabled.
 *
 * @param {*} value The current UI value of the dependency.
 * @return {boolean} True when the dependency is satisfied.
 */
function isValueSet( value ) {
	if ( Array.isArray( value ) ) {
		return 0 < value.length;
	}

	if ( 'string' === typeof value ) {
		return '' !== value.trim();
	}

	return Boolean( value );
}

/**
 * Whether a field's control is disabled because its `depends_on` option is
 * off/empty (the whole-field twin of TableControl's per-column handling).
 * Admin affordance only: the module still guards at runtime, and the stored
 * value is greyed, never forced off.
 *
 * @param {Object} field  Field description from the bootstrap data.
 * @param {Object} values Option key => current UI value map.
 * @return {boolean} True when the control must be disabled.
 */
export function isFieldDisabled( field, values ) {
	const dependency = field && field.depends_on;

	if ( ! dependency ) {
		return false;
	}

	// A comma separated list: any one of the targets on keeps the field enabled.
	return ! dependencyKeys( dependency ).some( ( key ) =>
		isValueSet( values ? values[ key ] : undefined )
	);
}

/**
 * The keys named by a `depends_on` value (one, or several comma separated).
 *
 * @param {string} dependency The field's `depends_on`.
 * @return {string[]} The dependency keys, trimmed and non-empty.
 */
function dependencyKeys( dependency ) {
	return String( dependency )
		.split( ',' )
		.map( ( key ) => key.trim() )
		.filter( ( key ) => '' !== key );
}

/**
 * The label of the field a field depends on, for the disabled explanation
 * (the dependency often lives in another tab).
 *
 * @param {Object}   field     Field description from the bootstrap data.
 * @param {Object[]} allFields Every field of the module, across its groups.
 * @return {string} The dependency's label, or '' when it cannot be resolved.
 */
export function dependencyLabel( field, allFields ) {
	const dependency = field && field.depends_on;

	if ( ! dependency || ! Array.isArray( allFields ) ) {
		return '';
	}

	return dependencyKeys( dependency )
		.map( ( key ) => {
			const target = allFields.find(
				( candidate ) => candidate && candidate.key === key
			);
			return target && target.label ? target.label : '';
		} )
		.filter( ( label ) => '' !== label )
		.join( ' / ' );
}

/**
 * Whether a table cell is read-only because a `GTM4WP_HARDCODED_*` constant
 * controls it. A locked row set locks every cell: no row of the admin's own
 * is left to save into.
 *
 * @param {Object} field  Field description from the bootstrap data.
 * @param {Object} column Column description of that field.
 * @return {boolean} True when the cell must be rendered read-only.
 */
export function isCellLocked( field, column ) {
	return (
		Boolean( field && field.rows_locked ) ||
		Boolean( column && column.readonly )
	);
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
 * Resolves a deep link `?<queryArg>=<option key>` (the argument name comes
 * from the bootstrap data, `focusArg`). The module and group are looked up in
 * the schema, so a link survives a field moving. Nothing from the URL is
 * returned: only an existing FIELD's own values, or null.
 *
 * @param {Array}  modules  Module descriptions from the bootstrap data.
 * @param {string} search   Query string, e.g. `window.location.search`.
 * @param {string} queryArg Name of the query argument carrying the option key.
 * @return {?Object} `{ moduleId, groupId, fieldKey }`, or null when there is no
 *                   deep link or it names an option this install does not have.
 */
export function focusTarget( modules, search, queryArg ) {
	if ( ! queryArg ) {
		return null;
	}

	const wanted = new URLSearchParams( String( search ?? '' ) ).get(
		queryArg
	);

	if ( ! wanted ) {
		return null;
	}

	for ( const module of modules ?? [] ) {
		const field = ( module.fields ?? [] ).find(
			( candidate ) => candidate.key === wanted
		);

		if ( field ) {
			return {
				moduleId: module.id,
				groupId: field.group,
				fieldKey: field.key,
			};
		}
	}

	return null;
}

/**
 * The groups of a module that hold fields, with their fields attached; an
 * empty declared group is dropped. Shared by ModulePanel and the URL logic.
 *
 * @param {Object} module Module description from the bootstrap data.
 * @return {Array} Groups holding at least one field, in declared order.
 */
export function groupsWithFields( module ) {
	const groups = module && module.groups ? module.groups : [];
	const fields = module && module.fields ? module.fields : [];

	return groups
		.map( ( group ) => ( {
			...group,
			fields: fields.filter( ( field ) => field.group === group.id ),
		} ) )
		.filter( ( group ) => group.fields.length > 0 );
}

/**
 * The group a module opens on, or null when it has no tab bar (a single
 * populated group renders flat).
 *
 * @param {Object} module Module description from the bootstrap data.
 * @return {?string} Group id, or null.
 */
export function defaultGroupId( module ) {
	const groups = groupsWithFields( module );

	return groups.length > 1 ? groups[ 0 ].id : null;
}

/**
 * The location fragment for a position on the screen: `#<module>/<group>`,
 * or `#<module>` for a module with no tabs. A fragment, not a query argument:
 * in-page position never reaches the server.
 *
 * @param {string}  moduleId Active module id.
 * @param {?string} groupId  Active group id, if the module has tabs.
 * @return {string} Fragment including the leading `#`, or '' when there is no
 *                  module to point at.
 */
export function locationHash( moduleId, groupId ) {
	if ( ! moduleId ) {
		return '';
	}

	return groupId ? `#${ moduleId }/${ groupId }` : `#${ moduleId }`;
}

/**
 * Resolves a `#<module>/<group>` fragment back to a position. Every value
 * comes from the schema, never the fragment. An unknown module yields null;
 * an unknown group falls back to the module's first tab.
 *
 * @param {Array}  modules Module descriptions from the bootstrap data.
 * @param {string} hash    Fragment, e.g. `window.location.hash`.
 * @return {?Object} `{ moduleId, groupId }`, or null.
 */
export function locationTarget( modules, hash ) {
	const path = String( hash ?? '' ).replace( /^#/, '' );

	if ( '' === path ) {
		return null;
	}

	const wanted = path.split( '/' );
	const module = ( modules ?? [] ).find(
		( candidate ) => candidate.id === wanted[ 0 ]
	);

	if ( ! module ) {
		return null;
	}

	const wantedGroup = wanted[ 1 ];
	const groups = groupsWithFields( module );
	const group =
		groups.length > 1
			? groups.find( ( candidate ) => candidate.id === wantedGroup )
			: undefined;

	return {
		moduleId: module.id,
		groupId: group ? group.id : defaultGroupId( module ),
	};
}

/**
 * Where the screen opens: a `?gtm4wp-focus=` deep link (clicked
 * deliberately), then a `#module/tab` bookmark, then the first module.
 *
 * @param {Array}  modules  Module descriptions from the bootstrap data.
 * @param {Object} location Location-like object with `search` and `hash`.
 * @param {string} focusArg Name of the deep-link query argument.
 * @return {Object} `{ moduleId, groupId, focusFieldKey }`; ids are '' / null
 *                  when there is nothing to show.
 */
export function openingPosition( modules, location, focusArg ) {
	const list = modules ?? [];
	const focused = focusTarget( list, location.search, focusArg );

	if ( focused ) {
		const module = list.find(
			( candidate ) => candidate.id === focused.moduleId
		);
		const groups = groupsWithFields( module );
		const linked = groups.some( ( group ) => group.id === focused.groupId );

		return {
			moduleId: focused.moduleId,
			groupId:
				linked && groups.length > 1
					? focused.groupId
					: defaultGroupId( module ),
			focusFieldKey: focused.fieldKey,
		};
	}

	const bookmarked = locationTarget( list, location.hash );

	if ( bookmarked ) {
		return { ...bookmarked, focusFieldKey: null };
	}

	return {
		moduleId: list.length > 0 ? list[ 0 ].id : '',
		groupId: list.length > 0 ? defaultGroupId( list[ 0 ] ) : null,
		focusFieldKey: null,
	};
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
 * Builds the SelectControl options for the Axeptio cookies-version field,
 * keeping the saved value representable when the fetch fails or the version
 * was removed (so a save never drops it).
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

	// A saved value no longer published (or not loaded) must survive a save.
	if ( '' !== current && ! names.has( current ) ) {
		options.push( { value: current, label: current } );
	}

	return options;
}
