<?php
if (!defined('ABSPATH')) exit;

if(!class_exists('WDF_Gateway_PayPal')) {
	class WDF_Gateway_PayPal extends WDF_Gateway {

		public $plugin_name = 'paypal';
		public $admin_name = '';
		public $public_name = '';
		public $force_ssl = false;
		public $payment_types = 'simple';
		public $skip_form = true;
		public $allow_reccuring = false;

		private $api_url = '';
		private $client_id = '';
		private $client_secret = '';

		function on_creation() {
			$this->public_name = $this->admin_name = __('PayPal', 'wdf');

			$settings = get_option('wdf_settings');

			$this->client_id     = isset($settings['paypal_client_id']) ? $settings['paypal_client_id'] : '';
			$this->client_secret = isset($settings['paypal_client_secret']) ? $settings['paypal_client_secret'] : '';

			if (isset($settings['paypal_sb']) && $settings['paypal_sb'] === 'yes') {
				$this->api_url = 'https://api-m.sandbox.paypal.com';
			} else {
				$this->api_url = 'https://api-m.paypal.com';
			}

			// AJAX endpoints for JS SDK
			add_action('wp_ajax_wdf_paypal_create_order',        array($this, 'ajax_create_order'));
			add_action('wp_ajax_nopriv_wdf_paypal_create_order', array($this, 'ajax_create_order'));
			add_action('wp_ajax_wdf_paypal_capture_order',        array($this, 'ajax_capture_order'));
			add_action('wp_ajax_nopriv_wdf_paypal_capture_order', array($this, 'ajax_capture_order'));

			// Enqueue PayPal JS SDK on frontend
			add_action('wp_enqueue_scripts', array($this, 'enqueue_paypal_sdk'));
		}

		// ── Frontend Scripts ──────────────────────────────────────

		function enqueue_paypal_sdk() {
			if (empty($this->client_id)) return;

			global $wp_query;
			if (!isset($wp_query->query_vars['post_type']) || $wp_query->query_vars['post_type'] !== 'funder') return;

			$settings = get_option('wdf_settings');
			$currency = isset($settings['currency']) ? $settings['currency'] : 'USD';

			wp_enqueue_script(
				'paypal-sdk',
				'https://www.paypal.com/sdk/js?client-id=' . urlencode($this->client_id) . '&currency=' . urlencode($currency) . '&intent=capture&components=buttons&disable-funding=card,blik,p24,bancontact,sofort,mybank,eps,giropay,ideal,sepa',
				array(),
				null,
				true
			);

			wp_enqueue_script(
				'wdf-paypal',
				WDF_PLUGIN_URL . '/js/wdf-paypal.js',
				array('jquery', 'paypal-sdk'),
				false,
				true
			);

			wp_localize_script('wdf-paypal', 'wdf_paypal', array(
				'ajax_url'             => admin_url('admin-ajax.php'),
				'create_order_nonce'   => wp_create_nonce('wdf_paypal_create_order'),
				'capture_order_nonce'  => wp_create_nonce('wdf_paypal_capture_order'),
				'currency'             => $currency,
				'error_message'        => __('Payment could not be processed. Please try again.', 'wdf'),
			));
		}

		// ── PayPal API helpers ────────────────────────────────────

		private function get_access_token() {
			$cached = get_transient('wdf_paypal_access_token');
			if ($cached) return $cached;

			$response = wp_remote_post($this->api_url . '/v1/oauth2/token', array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'       => 'grant_type=client_credentials',
				'timeout'    => 30,
				'sslverify'  => true,
			));

			if (is_wp_error($response)) {
				error_log('WDF PayPal OAuth error: ' . $response->get_error_message());
				return false;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($code !== 200 || empty($body['access_token'])) {
				error_log('WDF PayPal OAuth failed: HTTP ' . $code);
				return false;
			}

			$expires = isset($body['expires_in']) ? (int)$body['expires_in'] - 60 : 3600;
			set_transient('wdf_paypal_access_token', $body['access_token'], max($expires, 60));

			return $body['access_token'];
		}

		private function api_call($endpoint, $body = null, $method = 'POST') {
			$token = $this->get_access_token();
			if (!$token) return new WP_Error('paypal_auth', 'Could not authenticate with PayPal');

			$args = array(
				'method'    => $method,
				'headers'   => array(
					'Authorization'      => 'Bearer ' . $token,
					'Content-Type'       => 'application/json',
					'PayPal-Request-Id'  => 'wdf_' . wp_generate_uuid4(),
				),
				'timeout'   => 30,
				'sslverify' => true,
			);

			if ($body !== null) {
				$args['body'] = wp_json_encode($body);
			}

			$response = wp_remote_request($this->api_url . $endpoint, $args);

			if (is_wp_error($response)) {
				error_log('WDF PayPal API error: ' . $response->get_error_message());
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$data = json_decode(wp_remote_retrieve_body($response), true);

			if ($code < 200 || $code >= 300) {
				$msg = isset($data['message']) ? $data['message'] : 'HTTP ' . $code;
				error_log('WDF PayPal API error: ' . $msg . ' (endpoint: ' . $endpoint . ')');
				return new WP_Error('paypal_api', $msg);
			}

			return $data;
		}

		// ── AJAX: Create Order ────────────────────────────────────

		function ajax_create_order() {
			check_ajax_referer('wdf_paypal_create_order', 'nonce');

			global $wdf;
			$settings = get_option('wdf_settings');

			// Validate required fields
			$funder_id = isset($_POST['funder_id']) ? absint($_POST['funder_id']) : 0;
			$amount    = isset($_POST['pledge_amount']) ? $wdf->filter_price($_POST['pledge_amount']) : 0;
			$first     = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
			$last      = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
			$email     = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
			$privacy   = isset($_POST['privacy']) ? $_POST['privacy'] : '';

			if (!$funder_id || !get_post($funder_id)) {
				wp_send_json_error(array('message' => __('Invalid campaign.', 'wdf')));
			}
			if (empty($first) || empty($last)) {
				wp_send_json_error(array('message' => __('First name and last name are required.', 'wdf')));
			}
			if (!is_email($email)) {
				wp_send_json_error(array('message' => __('Please enter a valid email address.', 'wdf')));
			}
			if ($amount < 1) {
				wp_send_json_error(array('message' => sprintf(__('You must pledge at least %s', 'wdf'), $wdf->format_currency('', 1))));
			}
			if ($privacy !== 'accepted') {
				wp_send_json_error(array('message' => __('You must accept the privacy policy.', 'wdf')));
			}

			// Verify form nonce
			$send_nonce = isset($_POST['send_nonce']) ? $_POST['send_nonce'] : '';
			if (!wp_verify_nonce($send_nonce, 'send_nonce_' . $funder_id)) {
				wp_send_json_error(array('message' => __('Security check failed. Please refresh and try again.', 'wdf')));
			}

			$funder   = get_post($funder_id);
			$currency = isset($settings['currency']) ? $settings['currency'] : 'USD';
			$pledge_id = $wdf->generate_pledge_id();

			// Create PayPal order
			$order_data = $this->api_call('/v2/checkout/orders', array(
				'intent' => 'CAPTURE',
				'purchase_units' => array(array(
					'reference_id' => $pledge_id,
					'description'  => mb_substr(wp_strip_all_tags($funder->post_title), 0, 127),
					'amount'       => array(
						'currency_code' => $currency,
						'value'         => number_format($amount, 2, '.', ''),
					),
				)),
			));

			if (is_wp_error($order_data) || empty($order_data['id'])) {
				$msg = is_wp_error($order_data) ? $order_data->get_error_message() : 'Unknown error';
				wp_send_json_error(array('message' => __('Could not create PayPal order. Please try again.', 'wdf')));
			}

			// Store in transient for capture step
			set_transient('wdf_paypal_' . $order_data['id'], array(
				'pledge_id'  => $pledge_id,
				'funder_id'  => $funder_id,
				'amount'     => $amount,
				'currency'   => $currency,
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'reward'     => isset($_POST['reward']) && is_numeric($_POST['reward']) ? absint($_POST['reward']) + 1 : 0,
				'created_at' => time(),
			), DAY_IN_SECONDS);

			wp_send_json_success(array('order_id' => $order_data['id']));
		}

		// ── AJAX: Capture Order ───────────────────────────────────

		function ajax_capture_order() {
			check_ajax_referer('wdf_paypal_capture_order', 'nonce');

			$order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';
			if (empty($order_id)) {
				wp_send_json_error(array('message' => __('Missing order ID.', 'wdf')));
			}

			// Retrieve stored data
			$stored = get_transient('wdf_paypal_' . $order_id);
			if (!$stored) {
				wp_send_json_error(array('message' => __('Order expired or not found. Please try again.', 'wdf')));
			}

			// Capture payment
			$capture = $this->api_call('/v2/checkout/orders/' . $order_id . '/capture', new stdClass());

			if (is_wp_error($capture)) {
				wp_send_json_error(array('message' => __('Payment capture failed. Please try again.', 'wdf')));
			}

			$status = isset($capture['status']) ? $capture['status'] : '';
			if ($status !== 'COMPLETED') {
				error_log('WDF PayPal capture status: ' . $status . ' for order ' . $order_id);
				wp_send_json_error(array('message' => __('Payment was not completed. Please try again.', 'wdf')));
			}

			// Extract payer info from PayPal response (fallback to stored data)
			$payer_email = $stored['email'];
			if (isset($capture['payer']['email_address'])) {
				$payer_email = sanitize_email($capture['payer']['email_address']);
			}

			$capture_id = '';
			if (isset($capture['purchase_units'][0]['payments']['captures'][0]['id'])) {
				$capture_id = $capture['purchase_units'][0]['payments']['captures'][0]['id'];
			}

			// Build transaction
			$transaction = array(
				'gross'          => $stored['amount'],
				'type'           => 'simple',
				'currency_code'  => $stored['currency'],
				'first_name'     => $stored['first_name'],
				'last_name'      => $stored['last_name'],
				'payer_email'    => $payer_email,
				'gateway_public' => $this->public_name,
				'gateway'        => $this->plugin_name,
				'status'         => __('Payment Completed', 'wdf'),
				'gateway_msg'    => __('PayPal payment captured successfully.', 'wdf'),
				'ipn_id'         => $capture_id ? $capture_id : $order_id,
			);

			if (!empty($stored['reward'])) {
				$transaction['reward'] = $stored['reward'];
			}

			// Create pledge
			global $wdf;
			$wdf->update_pledge($stored['pledge_id'], $stored['funder_id'], 'wdf_complete', $transaction);

			// Clean up
			delete_transient('wdf_paypal_' . $order_id);

			// Build confirmation URL
			$confirm_url = add_query_arg(
				array('pledge_id' => $stored['pledge_id'], 'status' => 'OK'),
				wdf_get_funder_page('confirmation', $stored['funder_id'])
			);

			wp_send_json_success(array('redirect_url' => $confirm_url));
		}

		// ── Gateway interface (base class compatibility) ──────────

		function skip_form() {
			return true;
		}

		function payment_form() {
			return '';
		}

		function process_simple() {
			// Payment handled via AJAX (ajax_create_order + ajax_capture_order).
			// This method is called by the traditional form POST flow but PayPal
			// now uses the JS SDK popup. If somehow reached, redirect back.
			if (isset($_SESSION['funder_id'])) {
				wp_safe_redirect(get_post_permalink($_SESSION['funder_id']));
				exit;
			}
		}

		function process_advanced() {
			$this->process_simple();
		}

		function confirm() {
			// Confirmation is handled by the template system.
		}

		function handle_ipn() {
			// No IPN needed — payment is captured synchronously via AJAX.
			status_header(200);
			exit;
		}

		function execute_payment($type, $pledge, $transaction) {
			// Payment is captured immediately during checkout. No delayed execution.
		}

		function payment_info($content, $transaction) {
			$content = '<div class="paypal_transaction_info">';
			if (isset($transaction['ipn_id'])) {
				$content .= '<p>' . sprintf(__('PayPal Transaction ID: %s', 'wdf'), '<code>' . esc_html($transaction['ipn_id']) . '</code>') . '</p>';
			}
			$content .= '</div>';
			return $content;
		}

		// ── Admin Settings ────────────────────────────────────────

		function admin_settings() {
			$settings = get_option('wdf_settings');
			?>
			<table class="form-table">
				<tbody>
				<tr>
					<th scope="row">
						<label for="wdf_settings_paypal_sb"><?php _e('PayPal Mode', 'wdf'); ?></label>
					</th>
					<td>
						<select name="wdf_settings[paypal_sb]" id="wdf_settings_paypal_sb">
							<option value="no" <?php selected(isset($settings['paypal_sb']) ? $settings['paypal_sb'] : 'no', 'no'); ?>><?php _e('Live', 'wdf'); ?></option>
							<option value="yes" <?php selected(isset($settings['paypal_sb']) ? $settings['paypal_sb'] : 'no', 'yes'); ?>><?php _e('Sandbox', 'wdf'); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wdf_settings_paypal_client_id"><?php _e('Client ID', 'wdf'); ?></label>
					</th>
					<td>
						<input class="regular-text" type="text" id="wdf_settings_paypal_client_id"
							name="wdf_settings[paypal_client_id]"
							value="<?php echo esc_attr(isset($settings['paypal_client_id']) ? $settings['paypal_client_id'] : ''); ?>" />
						<p class="description"><?php _e('From PayPal Developer Dashboard > Apps & Credentials.', 'wdf'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wdf_settings_paypal_client_secret"><?php _e('Client Secret', 'wdf'); ?></label>
					</th>
					<td>
						<input class="regular-text" type="password" id="wdf_settings_paypal_client_secret"
							name="wdf_settings[paypal_client_secret]"
							value="<?php echo esc_attr(isset($settings['paypal_client_secret']) ? $settings['paypal_client_secret'] : ''); ?>" />
					</td>
				</tr>
				</tbody>
			</table>
			<?php
		}

		function save_gateway_settings() {
			if (!isset($_POST['wdf_settings'])) return;

			$settings = get_option('wdf_settings');
			$changed = false;

			if (isset($_POST['wdf_settings']['paypal_client_id'])) {
				$settings['paypal_client_id'] = sanitize_text_field($_POST['wdf_settings']['paypal_client_id']);
				$changed = true;
			}
			if (isset($_POST['wdf_settings']['paypal_client_secret'])) {
				$settings['paypal_client_secret'] = sanitize_text_field($_POST['wdf_settings']['paypal_client_secret']);
				$changed = true;
			}

			// Clear cached access token when credentials change
			if ($changed) {
				delete_transient('wdf_paypal_access_token');
				update_option('wdf_settings', $settings);
			}
		}
	}

	wdf_register_gateway_plugin('WDF_Gateway_PayPal', 'paypal', __('PayPal', 'wdf'), array('simple'));
}
?>
