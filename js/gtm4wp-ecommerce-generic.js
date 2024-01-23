function gtm4wp_make_sure_is_float( probably_float ) {
	let will_be_float = probably_float;

	if ( typeof will_be_float == "string" ) {
		will_be_float = parseFloat( will_be_float );
		if ( isNaN( will_be_float ) ) {
			will_be_float = 0;
		}
	} else if ( typeof will_be_float != "number" ) {
		will_be_float = 0;
	}
	will_be_float = will_be_float.toFixed(2)

	return will_be_float;
}

function gtm4wp_push_ecommerce( event_name, items, extra_params, event_callback=false, event_timeout=2000 ) {
	const ecom_obj = extra_params || {};
	ecom_obj.items = items;
	
	if ( gtm4wp_clear_ecommerce ) {
		window[ gtm4wp_datalayer_name ].push({
			ecommerce: null
		});
	}

	const dl_obj = {
		'event': event_name,
		'ecommerce': ecom_obj
	};

	if (event_callback) {
		dl_obj.eventCallback = event_callback;
		dl_obj.eventTimeout  = event_timeout;
	}

	window[ gtm4wp_datalayer_name ].push(dl_obj);
}

function gtm4wp_read_from_json( json_data, exclude_keys=['productlink', 'internal_id'] ) {
	try {
		const parsed_json = JSON.parse( json_data );
		if ( parsed_json ) {
			if ( parsed_json.price ) {
				parsed_json.price = gtm4wp_make_sure_is_float( parsed_json.price );
			}

			if ( exclude_keys && exclude_keys.length > 0 ) {
				for ( let i = 0; i < exclude_keys.length; i++ ) {
					delete parsed_json[ exclude_keys[i] ];
				}
			}

			return parsed_json;
		}
	} catch(e) {
		console && console.error && console.error( e.message );
	}

	return false;
}

function gtm4wp_read_json_from_node( el, dataset_item_id, exclude_keys=['productlink', 'internal_id'] ) {
	if ( el && el.dataset && el.dataset[ dataset_item_id ] ) {
		return gtm4wp_read_from_json( el.dataset[ dataset_item_id ], exclude_keys );
	}

	return false;
}

function gtm4wp_update_json_in_node( el, dataset_item_id, new_key, new_value ) {
	if ( el && el.dataset && el.dataset[ dataset_item_id ] ) {
		try {
			const parsed_json = JSON.parse( el.dataset[ dataset_item_id ] );
			if ( parsed_json ) {
				if ( parsed_json.price ) {
					parsed_json.price = gtm4wp_make_sure_is_float( parsed_json.price );
				}

                parsed_json[ new_key ] = new_value;

				el.dataset[ dataset_item_id ] = JSON.stringify( parsed_json );

				return true;
			}
		} catch(e) {
			console && console.error && console.error( e.message );
		}
	}

	return false;
}