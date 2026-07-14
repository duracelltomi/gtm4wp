/**
 * Cookies-version field for the Axeptio integration.
 *
 * The published versions of an Axeptio project cannot be known server side, so
 * the option list is fetched from the Axeptio project API using the Project ID
 * entered in the sibling field. When that list loads, the field is a dropdown;
 * when it cannot be loaded (no versions published, or the request is blocked by
 * a strict admin CSP / ad blocker / offline wp-admin), the field degrades to a
 * free-text input so the version can still be entered manually. The currently
 * saved value is always kept selectable (see axeptioVersionOptions) so a save
 * survives a failed fetch either way.
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
	onChange,
} ) {
	const projectId = String( allValues?.[ PROJECT_ID_KEY ] ?? '' ).trim();

	const [ cookies, setCookies ] = useState( [] );
	const [ fetchError, setFetchError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );

	// Re-fetch whenever the Project ID changes. The saved value is merged into
	// the option list at render time, so this effect only depends on projectId.
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

	// Fall back to manual entry once a Project ID is set but its version list
	// could not be loaded, so a locked-down or offline admin is never stuck.
	const manualEntry = '' !== projectId && ! isLoading && '' !== fetchError;

	if ( manualEntry ) {
		return (
			<>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					help={ help }
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
			disabled={ '' === projectId }
			onChange={ ( next ) => onChange( next ) }
		/>
	);
}
