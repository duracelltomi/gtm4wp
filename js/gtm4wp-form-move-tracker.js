document.addEventListener("focusin", function(event) {
	const elem = event && event.target && event.target.closest("input,select,textarea,button,meter,progress");
	if ( elem ) {
		window[ gtm4wp_datalayer_name ].push({
			'event'    : 'gtm4wp.formElementEnter',

			'inputID'   : elem.getAttribute("id") || "(no input ID)",
			'inputName' : elem.getAttribute("name") || "(no input name)",
			'inputClass': elem.getAttribute("class") || "(no input class)",

			'formID'   : (elem.form && elem.form.getAttribute("id")) || "(no form ID)",
			'formName' : (elem.form && elem.form.getAttribute("name")) || "(no form name)",
			'formClass': (elem.form && elem.form.getAttribute("class")) || "(no form class)"
		});
	}
}, false);

document.addEventListener("focusout", function(event) {
	const elem = event && event.target && event.target.closest("input,select,textarea,button,meter,progress");
	if ( elem ) {
		window[ gtm4wp_datalayer_name ].push({
			'event'    : 'gtm4wp.formElementLeave',

			'inputID'   : elem.getAttribute("id") || "(no input ID)",
			'inputName' : elem.getAttribute("name") || "(no input name)",
			'inputClass': elem.getAttribute("class") || "(no input class)",

			'formID'   : (elem.form && elem.form.getAttribute("id")) || "(no form ID)",
			'formName' : (elem.form && elem.form.getAttribute("name")) || "(no form name)",
			'formClass': (elem.form && elem.form.getAttribute("class")) || "(no form class)"
		});
	};
}, false);
