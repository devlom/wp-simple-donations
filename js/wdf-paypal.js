/**
 * WP Simple Donations — PayPal JS SDK integration
 *
 * Renders PayPal Buttons via the Orders v2 API.
 * Requires wdf_paypal localized object (ajax_url, nonces, currency, error_message).
 */
jQuery(document).ready(function($) {
	if (typeof wdf_paypal === 'undefined' || typeof paypal === 'undefined') return;

	var $container = $('#wdf-paypal-button-container');
	if (!$container.length) return;

	function getForm() {
		var $form = $container.closest('form.wdf_checkout_form');
		if (!$form.length) {
			$form = $container.closest('.wdf_fundraiser_panel').find('form.wdf_checkout_form');
		}
		return $form;
	}

	function getFormData($form) {
		return {
			action: 'wdf_paypal_create_order',
			nonce: wdf_paypal.create_order_nonce,
			funder_id: $form.find('input[name="funder_id"]').val(),
			pledge_amount: $form.find('input[name="wdf_pledge"]').val(),
			first_name: $form.find('input[name="firstname"]').val(),
			last_name: $form.find('input[name="lastname"]').val(),
			email: $form.find('input[name="e-mail"]').val(),
			privacy: $form.find('input[name="wdf_privacy"]').is(':checked') ? 'accepted' : '',
			send_nonce: $form.find('input[name="send_nonce"]').val(),
			reward: $form.find('input[name="wdf_reward"]:checked').val() || ''
		};
	}

	paypal.Buttons({
		style: {
			layout: 'vertical',
			color:  'gold',
			shape:  'rect',
			label:  'donate',
			height: 45
		},

		createOrder: function() {
			var $form = getForm();
			var data = getFormData($form);

			return $.ajax({
				url: wdf_paypal.ajax_url,
				method: 'POST',
				data: data
			}).then(function(response) {
				if (response.success) {
					return response.data.order_id;
				}
				var msg = response.data && response.data.message ? response.data.message : wdf_paypal.error_message;
				throw new Error(msg);
			});
		},

		onApprove: function(data) {
			return $.ajax({
				url: wdf_paypal.ajax_url,
				method: 'POST',
				data: {
					action: 'wdf_paypal_capture_order',
					nonce: wdf_paypal.capture_order_nonce,
					order_id: data.orderID
				}
			}).then(function(response) {
				if (response.success && response.data.redirect_url) {
					window.location.href = response.data.redirect_url;
				} else {
					var msg = response.data && response.data.message ? response.data.message : wdf_paypal.error_message;
					alert(msg);
				}
			});
		},

		onError: function(err) {
			console.error('PayPal error:', err);
			alert(wdf_paypal.error_message);
		}
	}).render('#wdf-paypal-button-container');
});
