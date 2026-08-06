/**
 * GTM4WP settings app: left pane navigation, accordion field groups,
 * schema driven controls and REST based saving.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Snackbar } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ImportExport from './components/ImportExport';
import ModulePanel from './components/ModulePanel';
import Sidebar from './components/Sidebar';
import { buildValueMap, changedValues, focusTarget } from './utils';

export default function App( { settings } ) {
	const modules = settings.modules;

	// Deep link (`?gtm4wp-focus=<option key>`, printed by SettingsPage::url()):
	// resolved once at mount into the module, group tab and field it names, so a
	// link from an admin notice lands on the control instead of on the screen.
	// Cleared as soon as the visitor navigates themselves, otherwise coming back
	// to that module would keep re-opening the linked tab.
	const [ focus, setFocus ] = useState( () =>
		focusTarget( modules, window.location.search, settings.focusArg )
	);

	const [ initialValues, setInitialValues ] = useState( () =>
		buildValueMap( modules )
	);
	const [ values, setValues ] = useState( initialValues );
	const [ errors, setErrors ] = useState( {} );
	const [ activeModuleId, setActiveModuleId ] = useState( () => {
		if ( focus ) {
			return focus.moduleId;
		}

		return modules.length > 0 ? modules[ 0 ].id : '';
	} );
	const [ search, setSearch ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ snackbar, setSnackbar ] = useState( null );

	const activeModule = modules.find(
		( module ) => module.id === activeModuleId
	);

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

	const onModuleSelect = ( moduleId ) => {
		setFocus( null );
		setActiveModuleId( moduleId );
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
					activeModuleId={ activeModuleId }
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
						focus={
							focus && focus.moduleId === activeModule.id
								? focus
								: null
						}
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
