<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WDF_Gateway_Przelewy24')) {
	class WDF_Gateway_Przelewy24 extends WDF_Gateway {

		// Private gateway slug
		public $plugin_name = 'przelewy24';

		// Name of your gateway, for the admin side
		public $admin_name = '';

		// Public name of your gateway
		public $public_name = '';

		// Whether or not ssl is needed for checkout page
		public $force_ssl = false;

		// An array of allowed payment types (simple, advanced)
		public $payment_types = 'simple';

		// If you are redirecting to a 3rd party make sure this is set to true
		public $skip_form = true;

		// Allow recurring payments with your gateway
		public $allow_reccuring = false;

		private $api_url = '';
		private $merchant_id = '';
		private $pos_id = '';
		private $crc_key = '';
		private $api_key = '';

		public function on_creation() {
			$this->public_name = $this->admin_name = __('Przelewy24', 'wdf');
			$settings = get_option('wdf_settings');

			$this->merchant_id = isset($settings['p24_merchant_id']) ? $settings['p24_merchant_id'] : '';
			$this->pos_id = isset($settings['p24_pos_id']) ? $settings['p24_pos_id'] : '';
			$this->crc_key = isset($settings['p24_crc_key']) ? $settings['p24_crc_key'] : '';
			$this->api_key = isset($settings['p24_api_key']) ? $settings['p24_api_key'] : '';

			if (isset($settings['p24_sandbox']) && $settings['p24_sandbox'] === 'yes') {
				$this->api_url = 'https://sandbox.przelewy24.pl';
			} else {
				$this->api_url = 'https://secure.przelewy24.pl';
			}
		}

		public function payment_form() {
			// skip_form = true — donor data collected on panel, no separate gateway form needed
			return '';
		}

		/**
		 * Calculate SHA-384 signature for P24 transaction registration.
		 */
		private function calculate_register_sign($session_id, $merchant_id, $amount, $currency) {
			$data = json_encode(
				array(
					'sessionId' => $session_id,
					'merchantId' => (int) $merchant_id,
					'amount' => (int) $amount,
					'currency' => $currency,
					'crc' => $this->crc_key,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			return hash('sha384', $data);
		}

		/**
		 * Calculate SHA-384 signature for P24 transaction verification.
		 */
		private function calculate_verify_sign($session_id, $order_id, $amount, $currency) {
			$data = json_encode(
				array(
					'sessionId' => $session_id,
					'orderId' => (int) $order_id,
					'amount' => (int) $amount,
					'currency' => $currency,
					'crc' => $this->crc_key,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			return hash('sha384', $data);
		}

		/**
		 * Calculate SHA-384 signature for P24 notification verification.
		 */
		private function calculate_notification_sign($session_id, $order_id, $amount, $currency) {
			$data = json_encode(
				array(
					'merchantId' => (int) $this->merchant_id,
					'posId' => (int) $this->pos_id,
					'sessionId' => $session_id,
					'amount' => (int) $amount,
					'originAmount' => (int) $amount,
					'currency' => $currency,
					'orderId' => (int) $order_id,
					'methodId' => 0,
					'statement' => '',
					'crc' => $this->crc_key,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			return hash('sha384', $data);
		}

		/**
		 * Make an API call to Przelewy24 REST API.
		 */
		private function api_call($endpoint, $body = array()) {
			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Authorization' => 'Basic ' . base64_encode($this->pos_id . ':' . $this->api_key),
				),
				'body' => wp_json_encode($body),
				'sslverify' => true,
				'timeout' => 60,
			);

			$response = wp_remote_post($this->api_url . $endpoint, $args);

			if (is_wp_error($response)) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body_raw = wp_remote_retrieve_body($response);
			$data = json_decode($body_raw, true);

			if ($code >= 400) {
				$error_msg = isset($data['error']) ? $data['error'] : __('Unknown P24 API error', 'wdf');
				return new WP_Error('p24_api_error', $error_msg);
			}

			return $data;
		}

		public function process_simple() {
			// Read donor data from session (collected on the panel form)
			$first_name = isset($_SESSION['wdf_first_name']) ? $_SESSION['wdf_first_name'] : '';
			$last_name  = isset($_SESSION['wdf_last_name']) ? $_SESSION['wdf_last_name'] : '';
			$email      = isset($_SESSION['wdf_sender_email']) ? $_SESSION['wdf_sender_email'] : '';

			if (empty($first_name) || empty($last_name) || !is_email($email)) {
				$this->create_gateway_error(__('Missing donor data. Please go back and fill in the form.', 'wdf'));
				return;
			}

			global $wdf;
			$settings = get_option('wdf_settings');
			$funder_id = $_SESSION['funder_id'];

			if (!$funder = get_post($funder_id)) {
				$this->create_gateway_error(__('Could not determine campaign.', 'wdf'));
				return;
			}

			$pledge_id = $wdf->generate_pledge_id();
			$_SESSION['wdf_pledge_id'] = $pledge_id;

			$currency = isset($settings['p24_currency']) ? $settings['p24_currency'] : 'PLN';
			$amount_float = floatval($_SESSION['wdf_pledge']);
			$amount_gr = (int) round($amount_float * 100);

			$session_id = 'wdf_' . $pledge_id . '_' . time();
			$this->return_url = add_query_arg(
				array('pledge_id' => $pledge_id, 'status' => 'OK'),
				wdf_get_funder_page('confirmation', $funder->ID)
			);

			$sign = $this->calculate_register_sign($session_id, $this->merchant_id, $amount_gr, $currency);

			$description = sprintf(__('Donation: %s', 'wdf'), $funder->post_title);

			$register_body = array(
				'merchantId' => (int) $this->merchant_id,
				'posId'      => (int) $this->pos_id,
				'sessionId'  => $session_id,
				'amount'     => $amount_gr,
				'currency'   => $currency,
				'description' => mb_substr($description, 0, 1024),
				'email'      => $email,
				'country'    => 'PL',
				'language'   => 'pl',
				'urlReturn'  => $this->return_url,
				'urlStatus'  => $this->ipn_url,
				'sign'       => $sign,
			);

			$response = $this->api_call('/api/v1/transaction/register', $register_body);

			if (is_wp_error($response)) {
				error_log('WDF P24 register error: ' . $response->get_error_message());
				$this->create_gateway_error(
					__('Error connecting to Przelewy24. Please try again.', 'wdf')
				);
				return;
			}

			if (!isset($response['data']['token'])) {
				error_log('WDF P24 register: no token returned. Response: ' . wp_json_encode($response));
				$this->create_gateway_error(__('Przelewy24 did not return a transaction token.', 'wdf'));
				return;
			}

			$token = $response['data']['token'];

			// Store transaction data in transient for IPN processing
			set_transient('wdf_p24_' . $session_id, array(
				'pledge_id'   => $pledge_id,
				'funder_id'   => $funder_id,
				'session_id'  => $session_id,
				'amount'      => $amount_gr,
				'currency'    => $currency,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'payer_email' => $email,
				'reward'      => isset($_SESSION['wdf_reward']) ? $_SESSION['wdf_reward'] : '0',
			), DAY_IN_SECONDS);

			// Redirect to P24 payment page
			$redirect_url = $this->api_url . '/trnRequest/' . $token;
			if (!headers_sent()) {
				wp_redirect($redirect_url);
				exit;
			}
		}

		public function process_advanced() {
			$this->process_simple();
		}

		public function confirm() {
		}

		public function payment_info($content, $transaction) {
			$content = '<div class="p24_transaction_info">';
			$content .= '<p>' . __('Payment processed via Przelewy24.', 'wdf') . '</p>';
			$content .= '</div>';
			return $content;
		}

		public function handle_ipn() {
			// Read raw input
			$raw_body = file_get_contents('php://input');
			$data = json_decode($raw_body, true);

			if (empty($data) || !isset($data['sessionId']) || !isset($data['orderId'])) {
				status_header(400);
				exit;
			}

			$session_id = sanitize_text_field($data['sessionId']);
			$order_id = absint($data['orderId']);
			$amount = absint($data['amount']);

			// Idempotency: prevent duplicate processing via atomic lock
			$lock_key = 'wdf_p24_lock_' . $order_id;
			if (get_transient($lock_key)) {
				error_log('WDF P24: Duplicate notification ignored for order ' . $order_id);
				status_header(200);
				exit;
			}
			set_transient($lock_key, 1, 300); // 5 min lock
			$currency = sanitize_text_field($data['currency']);
			$p24_sign = isset($data['sign']) ? sanitize_text_field($data['sign']) : '';

			// Retrieve stored transient
			$stored = get_transient('wdf_p24_' . $session_id);
			if (!$stored) {
				status_header(400);
				echo 'Transaction not found';
				exit;
			}

			// Verify signature
			$expected_sign = $this->calculate_notification_sign($session_id, $order_id, $amount, $currency);
			if (!hash_equals($expected_sign, $p24_sign)) {
				status_header(400);
				echo 'Invalid signature';
				exit;
			}

			// Verify amount matches
			if ($amount !== (int) $stored['amount'] || $currency !== $stored['currency']) {
				status_header(400);
				echo 'Amount mismatch';
				exit;
			}

			// Verify the transaction with P24
			$verify_sign = $this->calculate_verify_sign($session_id, $order_id, $amount, $currency);

			$verify_body = array(
				'merchantId' => (int) $this->merchant_id,
				'posId' => (int) $this->pos_id,
				'sessionId' => $session_id,
				'amount' => $amount,
				'currency' => $currency,
				'orderId' => $order_id,
				'sign' => $verify_sign,
			);

			$verify_response = $this->api_call('/api/v1/transaction/verify', $verify_body);

			if (is_wp_error($verify_response)) {
				status_header(500);
				echo 'Verification failed';
				exit;
			}

			// Build transaction record
			global $wdf;
			$transaction = array();
			$transaction['gross'] = $amount / 100; // Convert grosz back to PLN
			$transaction['type'] = 'simple';
			$transaction['currency_code'] = $currency;
			$transaction['first_name'] = $stored['first_name'];
			$transaction['last_name'] = $stored['last_name'];
			$transaction['payer_email'] = $stored['payer_email'];
			$transaction['gateway_public'] = $this->public_name;
			$transaction['gateway'] = $this->plugin_name;
			$transaction['ipn_id'] = $order_id;
			$transaction['status'] = __('Payment Completed', 'wdf');
			$transaction['gateway_msg'] = __('Przelewy24 payment verified.', 'wdf');

			if (isset($stored['reward']) && $stored['reward'] !== '0') {
				$transaction['reward'] = $stored['reward'];
			}

			// Address fields
			$address_fields = array('country', 'address1', 'city', 'zip');
			foreach ($address_fields as $field) {
				if (isset($stored[$field])) {
					$transaction[$field] = $stored[$field];
				}
			}

			$status = 'wdf_complete';
			$wdf->update_pledge($stored['pledge_id'], $stored['funder_id'], $status, $transaction);

			// Clean up
			delete_transient('wdf_p24_' . $session_id);
			// Keep lock active to reject further duplicates

			status_header(200);
			exit;
		}

		public function execute_payment($type, $pledge, $transaction) {
			// P24 processes payments immediately via redirect, no delayed execution needed
		}

		public function admin_settings() {
			if (!class_exists('WpmuDev_HelpTooltips'))
				require_once WDF_PLUGIN_BASE_DIR . '/lib/external/class.wd_help_tooltips.php';

			$tips = new WpmuDev_HelpTooltips();
			$tips->set_icon_url(WDF_PLUGIN_URL . '/img/information.png');
			$settings = get_option('wdf_settings');
			?>
			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_sandbox]"><?php _e('Przelewy24 Mode', 'wdf'); ?></label>
						</th>
						<td>
							<select name="wdf_settings[p24_sandbox]" id="wdf_settings_p24_sandbox">
								<option value="no" <?php (isset($settings['p24_sandbox']) ? selected($settings['p24_sandbox'], 'no') : ''); ?>><?php _e('Live', 'wdf'); ?></option>
								<option value="yes" <?php (isset($settings['p24_sandbox']) ? selected($settings['p24_sandbox'], 'yes') : ''); ?>><?php _e('Sandbox', 'wdf'); ?></option>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_merchant_id]"><?php _e('Merchant ID', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="text" name="wdf_settings[p24_merchant_id]" value="<?php echo esc_attr(isset($settings['p24_merchant_id']) ? $settings['p24_merchant_id'] : ''); ?>" />
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_pos_id]"><?php _e('POS ID', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="text" name="wdf_settings[p24_pos_id]" value="<?php echo esc_attr(isset($settings['p24_pos_id']) ? $settings['p24_pos_id'] : ''); ?>" />
							<?php echo $tips->add_tip(__('POS ID is usually the same as Merchant ID.', 'wdf')); ?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_crc_key]"><?php _e('CRC Key', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="password" name="wdf_settings[p24_crc_key]" value="<?php echo esc_attr(isset($settings['p24_crc_key']) ? $settings['p24_crc_key'] : ''); ?>" />
							<?php echo $tips->add_tip(__('CRC key is available in the Przelewy24 admin panel under My Data > API Settings.', 'wdf')); ?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_api_key]"><?php _e('API Key', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="password" name="wdf_settings[p24_api_key]" value="<?php echo esc_attr(isset($settings['p24_api_key']) ? $settings['p24_api_key'] : ''); ?>" />
							<?php echo $tips->add_tip(__('API key is available in the Przelewy24 admin panel under My Data > API Settings.', 'wdf')); ?>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="wdf_settings[p24_currency]"><?php _e('Currency', 'wdf'); ?></label>
						</th>
						<td>
							<?php
							$sel_currency = isset($settings['p24_currency']) ? $settings['p24_currency'] : 'PLN';
							$currencies = array(
								'PLN' => 'PLN - Polish Zloty',
								'EUR' => 'EUR - Euro',
								'GBP' => 'GBP - Pound Sterling',
								'CZK' => 'CZK - Czech Koruna',
							);
							?>
							<select name="wdf_settings[p24_currency]">
								<?php foreach ($currencies as $k => $v) : ?>
									<option value="<?php echo esc_attr($k); ?>" <?php selected($sel_currency, $k); ?>><?php echo esc_html($v); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php _e('Notification URL', 'wdf'); ?></th>
						<td>
							<code><?php echo esc_html($this->ipn_url); ?></code>
							<p class="description"><?php _e('Enter this URL in the Przelewy24 admin panel under My Data > API Settings > Notification URL.', 'wdf'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php
		}

		public function save_gateway_settings() {
			$p24_fields = array('p24_merchant_id', 'p24_pos_id', 'p24_crc_key', 'p24_api_key', 'p24_sandbox', 'p24_currency');
			$has_p24_settings = false;

			foreach ($p24_fields as $field) {
				if (isset($_POST['wdf_settings'][$field])) {
					$has_p24_settings = true;
					break;
				}
			}

			if ($has_p24_settings) {
				$settings = get_option('wdf_settings');
				foreach ($p24_fields as $field) {
					if (isset($_POST['wdf_settings'][$field])) {
						$settings[$field] = sanitize_text_field($_POST['wdf_settings'][$field]);
					}
				}
				update_option('wdf_settings', $settings);
			}
		}
	}

	wdf_register_gateway_plugin('WDF_Gateway_Przelewy24', 'przelewy24', __('Przelewy24', 'wdf'), array('simple', 'standard', 'advanced'));
}
