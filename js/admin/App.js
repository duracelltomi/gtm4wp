/**
 * GTM4WP settings app: left pane navigation, accordion field groups,
 * schema driven controls and REST based saving.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Snackbar } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ImportExport from './components/ImportExport';
import ModulePanel from './components/ModulePanel';
import Sidebar from './components/Sidebar';
import {
	buildValueMap,
	changedValues,
	defaultGroupId,
	locationHash,
	openingPosition,
} from './utils';

export default function App( { settings } ) {
	const modules = settings.modules;

	// Where on the screen we are: which module, which tab of it, and whether a
	// deep link's highlight is still live. One object rather than three pieces
	// of state because they always move together - every navigation decides all
	// three - and because it is exactly what the location fragment encodes.
	//
	// Resolved once at mount: a `?gtm4wp-focus=<option key>` link from an admin
	// notice first, then a `#module/tab` bookmark, then the first module.
	const [ position, setPosition ] = useState( () =>
		openingPosition( modules, window.location, settings.focusArg )
	);

	const [ initialValues, setInitialValues ] = useState( () =>
		buildValueMap( modules )
	);
	const [ values, setValues ] = useState( initialValues );
	const [ errors, setErrors ] = useState( {} );
	const [ search, setSearch ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ snackbar, setSnackbar ] = useState( null );

	const activeModule = modules.find(
		( module ) => module.id === position.moduleId
	);

	// Keep the address bar pointing at where we are, so the URL can be copied or
	// bookmarked at any moment. replaceState, never pushState: the back button
	// keeps meaning "leave this screen" rather than silently becoming a tab
	// stepper, and nothing here needs a popstate handler to stay consistent.
	//
	// Only the fragment is rewritten, so `?page=…` (and the `gtm4wp-focus`
	// argument that may have brought us here) is left exactly as it was.
	useEffect( () => {
		const hash = locationHash( position.moduleId, position.groupId );

		if ( '' !== hash && hash !== window.location.hash ) {
			window.history.replaceState( null, '', hash );
		}
	}, [ position.moduleId, position.groupId ] );

	const changed = useMemo(
		() => changedValues( initialValues, values ),
		[ initialValues, values ]
	);
	const isDirty = Object.keys( changed ).length > 0;

	const dirtyModules = useMemo(
		() =>
			modules
				.filter( ( module ) =>
					module.fields.some( ( field ) =>
						Object.prototype.hasOwnProperty.call(
							changed,
							field.key
						)
					)
				)
				.map( ( module ) => module.id ),
		[ modules, changed ]
	);

	const onFieldChange = ( key, next ) => {
		setValues( ( previous ) => ( { ...previous, [ key ]: next } ) );
	};

	// Picking a module opens it on its own first tab and drops any deep-link
	// highlight: from here on the visitor is steering.
	const onModuleSelect = ( moduleId ) => {
		setPosition( {
			moduleId,
			groupId: defaultGroupId(
				modules.find( ( module ) => module.id === moduleId )
			),
			focusFieldKey: null,
		} );
	};

	// Reported by the tab bar. The highlight is deliberately kept: the linked
	// field is still what the visitor came for, they have just looked elsewhere.
	const onGroupSelect = ( groupId ) => {
		setPosition( ( previous ) => ( { ...previous, groupId } ) );
	};

	// After an import the server returns the freshly stored, sanitized values;
	// adopt them as the new baseline so nothing shows up as unsaved and the
	// panels reflect exactly what was persisted.
	const onImported = ( serverValues ) => {
		const next = buildValueMap( modules, serverValues );
		setInitialValues( next );
		setValues( next );
		setErrors( {} );
	};

	const onSave = async () => {
		setIsSaving( true );
		setSnackbar( null );

		try {
			const response = await apiFetch( {
				path: settings.restPath,
				method: 'POST',
				data: { values: changed },
			} );

			const nextErrors = response.errors || {};
			setErrors( nextErrors );

			// Accept everything the server accepted; rejected fields stay dirty.
			const accepted = { ...initialValues };
			Object.keys( changed ).forEach( ( key ) => {
				if (
					! Object.prototype.hasOwnProperty.call( nextErrors, key )
				) {
					accepted[ key ] = values[ key ];
				}
			} );
			setInitialValues( accepted );

			setSnackbar(
				response.saved
					? __( 'Settings saved.', 'duracelltomi-google-tag-manager' )
					: __(
							'Saved with errors — please review the highlighted fields.',
							'duracelltomi-google-tag-manager'
					  )
			);
		} catch ( error ) {
			setSnackbar(
				error.message ||
					__(
						'Saving failed. Please try again.',
						'duracelltomi-google-tag-manager'
					)
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<div className="gtm4wp-app">
			<header className="gtm4wp-app__header">
				<h1>
					{ __(
						'Google Tag Manager for WordPress',
						'duracelltomi-google-tag-manager'
					) }
				</h1>
				<div className="gtm4wp-app__actions">
					<ImportExport
						exportPath={ settings.exportPath }
						importPath={ settings.importPath }
						onImported={ onImported }
						onNotice={ setSnackbar }
					/>
					<Button
						variant="primary"
						disabled={ ! isDirty || isSaving }
						isBusy={ isSaving }
						onClick={ onSave }
					>
						{ isSaving
							? __( 'Saving…', 'duracelltomi-google-tag-manager' )
							: __(
									'Save changes',
									'duracelltomi-google-tag-manager'
							  ) }
					</Button>
				</div>
			</header>
			<div className="gtm4wp-app__body">
				<Sidebar
					modules={ modules }
					activeModuleId={ position.moduleId }
					onSelect={ onModuleSelect }
					search={ search }
					onSearch={ setSearch }
					dirtyModules={ dirtyModules }
				/>
				{ activeModule && (
					<ModulePanel
						module={ activeModule }
						values={ values }
						errors={ errors }
						activeGroupId={ position.groupId }
						focusFieldKey={ position.focusFieldKey }
						onGroupSelect={ onGroupSelect }
						onChange={ onFieldChange }
					/>
				) }
			</div>
			{ snackbar && (
				<div className="gtm4wp-app__snackbar">
					<Snackbar onDismiss={ () => setSnackbar( null ) }>
						{ snackbar }
					</Snackbar>
				</div>
			) }
		</div>
	);
}
