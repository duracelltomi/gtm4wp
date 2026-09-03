/**
 * Google service accounts panel: the custody UI of the google-auth module.
 *
 * Lists the stored accounts, uploads a key file, renames an account, tests one
 * against Google's token endpoint and deletes one. The key file is read here
 * only to be posted; the server parses and encrypts it and answers with the
 * public view of the account, which is all this panel ever renders. There is
 * deliberately no way to read a key back, so nothing here shows, stores or
 * logs one - and the label is the only stored field that can be edited, since
 * everything else describes the immutable uploaded key.
 */

import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner, TextControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Human wording of a stored account status. The status strings are the
 * server's KeyVault::STATUS_* constants; an unknown one is shown verbatim
 * rather than hidden, so a new server status is visible before this map
 * learns it.
 *
 * @param {string} status Account status.
 * @return {string} Translated status text.
 */
function statusText( status ) {
	switch ( status ) {
		case 'ok':
			return __( 'Working', 'duracelltomi-google-tag-manager' );
		case 'error':
			return __( 'Failed', 'duracelltomi-google-tag-manager' );
		case 'reupload-required':
			return __(
				'Key unreadable, upload the key file again',
				'duracelltomi-google-tag-manager'
			);
		case 'unverified':
			return __( 'Not tested yet', 'duracelltomi-google-tag-manager' );
		default:
			return status;
	}
}

/**
 * Locale-formatted date of a unix timestamp, or a dash for "never".
 *
 * @param {number} timestamp Seconds since the epoch; 0 for never.
 * @return {string} Display text.
 */
function dateText( timestamp ) {
	if ( ! timestamp ) {
		return '—';
	}

	return new Date( timestamp * 1000 ).toLocaleDateString();
}

/**
 * Message of a failed REST call. apiFetch rejects with the parsed WP_Error
 * body for a refused request and with a plain Error for a network failure;
 * both carry `message`.
 *
 * @param {Object} error    What apiFetch rejected with.
 * @param {string} fallback Text when the failure carries none.
 * @return {string} Message to show.
 */
function errorText( error, fallback ) {
	return ( error && error.message ) || fallback;
}

