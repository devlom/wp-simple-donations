<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WDF_Gateway_PayU')) {
	class WDF_Gateway_PayU extends WDF_Gateway {

		public $plugin_name = 'payu';
		public $admin_name = '';
		public $public_name = '';
		public $force_ssl = false;
		public $payment_types = 'simple';
		public $skip_form = true;
		public $allow_reccuring = false;

		private $api_url = '';
		private $pos_id = '';
		private $client_secret = '';
		private $md5_key = '';

		public function on_creation() {
			$this->public_name = $this->admin_name = __('PayU', 'wdf');
			$settings = get_option('wdf_settings');

			$this->pos_id        = isset($settings['payu_pos_id']) ? $settings['payu_pos_id'] : '';
			$this->client_secret = isset($settings['payu_client_secret']) ? $settings['payu_client_secret'] : '';
			$this->md5_key       = isset($settings['payu_md5_key']) ? $settings['payu_md5_key'] : '';

			if (isset($settings['payu_sandbox']) && $settings['payu_sandbox'] === 'yes') {
				$this->api_url = 'https://secure.snd.payu.com';
			} else {
				$this->api_url = 'https://secure.payu.com';
			}
		}

		// ── OAuth2 Access Token ───────────────────────────────────

		private function get_access_token() {
			$transient_key = 'wdf_payu_token_' . substr(md5($this->pos_id), 0, 8);
			$cached = get_transient($transient_key);
			if ($cached) return $cached;

			$response = wp_remote_post($this->api_url . '/pl/standard/user/oauth/authorize', array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => http_build_query(array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->pos_id,
					'client_secret' => $this->client_secret,
				)),
				'timeout'   => 30,
				'sslverify' => true,
			));

			if (is_wp_error($response)) {
				error_log('WDF PayU OAuth error: ' . $response->get_error_message());
				return false;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = json_decode(wp_remote_retrieve_body($response), true);

			if ($code !== 200 || empty($body['access_token'])) {
				error_log('WDF PayU OAuth failed: HTTP ' . $code);
				return false;
			}

			$expires = isset($body['expires_in']) ? (int) $body['expires_in'] - 120 : 3600;
			set_transient($transient_key, $body['access_token'], max($expires, 60));

			return $body['access_token'];
		}

		// ── API call helper ───────────────────────────────────────

		private function api_call($endpoint, $body = null, $method = 'POST') {
			$token = $this->get_access_token();
			if (!$token) {
				return new WP_Error('payu_auth', __('Could not authenticate with PayU.', 'wdf'));
			}

			$args = array(
				'method'    => $method,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'timeout'   => 30,
				'sslverify' => true,
				// IMPORTANT: PayU returns 302 on order create — don't follow redirects
				'redirection' => 0,
			);

			if ($body !== null) {
				$args['body'] = wp_json_encode($body);
			}

			$response = wp_remote_request($this->api_url . $endpoint, $args);

			if (is_wp_error($response)) {
				error_log('WDF PayU API error: ' . $response->get_error_message());
				return $response;
			}

			$code     = wp_remote_retrieve_response_code($response);
			$body_raw = wp_remote_retrieve_body($response);
			$data     = json_decode($body_raw, true);

			// PayU returns 302 for order creation with JSON body — that's success
			if ($code === 302 || $code === 301) {
				return $data;
			}

			if ($code >= 400) {
				$msg = isset($data['status']['statusDesc']) ? $data['status']['statusDesc'] : 'HTTP ' . $code;
				error_log('WDF PayU API error: ' . $msg . ' (endpoint: ' . $endpoint . ')');
				return new WP_Error('payu_api', $msg);
			}

			return $data;
		}

		// ── Notification signature verification ───────────────────

		private function verify_notification_signature($raw_body) {
			$sig_header = isset($_SERVER['HTTP_OPENPAYU_SIGNATURE']) ? $_SERVER['HTTP_OPENPAYU_SIGNATURE'] : '';
			if (empty($sig_header)) return false;

			// Parse "sender=checkout;signature=abc123;algorithm=MD5;content=DOCUMENT"
			$parts = array();
			foreach (explode(';', $sig_header) as $part) {
				$kv = explode('=', $part, 2);
				if (count($kv) === 2) {
					$parts[trim($kv[0])] = trim($kv[1]);
				}
			}

			if (empty($parts['signature'])) return false;

			$expected = md5($raw_body . $this->md5_key);
			return hash_equals($expected, $parts['signature']);
		}

		// ── Payment Form ──────────────────────────────────────────

		public function payment_form() {
			// skip_form = true — donor data collected on panel, no separate gateway form needed
			return '';
		}

		// ── Process Payment ───────────────────────────────────────

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
			$settings  = get_option('wdf_settings');
			$funder_id = $_SESSION['funder_id'];
			$funder    = get_post($funder_id);

			if (!$funder) {
				$this->create_gateway_error(__('Could not determine campaign.', 'wdf'));
				return;
			}

			$pledge_id    = $wdf->generate_pledge_id();
			$_SESSION['wdf_pledge_id'] = $pledge_id;

			$currency     = isset($settings['currency']) ? $settings['currency'] : 'PLN';
			$amount_float = floatval($_SESSION['wdf_pledge']);
			$amount_cents = (int) round($amount_float * 100);

			$this->return_url = add_query_arg(
				array('pledge_id' => $pledge_id, 'status' => 'OK'),
				wdf_get_funder_page('confirmation', $funder->ID)
			);

			$description = sprintf(__('Donation: %s', 'wdf'), $funder->post_title);

			$order_body = array(
				'notifyUrl'     => $this->ipn_url,
				'continueUrl'   => $this->return_url,
				'customerIp'    => $this->get_customer_ip(),
				'merchantPosId' => $this->pos_id,
				'description'   => mb_substr(wp_strip_all_tags($description), 0, 255),
				'currencyCode'  => $currency,
				'totalAmount'   => (string) $amount_cents,
				'extOrderId'    => $pledge_id,
				'buyer'         => array(
					'email'     => $email,
					'firstName' => $first_name,
					'lastName'  => $last_name,
					'language'  => substr(get_locale(), 0, 2),
				),
				'products'      => array(
					array(
						'name'      => mb_substr(wp_strip_all_tags($funder->post_title), 0, 255),
						'unitPrice' => (string) $amount_cents,
						'quantity'  => '1',
					),
				),
			);

			$response = $this->api_call('/api/v2_1/orders', $order_body);

			if (is_wp_error($response)) {
				$this->create_gateway_error(
					__('Error connecting to PayU: ', 'wdf') . $response->get_error_message()
				);
				return;
			}

			if (!isset($response['status']['statusCode']) || $response['status']['statusCode'] !== 'SUCCESS') {
				$desc = isset($response['status']['statusDesc']) ? $response['status']['statusDesc'] : __('Unknown error', 'wdf');
				$this->create_gateway_error(__('PayU error: ', 'wdf') . $desc);
				return;
			}

			if (empty($response['redirectUri']) || empty($response['orderId'])) {
				$this->create_gateway_error(__('PayU did not return a payment URL.', 'wdf'));
				return;
			}

			$payu_order_id = sanitize_text_field($response['orderId']);

			// Store transaction data in transient for notification processing
			set_transient('wdf_payu_' . $payu_order_id, array(
				'pledge_id'  => $pledge_id,
				'funder_id'  => $funder_id,
				'amount'     => $amount_cents,
				'currency'   => $currency,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $email,
				'reward'     => isset($_SESSION['wdf_reward']) ? $_SESSION['wdf_reward'] : '0',
				'created_at' => time(),
			), DAY_IN_SECONDS);

			// Also store mapping from extOrderId (pledge_id) to payu_order_id
			set_transient('wdf_payu_ext_' . $pledge_id, $payu_order_id, DAY_IN_SECONDS);

			// Redirect to PayU payment page
			if (!headers_sent()) {
				wp_redirect($response['redirectUri']);
				exit;
			}
		}

		public function process_advanced() {
			$this->process_simple();
		}

		// ── Handle Notification (Webhook) ─────────────────────────

		public function handle_ipn() {
			$raw_body = file_get_contents('php://input');
			$data = json_decode($raw_body, true);

			if (empty($data) || !isset($data['order']['orderId'])) {
				status_header(400);
				exit;
			}

			// Verify signature
			if (!$this->verify_notification_signature($raw_body)) {
				error_log('WDF PayU: Invalid notification signature for order ' . $data['order']['orderId']);
				status_header(401);
				exit;
			}

			$order         = $data['order'];
			$payu_order_id = sanitize_text_field($order['orderId']);
			$status        = sanitize_text_field($order['status']);

			// Only process COMPLETED notifications
			if ($status !== 'COMPLETED') {
				// Acknowledge receipt but don't create pledge yet
				status_header(200);
				exit;
			}

			// Retrieve stored data
			$stored = get_transient('wdf_payu_' . $payu_order_id);
			if (!$stored) {
				error_log('WDF PayU: Transient not found for order ' . $payu_order_id);
				status_header(200); // Still acknowledge — may be duplicate
				exit;
			}

			// Verify amount matches
			$notified_amount = isset($order['totalAmount']) ? absint($order['totalAmount']) : 0;
			if ($notified_amount !== (int) $stored['amount']) {
				error_log('WDF PayU: Amount mismatch for order ' . $payu_order_id . ' (expected: ' . $stored['amount'] . ', got: ' . $notified_amount . ')');
				status_header(400);
				exit;
			}

			// Build transaction record
			global $wdf;
			$transaction = array(
				'gross'          => $stored['amount'] / 100,
				'type'           => 'simple',
				'currency_code'  => $stored['currency'],
				'first_name'     => $stored['first_name'],
				'last_name'      => $stored['last_name'],
				'payer_email'    => $stored['email'],
				'gateway_public' => $this->public_name,
				'gateway'        => $this->plugin_name,
				'ipn_id'         => $payu_order_id,
				'status'         => __('Payment Completed', 'wdf'),
				'gateway_msg'    => __('PayU payment completed.', 'wdf'),
			);

			if (isset($stored['reward']) && $stored['reward'] !== '0') {
				$transaction['reward'] = $stored['reward'];
			}

			$wdf->update_pledge($stored['pledge_id'], $stored['funder_id'], 'wdf_complete', $transaction);

			// Clean up
			delete_transient('wdf_payu_' . $payu_order_id);
			delete_transient('wdf_payu_ext_' . $stored['pledge_id']);

			status_header(200);
			exit;
		}

		// ── Confirmation / Payment Info ───────────────────────────

		public function confirm() {
		}

		public function payment_info($content, $transaction) {
			$content = '<div class="payu_transaction_info">';
			if (isset($transaction['ipn_id'])) {
				$content .= '<p>' . sprintf(__('PayU Order ID: %s', 'wdf'), '<code>' . esc_html($transaction['ipn_id']) . '</code>') . '</p>';
			}
			$content .= '</div>';
			return $content;
		}

		public function execute_payment($type, $pledge, $transaction) {
			// PayU processes payments immediately via redirect, no delayed execution needed.
		}

		// ── Admin Settings ────────────────────────────────────────

		public function admin_settings() {
			if (!class_exists('WpmuDev_HelpTooltips'))
				require_once WDF_PLUGIN_BASE_DIR . '/lib/external/class.wd_help_tooltips.php';

			$tips = new WpmuDev_HelpTooltips();
			$tips->set_icon_url(WDF_PLUGIN_URL . '/img/information.png');
			$settings = get_option('wdf_settings');
			?>
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="wdf_settings_payu_sandbox"><?php _e('PayU Mode', 'wdf'); ?></label>
						</th>
						<td>
							<select name="wdf_settings[payu_sandbox]" id="wdf_settings_payu_sandbox">
								<option value="no" <?php selected(isset($settings['payu_sandbox']) ? $settings['payu_sandbox'] : 'no', 'no'); ?>><?php _e('Live', 'wdf'); ?></option>
								<option value="yes" <?php selected(isset($settings['payu_sandbox']) ? $settings['payu_sandbox'] : 'no', 'yes'); ?>><?php _e('Sandbox', 'wdf'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wdf_settings_payu_pos_id"><?php _e('POS ID (client_id)', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="text" id="wdf_settings_payu_pos_id"
								name="wdf_settings[payu_pos_id]"
								value="<?php echo esc_attr(isset($settings['payu_pos_id']) ? $settings['payu_pos_id'] : ''); ?>" />
							<?php echo $tips->add_tip(__('POS ID from PayU panel. Also used as OAuth client_id.', 'wdf')); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wdf_settings_payu_client_secret"><?php _e('Client Secret', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="password" id="wdf_settings_payu_client_secret"
								name="wdf_settings[payu_client_secret]"
								value="<?php echo esc_attr(isset($settings['payu_client_secret']) ? $settings['payu_client_secret'] : ''); ?>" />
							<?php echo $tips->add_tip(__('OAuth client_secret from PayU panel (different from MD5 key).', 'wdf')); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wdf_settings_payu_md5_key"><?php _e('MD5 Key (Second Key)', 'wdf'); ?></label>
						</th>
						<td>
							<input class="regular-text" type="password" id="wdf_settings_payu_md5_key"
								name="wdf_settings[payu_md5_key]"
								value="<?php echo esc_attr(isset($settings['payu_md5_key']) ? $settings['payu_md5_key'] : ''); ?>" />
							<?php echo $tips->add_tip(__('Used for webhook notification signature verification.', 'wdf')); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Notification URL', 'wdf'); ?></th>
						<td>
							<code><?php echo esc_html($this->ipn_url); ?></code>
							<p class="description"><?php _e('Enter this URL in the PayU panel under POS Configuration > Notification URL.', 'wdf'); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php
		}

		public function save_gateway_settings() {
			$payu_fields = array('payu_pos_id', 'payu_client_secret', 'payu_md5_key', 'payu_sandbox');
			$has_payu = false;

			foreach ($payu_fields as $field) {
				if (isset($_POST['wdf_settings'][$field])) {
					$has_payu = true;
					break;
				}
			}

			if ($has_payu) {
				$settings = get_option('wdf_settings');
				$old_pos = isset($settings['payu_pos_id']) ? $settings['payu_pos_id'] : '';

				foreach ($payu_fields as $field) {
					if (isset($_POST['wdf_settings'][$field])) {
						$settings[$field] = sanitize_text_field($_POST['wdf_settings'][$field]);
					}
				}

				// Clear cached token if POS ID changed
				if ($old_pos !== $settings['payu_pos_id']) {
					delete_transient('wdf_payu_token_' . substr(md5($old_pos), 0, 8));
				}

				update_option('wdf_settings', $settings);
			}
		}

		// ── Helpers ───────────────────────────────────────────────

		private function get_customer_ip() {
			$ip = '';
			if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
				$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$ip = trim($ips[0]);
			} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
				$ip = $_SERVER['REMOTE_ADDR'];
			}
			return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
		}
	}

	wdf_register_gateway_plugin('WDF_Gateway_PayU', 'payu', __('PayU', 'wdf'), array('simple'));
}
?>
