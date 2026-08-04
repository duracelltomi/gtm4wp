// Guard against double registration: a re-injected bundle (AJAX navigation, a page
// builder duplicating the handle) would otherwise attach these document-level
// listeners twice and double-push every form event. The media-tracker family and
// the CF7 tracker have carried this guard since #22 / #28; both times it was written
// as a media-tracker rule, so the bundles that attach document listeners without
// being media trackers were left behind (#71). Litmus: every js/frontend/*.js file
// with a module-scope addEventListener() needs one, whatever it tracks.
if ( ! window.gtm4wp_form_move_inited ) {
	window.gtm4wp_form_move_inited = true;

	document.addEventListener(
		'focusin',
		function ( event ) {
			const elem =
				event &&
				event.target &&
				event.target.closest &&
				event.target.closest(
					'input,select,textarea,button,meter,progress'
				);
			if ( elem ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.formElementEnter',

					inputID: elem.getAttribute( 'id' ) || '(no input ID)',
					inputName: elem.getAttribute( 'name' ) || '(no input name)',
					inputClass:
						elem.getAttribute( 'class' ) || '(no input class)',

					formID:
						( elem.form && elem.form.getAttribute( 'id' ) ) ||
						'(no form ID)',
					formName:
						( elem.form && elem.form.getAttribute( 'name' ) ) ||
						'(no form name)',
					formClass:
						( elem.form && elem.form.getAttribute( 'class' ) ) ||
						'(no form class)',
				} );
			}
		},
		false
	);

	document.addEventListener(
		'focusout',
		function ( event ) {
			const elem =
				event &&
				event.target &&
				event.target.closest &&
				event.target.closest(
					'input,select,textarea,button,meter,progress'
				);
			if ( elem ) {
				window[ gtm4wp_datalayer_name ].push( {
					event: 'gtm4wp.formElementLeave',

					inputID: elem.getAttribute( 'id' ) || '(no input ID)',
					inputName: elem.getAttribute( 'name' ) || '(no input name)',
					inputClass:
						elem.getAttribute( 'class' ) || '(no input class)',

					formID:
						( elem.form && elem.form.getAttribute( 'id' ) ) ||
						'(no form ID)',
					formName:
						( elem.form && elem.form.getAttribute( 'name' ) ) ||
						'(no form name)',
					formClass:
						( elem.form && elem.form.getAttribute( 'class' ) ) ||
						'(no form class)',
				} );
			}
		},
		false
	);
}
