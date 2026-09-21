/**
 * GTM4WP settings app: left pane navigation, accordion field groups,
 * schema driven controls and REST based saving.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Snackbar } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ImportExport from './components/ImportExport';
import MobileNav from './components/MobileNav';
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

	// Position on the screen (module, tab, live deep-link highlight): one
	// object because every navigation decides all three. Resolved once at
	// mount: `?gtm4wp-focus=` link, then `#module/tab` bookmark, then the
	// first module.
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

	// Keeps the address bar bookmarkable. replaceState, never pushState: the
	// back button keeps meaning "leave this screen". Only the fragment is
	// rewritten; the query string stays as it was.
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

	// Nothing is written until Save, and a removed table row looks final, so
	// the browser asks before the tab is left. Registered only while there
	// is something to lose.
	useEffect( () => {
		if ( ! isDirty ) {
			return undefined;
		}

		const warn = ( event ) => {
			event.preventDefault();

			// Browsers ignore the wording; returnValue is what they need.
			event.returnValue = '';

			return '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ isDirty ] );

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

	// Opens the module on its first tab and drops the deep-link highlight.
	const onModuleSelect = ( moduleId ) => {
		setPosition( {
			moduleId,
			groupId: defaultGroupId(
				modules.find( ( module ) => module.id === moduleId )
			),
			focusFieldKey: null,
		} );
	};

	// From the tab bar; the deep-link highlight is deliberately kept.
	const onGroupSelect = ( groupId ) => {
		setPosition( ( previous ) => ( { ...previous, groupId } ) );
	};

	// After an import the server's stored values become the new baseline.
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
					{ /* An enabled Save button is a weak signal - it says what
					     you may do, not that anything is waiting. role=status
					     announces it to a screen reader the moment an edit
					     makes it appear. */ }
					{ isDirty && (
						<span className="gtm4wp-app__unsaved" role="status">
							{ __(
								'Unsaved changes',
								'duracelltomi-google-tag-manager'
							) }
						</span>
					) }
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
			{ /* Phone navigation; the stylesheet hides whichever of this and
			     the left pane below does not fit the viewport. */ }
			<MobileNav
				modules={ modules }
				activeModuleId={ position.moduleId }
				onSelect={ onModuleSelect }
				search={ search }
				onSearch={ setSearch }
				dirtyModules={ dirtyModules }
			/>
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
