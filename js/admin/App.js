/**
 * GTM4WP settings app: left pane navigation, accordion field groups,
 * schema driven controls and REST based saving.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Snackbar } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ModulePanel from './components/ModulePanel';
import Sidebar from './components/Sidebar';
import { changedValues, coerceValue } from './utils';

function initialValueMap( modules ) {
	const values = {};

	modules.forEach( ( module ) => {
		module.fields.forEach( ( field ) => {
			values[ field.key ] = coerceValue( field, field.value );
		} );
	} );

	return values;
}

export default function App( { settings } ) {
	const modules = settings.modules;

	const [ initialValues, setInitialValues ] = useState( () =>
		initialValueMap( modules )
	);
	const [ values, setValues ] = useState( initialValues );
	const [ errors, setErrors ] = useState( {} );
	const [ activeModuleId, setActiveModuleId ] = useState(
		modules.length > 0 ? modules[ 0 ].id : ''
	);
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
			</header>
			<div className="gtm4wp-app__body">
				<Sidebar
					modules={ modules }
					activeModuleId={ activeModuleId }
					onSelect={ setActiveModuleId }
					search={ search }
					onSearch={ setSearch }
					dirtyModules={ dirtyModules }
				/>
				{ activeModule && (
					<ModulePanel
						module={ activeModule }
						values={ values }
						errors={ errors }
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