export default function ServiceAccountsPanel( { data } ) {
	const { restPath, keyFileMaxBytes, labelMaxLength } = data;

	const fileInputRef = useRef( null );
	const [ accounts, setAccounts ] = useState( null );
	const [ label, setLabel ] = useState( '' );
	const [ pendingFile, setPendingFile ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ pendingDeleteId, setPendingDeleteId ] = useState( null );
	const [ editingId, setEditingId ] = useState( null );
	const [ editingLabel, setEditingLabel ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		apiFetch( { path: restPath } )
			.then( ( response ) => {
				if ( ! cancelled ) {
					setAccounts( ( response && response.accounts ) || [] );
				}
			} )
			.catch( ( error ) => {
				if ( ! cancelled ) {
					setAccounts( [] );
					setNotice( {
						status: 'error',
						text: errorText(
							error,
							__(
								'The service accounts could not be loaded.',
								'duracelltomi-google-tag-manager'
							)
						),
					} );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ restPath ] );

	// Selecting a file only keeps it for the Add button: nothing is sent yet,
	// so the label stays editable in either order (file first or label first).
	const onFileChange = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];

		// Reset the input so choosing the same file again still fires change.
		event.target.value = '';

		if ( ! file ) {
			return;
		}

		// Mirrors the server's cap. A real key file is around 2 KB; anything
		// over the cap is not one, and refusing it here spares the round trip.
		if ( file.size > keyFileMaxBytes ) {
			setNotice( {
				status: 'error',
				text: __(
					'That file is too large to be a service account key file.',
					'duracelltomi-google-tag-manager'
				),
			} );
			return;
		}

		setPendingFile( file );
		setNotice( null );
	};

	const onUpload = async () => {
		// The disabled={isBusy} prop reaches the controls through the component
		// library's pass-through and cannot be trusted across the supported WP
		// range, so every mutation handler re-checks - the TableControl
		// isCellLocked() pattern. One request at a time.
		if ( ! pendingFile || isBusy ) {
			return;
		}

		setIsBusy( true );
		setNotice( null );

		try {
			const keyFile = await pendingFile.text();
			const response = await apiFetch( {
				path: restPath,
				method: 'POST',
				data: { label, key_file: keyFile },
			} );

			setAccounts( response.accounts || [] );
			setLabel( '' );
			setPendingFile( null );
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: %s: label of the service account. */
					__(
						'Service account %s added. Use Test to check that it can reach Google.',
						'duracelltomi-google-tag-manager'
					),
					response.account ? response.account.label : ''
				),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				text: errorText(
					error,
					__(
						'The key file could not be uploaded.',
						'duracelltomi-google-tag-manager'
					)
				),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const onResetForm = () => {
		if ( isBusy ) {
			return;
		}

		setLabel( '' );
		setPendingFile( null );
	};

	const onRenameStart = ( account ) => {
		setEditingId( account.id );
		setEditingLabel( account.label );
		setPendingDeleteId( null );
	};

	const onRename = async ( account ) => {
		// Handler-level twin of disabled={isBusy} - see onUpload.
		if ( isBusy ) {
			return;
		}

		setIsBusy( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `${ restPath }/${ account.id }`,
				method: 'POST',
				data: { label: editingLabel },
			} );

			setAccounts( response.accounts || [] );
			setEditingId( null );
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: %s: new label of the service account. */
					__(
						'Service account renamed to %s.',
						'duracelltomi-google-tag-manager'
					),
					response.account ? response.account.label : ''
				),
			} );
		} catch ( error ) {
			// The row stays in edit mode so the admin can correct and retry.
			setNotice( {
				status: 'error',
				text: errorText(
					error,
					__(
						'The service account could not be renamed.',
						'duracelltomi-google-tag-manager'
					)
				),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const onTest = async ( account ) => {
		// Handler-level twin of disabled={isBusy} - see onFileChange.
		if ( isBusy ) {
			return;
		}

		setIsBusy( true );
		setNotice( null );

		try {
			const response = await apiFetch( {
				path: `${ restPath }/${ account.id }/test`,
				method: 'POST',
			} );

			if ( response.account ) {
				setAccounts( ( current ) =>
					( current || [] ).map( ( candidate ) =>
						candidate.id === account.id
							? response.account
							: candidate
					)
				);
			}

			setNotice( {
				status: response.ok ? 'success' : 'error',
				text: response.message,
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				text: errorText(
					error,
					__(
						'The service account could not be tested.',
						'duracelltomi-google-tag-manager'
					)
				),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const onDelete = async ( account ) => {
		// Handler-level twin of disabled={isBusy} - see onFileChange.
		if ( isBusy ) {
			return;
		}

		setIsBusy( true );
		setNotice( null );
		setPendingDeleteId( null );

		try {
			const response = await apiFetch( {
				path: `${ restPath }/${ account.id }`,
				method: 'DELETE',
			} );

			setAccounts( response.accounts || [] );
			setNotice( {
				status: 'success',
				text: sprintf(
					/* translators: %s: label of the service account. */
					__(
						'Service account %s deleted.',
						'duracelltomi-google-tag-manager'
					),
					account.label
				),
			} );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				text: errorText(
					error,
					__(
						'The service account could not be deleted.',
						'duracelltomi-google-tag-manager'
					)
				),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<div className="gtm4wp-accounts">
			{ notice && (
				<Notice
					className="gtm4wp-accounts__notice"
					status={ notice.status }
					isDismissible={ false }
				>
					{ notice.text }
				</Notice>
			) }

			{ null === accounts ? (
				<Spinner />
			) : (
				<AccountsTable
					accounts={ accounts }
					isBusy={ isBusy }
					pendingDeleteId={ pendingDeleteId }
					editingId={ editingId }
					editingLabel={ editingLabel }
					onEditLabel={ setEditingLabel }
					onRenameStart={ onRenameStart }
					onRenameCancel={ () => setEditingId( null ) }
					onRename={ onRename }
					onTest={ onTest }
					onDeleteRequest={ setPendingDeleteId }
					onDelete={ onDelete }
				/>
			) }

			<div className="gtm4wp-accounts__upload">
				<h3>
					{ __(
						'Add a service account',
						'duracelltomi-google-tag-manager'
					) }
				</h3>
				<p>
					{ __(
						'Upload the JSON key file downloaded from Google Cloud IAM. The private key inside it is stored encrypted and is never shown again, so keep your own copy of the file.',
						'duracelltomi-google-tag-manager'
					) }
				</p>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Label', 'duracelltomi-google-tag-manager' ) }
					help={ sprintf(
						/* translators: %d: maximum label length in characters. */
						__(
							'Optional name shown instead of the account email, up to %d characters.',
							'duracelltomi-google-tag-manager'
						),
						labelMaxLength
					) }
					value={ label }
					disabled={ isBusy }
					onChange={ setLabel }
				/>
				<div className="gtm4wp-accounts__upload-file">
					<Button
						variant="secondary"
						disabled={ isBusy }
						onClick={ () =>
							fileInputRef.current && fileInputRef.current.click()
						}
					>
						{ __(
							'Choose key file',
							'duracelltomi-google-tag-manager'
						) }
					</Button>
					<span className="gtm4wp-accounts__upload-filename">
						{ pendingFile
							? pendingFile.name
							: __(
									'No file selected yet.',
									'duracelltomi-google-tag-manager'
							  ) }
					</span>
				</div>
				<input
					ref={ fileInputRef }
					type="file"
					accept="application/json,.json"
					className="gtm4wp-app__file-input"
					onChange={ onFileChange }
				/>
				<div className="gtm4wp-accounts__upload-actions">
					<Button
						variant="primary"
						disabled={ isBusy || ! pendingFile }
						isBusy={ isBusy }
						onClick={ onUpload }
					>
						{ __(
							'Add service account',
							'duracelltomi-google-tag-manager'
						) }
					</Button>
					<Button
						variant="tertiary"
						disabled={ isBusy || ( ! pendingFile && '' === label ) }
						onClick={ onResetForm }
					>
						{ __( 'Reset', 'duracelltomi-google-tag-manager' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}

function AccountsTable( {
	accounts,
	isBusy,
	pendingDeleteId,
	editingId,
	editingLabel,
	onEditLabel,
	onRenameStart,
	onRenameCancel,
	onRename,
	onTest,
	onDeleteRequest,
	onDelete,
} ) {
	if ( 0 === accounts.length ) {
		return (
			<p className="gtm4wp-accounts__empty">
				{ __(
					'No service accounts stored yet.',
					'duracelltomi-google-tag-manager'
				) }
			</p>
		);
	}

	return (
		<div className="gtm4wp-accounts__scroll">
			<table className="gtm4wp-accounts__table">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Label', 'duracelltomi-google-tag-manager' ) }
						</th>
						<th scope="col">
							{ __(
								'Service account',
								'duracelltomi-google-tag-manager'
							) }
						</th>
						<th scope="col">
							{ __(
								'Key ID',
								'duracelltomi-google-tag-manager'
							) }
						</th>
						<th scope="col">
							{ __(
								'Uploaded',
								'duracelltomi-google-tag-manager'
							) }
						</th>
						<th scope="col">
							{ __(
								'Status',
								'duracelltomi-google-tag-manager'
							) }
						</th>
						<th scope="col">
							<span className="screen-reader-text">
								{ __(
									'Actions',
									'duracelltomi-google-tag-manager'
								) }
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					{ accounts.map( ( account ) => (
						<tr key={ account.id }>
							<td>
								{ editingId === account.id ? (
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										label={ sprintf(
											/* translators: %s: e-mail of the service account. */
											__(
												'New label for %s',
												'duracelltomi-google-tag-manager'
											),
											account.client_email
										) }
										hideLabelFromVision
										value={ editingLabel }
										disabled={ isBusy }
										onChange={ onEditLabel }
									/>
								) : (
									account.label
								) }
							</td>
							<td>
								<code>{ account.client_email }</code>
							</td>
							<td>
								<code>{ account.private_key_id || '—' }</code>
							</td>
							<td>{ dateText( account.uploaded_at ) }</td>
							<td>
								<span
									className={ `gtm4wp-accounts__status gtm4wp-accounts__status--${ account.status }` }
								>
									{ statusText( account.status ) }
								</span>
								{ account.last_error && (
									<span className="gtm4wp-accounts__error">
										{ account.last_error }
									</span>
								) }
							</td>
							<td className="gtm4wp-accounts__actions">
								<RowActions
									account={ account }
									isBusy={ isBusy }
									isEditing={ editingId === account.id }
									isDeleting={
										pendingDeleteId === account.id
									}
									onRenameStart={ onRenameStart }
									onRenameCancel={ onRenameCancel }
									onRename={ onRename }
									onTest={ onTest }
									onDeleteRequest={ onDeleteRequest }
									onDelete={ onDelete }
								/>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

// The action buttons of one account row, in one of three mutually exclusive
// states: editing the label, confirming a delete, or the resting set.
function RowActions( {
	account,
	isBusy,
	isEditing,
	isDeleting,
	onRenameStart,
	onRenameCancel,
	onRename,
	onTest,
	onDeleteRequest,
	onDelete,
} ) {
	if ( isEditing ) {
		return (
			<>
				<Button
					variant="primary"
					disabled={ isBusy }
					isBusy={ isBusy }
					label={ sprintf(
						/* translators: %s: label of the service account. */
						__(
							'Save the new label of %s',
							'duracelltomi-google-tag-manager'
						),
						account.label
					) }
					onClick={ () => onRename( account ) }
				>
					{ __( 'Save', 'duracelltomi-google-tag-manager' ) }
				</Button>
				<Button
					variant="tertiary"
					disabled={ isBusy }
					onClick={ onRenameCancel }
				>
					{ __( 'Cancel', 'duracelltomi-google-tag-manager' ) }
				</Button>
			</>
		);
	}

	if ( isDeleting ) {
		return (
			<>
				<Button
					variant="primary"
					isDestructive
					disabled={ isBusy }
					label={ sprintf(
						/* translators: %s: label of the service account. */
						__(
							'Confirm deleting %s',
							'duracelltomi-google-tag-manager'
						),
						account.label
					) }
					onClick={ () => onDelete( account ) }
				>
					{ __(
						'Confirm delete',
						'duracelltomi-google-tag-manager'
					) }
				</Button>
				<Button
					variant="tertiary"
					disabled={ isBusy }
					onClick={ () => onDeleteRequest( null ) }
				>
					{ __( 'Cancel', 'duracelltomi-google-tag-manager' ) }
				</Button>
			</>
		);
	}

	return (
		<>
			<Button
				variant="secondary"
				disabled={ isBusy }
				label={ sprintf(
					/* translators: %s: label of the service account. */
					__( 'Test %s', 'duracelltomi-google-tag-manager' ),
					account.label
				) }
				onClick={ () => onTest( account ) }
			>
				{ __( 'Test', 'duracelltomi-google-tag-manager' ) }
			</Button>
			<Button
				variant="tertiary"
				disabled={ isBusy }
				label={ sprintf(
					/* translators: %s: label of the service account. */
					__( 'Rename %s', 'duracelltomi-google-tag-manager' ),
					account.label
				) }
				onClick={ () => onRenameStart( account ) }
			>
				{ __( 'Rename', 'duracelltomi-google-tag-manager' ) }
			</Button>
			{ /* Icon-only, matching the remove-row button every TableControl
			     table (containers, Data Manager destinations) renders. The
			     accessible name stays "Delete <label>" via the label prop. */ }
			<Button
				icon="trash"
				isDestructive
				disabled={ isBusy }
				label={ sprintf(
					/* translators: %s: label of the service account. */
					__( 'Delete %s', 'duracelltomi-google-tag-manager' ),
					account.label
				) }
				onClick={ () => onDeleteRequest( account.id ) }
			/>
		</>
	);
}
