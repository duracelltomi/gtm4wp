;jQuery(function() {
	jQuery( document ).on( 'click', '.single_add_to_cart_button', function() {
	  var _product_form     = jQuery( this ).closest( 'form.cart' );
	  var _product_id       = jQuery( '[name=gtm4wp_id]', _product_form ).val();
	  var _product_name     = jQuery( '[name=gtm4wp_name]', _product_form ).val();
	  var _product_sku      = jQuery( '[name=gtm4wp_sku]', _product_form ).val();

		window[ gtm4wp_datalayer_name ].push({
			'event': 'gtm4wp.addProductToCart',
			'productName': _product_name,
			'productSKU':  _product_sku,
			'productID':   _product_id
		});
	});
});
