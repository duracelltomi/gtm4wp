;jQuery(function() {
	jQuery( document ).on( 'click', '.single_add_to_cart_button', function() {
	  var _product_form     = jQuery( this ).closest( 'form.cart' );
	  var _product_var_id   = jQuery( '[name=variation_id]', _product_form );
	  var _product_id       = jQuery( '[name=gtm4wp_id]', _product_form ).val();
	  var _product_name     = jQuery( '[name=gtm4wp_name]', _product_form ).val();
	  var _product_sku      = jQuery( '[name=gtm4wp_sku]', _product_form ).val();
	  var _product_category = jQuery( '[name=gtm4wp_category]', _product_form ).val();
	  var _product_price    = jQuery( '[name=gtm4wp_price]', _product_form ).val();
	  var _product_currency = jQuery( '[name=gtm4wp_currency]', _product_form ).val();

	  if ( _product_var_id.length > 0 ) {
			_product_var_id_val = _product_var_id.val();
			_product_form_variations = _product_form.data( 'product_variations' );

			_product_form_variations.forEach( function( product_var ) {
				if ( product_var.variation_id == _product_var_id_val ) {
					_product_var_sku = product_var.sku;
					if ( ! _product_var_sku ) {
						_product_var_sku = _product_var_id_val;
					}

					var _tmp = [];
					for( var attrib_key in product_var.attributes ) {
						_tmp.push( product_var.attributes[ attrib_key ] );
					}

					window[ gtm4wp_datalayer_name ].push({
						'event': 'gtm4wp.addProductToCartEEC',
						'ecommerce': {
							'currencyCode': _product_currency,
							'add': {
								'products': [{
									'id': gtm4wp_use_sku_instead ? _product_var_sku : _product_var_id_val,
									'name': _product_name,
									'price': product_var.display_price,
									'category': _product_category,
									'variant': _tmp.join(','),
									'quantity': jQuery( 'form.cart:first input[name=quantity]' ).val()
								}]
							}
						}
					});

				}
			});
	  } else {
			window[ gtm4wp_datalayer_name ].push({
				'event': 'gtm4wp.addProductToCartEEC',
				'ecommerce': {
					'currencyCode': _product_currency,
					'add': {
						'products': [{
							'id': gtm4wp_use_sku_instead ? _product_sku : _product_id,
							'name': _product_name,
							'price': _product_price,
							'category': _product_category,
							'quantity': jQuery( 'form.cart:first input[name=quantity]' ).val()
						}]
					}
				}
			});
		}
	});

	jQuery(document).on( 'found_variation', function( event, product_variation ) {
		if ( "undefined" == typeof product_variation ) {
			// some ither plugins trigger this event without variation data
			return;
		}
	
	  var _product_form     = event.target;
	  var _product_var_id   = jQuery( '[name=variation_id]', _product_form );
	  var _product_id       = jQuery( '[name=gtm4wp_id]', _product_form ).val();
	  var _product_name     = jQuery( '[name=gtm4wp_name]', _product_form ).val();
	  var _product_sku      = jQuery( '[name=gtm4wp_sku]', _product_form ).val();
	  var _product_category = jQuery( '[name=gtm4wp_category]', _product_form ).val();
	  var _product_price    = jQuery( '[name=gtm4wp_price]', _product_form ).val();
	  var _product_currency = jQuery( '[name=gtm4wp_currency]', _product_form ).val();

		var current_product_detail_data   = {
			name: _product_name,
			id: 0,
			price: 0,
			category: _product_category,
			variant: ''
		};

		current_product_detail_data.id = product_variation.variation_id;
		if ( gtm4wp_use_sku_instead && product_variation.sku && ('' != product_variation.sku) ) {
			current_product_detail_data.id = product_variation.sku;
		}
		current_product_detail_data.price = product_variation.display_price;

		var _tmp = [];
		for( var attrib_key in product_variation.attributes ) {
			_tmp.push( product_variation.attributes[ attrib_key ] );
		}
		current_product_detail_data.variant = _tmp.join(',');

		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.changeDetailViewEEC',
			'ecommerce': {
				'currencyCode': _product_currency,
				'detail': {
					'products': [current_product_detail_data]
				},
			},
			'ecomm_prodid': gtm4wp_id_prefix + current_product_detail_data.id,
			'ecomm_pagetype': 'product',
			'ecomm_totalvalue': current_product_detail_data.price
		});
	});

	jQuery( '.variations select' ).trigger( 'change' );

	jQuery( document ).ajaxSuccess( function( event, xhr, settings ) {
		if ( settings.url.indexOf( 'wc-api=WC_Quick_View' ) > -1 ) {
		  setTimeout( function() {
				jQuery( ".woocommerce.quick-view" ).parent().find( "script" ).each( function(i) {
					eval( jQuery( this ).text() );
				});
			}, 500);
		}
	});
});
