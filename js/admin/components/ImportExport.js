/**
 * Export / import controls of the GTM4WP admin app.
 *
 * Export downloads the current settings as a JSON file; import uploads such a
 * file. The server re-runs every imported value through its schema sanitizer,
 * so the file is never trusted here - this component only moves bytes.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { exportFilename } from '../utils';

export default function ImportExport( {
	exportPath,
	importPath,
	onImported,
	onNotice,
} ) {
	const fileInputRef = useRef( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const onExport = async () => {
		setIsBusy( true );

		try {
			const data = await apiFetch( { path: exportPath } );
			const json = JSON.stringify( data, null, 2 );
			const blob = new window.Blob( [ json ], {
				type: 'application/json',
			} );
			const url = window.URL.createObjectURL( blob );

			const link = document.createElement( 'a' );
			link.href = url;
			link.download = exportFilename();
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			window.URL.revokeObjectURL( url );
		} catch ( error ) {
			onNotice(
				error.message ||
					__(
						'Export failed. Please try again.',
						'duracelltomi-google-tag-manager'
					)
			);
		} finally {
			setIsBusy( false );
		}
	};

	const onFileChange = async ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];

		// Reset the input so choosing the same file again still fires change.
		event.target.value = '';

		if ( ! file ) {
			return;
		}

		setIsBusy( true );

		try {
			const payload = await file.text();
			const response = await apiFetch( {
				path: importPath,
				method: 'POST',
				data: { payload },
			} );

			if ( response && response.imported ) {
				onImported( response.values || {} );
				onNotice(
					__(
						'Settings imported.',
						'duracelltomi-google-tag-manager'
					)
				);
			} else {
				if ( response && response.values ) {
					onImported( response.values );
				}
				onNotice(
					__(
						'Imported with errors — some values were rejected and left at their defaults.',
						'duracelltomi-google-tag-manager'
					)
				);
			}
		} catch ( error ) {
			onNotice(
				error.message ||
					__(
						'Import failed. Make sure the file is a GTM4WP settings export.',
						'duracelltomi-google-tag-manager'
					)
			);
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<div className="gtm4wp-app__io">
			<Button
				variant="secondary"
				disabled={ isBusy }
				onClick={ onExport }
			>
				{ __( 'Export settings', 'duracelltomi-google-tag-manager' ) }
			</Button>
			<Button
				variant="secondary"
				disabled={ isBusy }
				onClick={ () =>
					fileInputRef.current && fileInputRef.current.click()
				}
			>
				{ __( 'Import settings', 'duracelltomi-google-tag-manager' ) }
			</Button>
			<input
				ref={ fileInputRef }
				type="file"
				accept="application/json,.json"
				className="gtm4wp-app__file-input"
				onChange={ onFileChange }
			/>
		</div>
	);
}
