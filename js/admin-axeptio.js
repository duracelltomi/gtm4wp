/**
 * GTM4WP Axeptio admin settings.
 *
 * Fills the cookies version <select> with the versions published for the configured Axeptio project,
 * so the value can be picked from the real list instead of being typed by hand.
 */
document.addEventListener( 'DOMContentLoaded', () => {
	const projectIdInput = document.getElementById( 'gtm4wp-options[integrate-axeptio-projectid]' );
	const versionSelect  = document.getElementById( 'gtm4wp-options[integrate-axeptio-cookies-version]' );
	const errorMessage   = document.querySelector( '.axeptio_cookies_version_error' );

	if ( ! projectIdInput || ! versionSelect ) {
		return;
	}

	const showError = ( message ) => {
		if ( errorMessage ) {
			errorMessage.textContent = message;
			errorMessage.style.display = message ? 'block' : '';
		}
	};

	const fillVersions = ( cookies ) => {
		const selectedVersion = versionSelect.dataset.selectedVersion || '';
		const titlesByName = new Map(
			cookies.filter( ( cookie ) => cookie.name ).map( ( cookie ) => [ cookie.name, cookie.title || cookie.name ] )
		);

		versionSelect.replaceChildren(
			...[ ...titlesByName ].map( ( [ name, title ] ) => new Option( title, name, false, name === selectedVersion ) )
		);
	};

	const loadVersions = () => {
		const projectId = projectIdInput.value.trim();
		showError( '' );

		if ( ! projectId ) {
			return;
		}

		fetch( `https://client.axept.io/${ encodeURIComponent( projectId ) }.json?nocache=${ Date.now() }` )
			.then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( 'Network response was not ok' );
				}

				return response.json();
			} )
			.then( ( data ) => {
				if ( data.cookies && data.cookies.length ) {
					fillVersions( data.cookies );
				} else {
					showError( gtm4wpAxeptio.non_existing_account_id );
				}
			} )
			.catch( () => showError( gtm4wpAxeptio.verification_error ) );
	};

	projectIdInput.addEventListener( 'change', loadVersions );

	if ( projectIdInput.value.trim() ) {
		loadVersions();
	}
} );
