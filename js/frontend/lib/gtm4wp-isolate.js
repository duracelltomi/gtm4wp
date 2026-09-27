/**
 * Runs tracking code from inside another plugin's jQuery trigger without letting
 * it throw there (RI-35). jQuery calls handlers with no try/catch, so a throw
 * unwinds through the caller and skips its code after the trigger: WooCommerce's
 * checkout submit (#472), its variation form's unblock (#327), EDD's gateway
 * loader and the gateway scripts bound after ours (#329). The error is re-thrown
 * on a timer so it still reaches the console.
 *
 * @param {Function} callback The tracking code to run.
 */
export function gtm4wp_run_isolated( callback ) {
	try {
		callback();
	} catch ( e ) {
		setTimeout( function () {
			throw e;
		}, 0 );
	}
}
