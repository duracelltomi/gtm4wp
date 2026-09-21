/**
 * Cookies-version field for the Axeptio integration: a dropdown of the
 * versions fetched from the Axeptio project API by Project ID, degrading to
 * a free-text input when the list cannot be loaded (none published, CSP, ad
 * blocker, offline). The saved value is always kept selectable.
 */

import { SelectControl, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { axeptioVersionOptions } from '../utils';

// Stable public option key of the Axeptio Project ID field (compat/constants.php).
const PROJECT_ID_KEY = 'integrate-axeptio-projectid';

export default function AxeptioVersionControl( {
	value,
	allValues,
	label,
	help,
	disabled = false,
	onChange,
} ) {
	const projectId = String( allValues?.[ PROJECT_ID_KEY ] ?? '' ).trim();

	const [ cookies, setCookies ] = useState( [] );
	const [ fetchError, setFetchError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	// Re-fetch on Project ID change; the saved value is merged at render.
	useEffect( () => {
		if ( '' === projectId ) {
			setCookies( [] );
			setFetchError( '' );
			setIsLoading( false );
			return undefined;
		}

		let cancelled = false;
		setIsLoading( true );
		setFetchError( '' );

		window
			.fetch(
				`https://client.axept.io/${ encodeURIComponent(
					projectId
				) }.json?nocache=${ Date.now() }`
			)
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok' );
				}

				return response.json();
			} )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}

				const list =
					data && Array.isArray( data.cookies ) ? data.cookies : [];

				if ( list.length ) {
					setCookies( list );
					setFetchError( '' );
				} else {
					setCookies( [] );
					setFetchError(
						__(
							'We were unable to find your Axeptio project, or it has not been published yet. Enter the cookies version manually below.',
							'duracelltomi-google-tag-manager'
						)
					);
				}
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}

				setCookies( [] );
				setFetchError(
					__(
						'The Axeptio project versions could not be loaded. Enter the cookies version manually below.',
						'duracelltomi-google-tag-manager'
					)
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ projectId ] );

	// Manual entry when a Project ID is set but its list could not load.
	const manualEntry = '' !== projectId && ! isLoading && '' !== fetchError;

	if ( manualEntry ) {
		return (
			<>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
					disabled={ disabled }
					value={ String( value ?? '' ) }
					onChange={ ( next ) => onChange( next ) }
				/>
				<p className="gtm4wp-field-error">{ fetchError }</p>
			</>
		);
	}

	const placeholderLabel =
		'' === projectId
			? __(
					'Enter your Project ID above to load versions',
					'duracelltomi-google-tag-manager'
			  )
			: __( '— Select a version —', 'duracelltomi-google-tag-manager' );

	const options = axeptioVersionOptions( cookies, value, placeholderLabel );

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			value={ String( value ?? '' ) }
			options={ options }
			disabled={ disabled || '' === projectId }
			onChange={ ( next ) => onChange( next ) }
		/>
	);
}
