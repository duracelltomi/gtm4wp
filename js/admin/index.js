/**
 * Entry point of the GTM4WP settings app.
 */

import { createRoot } from '@wordpress/element';

import App from './App';
import './style.scss';

const container = document.getElementById( 'gtm4wp-admin-app' );

if ( container && window.gtm4wpSettings ) {
	createRoot( container ).render(
		<App settings={ window.gtm4wpSettings } />
	);
}
