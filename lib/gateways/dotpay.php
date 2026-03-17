<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('WDF_Gateway_Dotpay')) {
    class WDF_Gateway_Dotpay extends WDF_Gateway
    {

        // Private gateway slug. Lowercase alpha (a-z) and dashes (-) only please!
        public $plugin_name = 'dotpay';

        // Name of your gateway, for the admin side.
        public $admin_name = '';

        // Public name of your gateway, for lists and such.
        public $public_name = '';

        // Whether or not ssl is needed for checkout page
        public $force_ssl = false;

        // An array of allowed payment types (simple, advanced)
        public $payment_types = 'advanced';

        // If you are redirecting to a 3rd party make sure this is set to true
        public $skip_form = true;

        // Allow recurring payments with your gateway
        public $allow_reccuring = true;

        public function skip_form()
        {
            if (isset($_SESSION['funder_id'])) {
                $collect_address = get_post_meta($_SESSION['funder_id'], 'wdf_collect_address', true);
                if ($collect_address) {
                    return false;
                }

            }

            return $this->skip_form;
        }

        public function on_creation()
        {
            $this->public_name = $this->admin_name = __('Dotpay', 'wdf');

            if (isset($_SESSION['funder_id'])) {
                $collect_address = get_post_meta($_SESSION['funder_id'], 'wdf_collect_address', true);
                if ($collect_address) {
                    $this->skip_form = false;
                }

            }

            $settings = get_option('wdf_settings');

            $this->query = array();

            $this->API_Username = (isset($settings['dotpay']['advanced']['api_user']) ? $settings['dotpay']['advanced']['api_user'] : '');
            $this->API_Password = (isset($settings['dotpay']['advanced']['api_pass']) ? $settings['dotpay']['advanced']['api_pass'] : '');
            $this->API_Signature = (isset($settings['dotpay']['advanced']['api_sig']) ? $settings['dotpay']['advanced']['api_sig'] : '');
            if (isset($settings['dotpay_sb']) && $settings['dotpay_sb'] == 'yes') {
                $this->Standard_Endpoint = "https://ssl.dotpay.pl";
                $this->Adaptive_Endpoint = "https://svcs.sandbox.dotpay.com/AdaptivePayments/";
                $this->dotpayURL = "https://www.sandbox.dotpay.com/webscr?cmd=_ap-preapproval&preapprovalkey=";
                // Generic Dotpay AppID for Sandbox Testing
                $this->appId = 'APP-80W284485P519543T';
            } else {
                $this->dotpayURL = "https://ssl.dotpay.pl";
                $this->appId = (isset($settings['dotpay']['advanced']['app_id']) ? $settings['dotpay']['advanced']['app_id'] : '');
            }

        }

        public function payment_form()
        {
            $funder_id = get_the_ID();
            $settings = get_option('wdf_settings');

            global $wdf;
            $pledge_id = $wdf->generate_pledge_id();

            $dotpay_id = (isset($settings['dotpay']['advanced']['app_id']) ? $settings['dotpay']['advanced']['app_id'] : '');

            if (isset($settings['dotpay_sb']) && $settings['dotpay_sb'] == 'yes') {
                $dotpay_id = (isset($settings['dotpay']['advanced']['app_id_test']) ? $settings['dotpay']['advanced']['app_id_test'] : '');
            } else if (isset($settings['dotpay_sb']) && $settings['dotpay_sb'] == 'no') {
                $dotpay_id = (isset($settings['dotpay']['advanced']['app_id']) ? $settings['dotpay']['advanced']['app_id'] : '');
            }


            $content = '<div class="wdf_dotpay_payment_form wdf_payment_form">';
            $content .= '<p class="wdf_dotpay_payment_form_address_info wdf_payment_form_address_info">';
       
            $content .= '<input type="hidden" name="id" value="' . $dotpay_id . '" />';
            $content .= '<input type="hidden" name="kwota" value="' . $_SESSION['wdf_pledge'] . '" />';
            $content .= '<input type="hidden" name="channel" value="' . (isset($_POST['channel']) ? esc_attr($_POST['channel']) : '') . '" />';

            $name = (isset($_POST['firstname']) ? esc_attr($_POST['firstname']) : '') . '||' . (isset($_POST['lastname']) ? esc_attr($_POST['lastname']) : '');

            $content .= '<input type="hidden" name="URL" value="' . wdf_get_funder_page('confirmation', $funder_id) .'?pledge_id='.$pledge_id. '" />';

            $content .= '<input type="hidden" name="URLC" value="' . $this->ipn_url . '" />';            $content .= '<input type="hidden" name="control" value="' . $pledge_id . '||' . $funder_id . '||' . $name . '" />';
            $content .= '<input type="hidden" name="firstname" value="' . (isset($_POST['firstname']) ? esc_attr($_POST['firstname']) : '') . '" />';
            $content .= '<input type="hidden" name="lastname" value="' . (isset($_POST['lastname']) ? esc_attr($_POST['lastname']) : '') . '" />';
            $content .= '<input type="hidden" name="email" value="' . (isset($_POST['e-mail']) ? esc_attr($_POST['e-mail']) : '') . '" />';
            $content .= '<input type="hidden" name="opis" value="' . get_the_title() . '" />';
            $content .= '<input type="hidden" name="type" value="4" />';
            $content .= '<input type="hidden" name="ignore_last_payment_channel" value="1" />';
            $content .= '</p>';
            $content .= '</div>';
            $content .= '<script type="text/javascript">
            document.getElementById("dotpay").submit();
            document.body.innerHTML = "<div class=\"wdf_redirect\">Przekierowywanie do systemu płatności.</div>";
        </script>';
        
            return $content;
        }

        function payment_info( $content, $transaction ) {
			$settings = get_option('wdf_settings');
			return $content;
		}

        public function handle_ipn()
        {
            echo "OK";

            if (isset($_POST['operation_type']) && $_POST['operation_type'] == 'payment') {
                //Handle IPN for advanced payments
                if ($this->verify_dotpay()) {

                    global $wdf;
                    $transaction = array();
                    $control = explode('||', urldecode($_REQUEST['control']));

                    $post_title = $control[0];
                    $funder_id = $control[1];
                    $transaction['currency_code'] = (isset($_POST['operation_currency']) ? $_POST['operation_currency'] : $settings['currency']);
                    $transaction['payer_email'] = $_POST['email'];
                    $transaction['gateway_public'] = $this->public_name;
                    $transaction['gateway'] = $this->plugin_name;
                    $transaction['first_name'] = $control[2];
                    $transaction['post_id'] = $control[1];
                    $transaction['last_name'] = $control[3];
                    $transaction['gross'] = (isset($_POST['operation_amount']) ? $_POST['operation_amount'] : '');
                    $transaction['ipn_id'] = (isset($_POST['signature']) ? $_POST['signature'] : '');
                    //Make sure you pass the correct type back into the transaction
                    $transaction['type'] = 'advanced';

                    switch ($_POST['operation_status']) {
                        case 'completed':
                            $status = 'wdf_complete';
                            $transaction['status'] = __('Completed', 'wdf');
                            $transaction['gateway_msg'] = __('Transaction Completed', 'wdf');
                            break;
                        case 'processing':
                            $status = 'wdf_approved';
                            $transaction['status'] = __('Pre-Approved', 'wdf');
                            $transaction['gateway_msg'] = __('Transaction Pre-Approved', 'wdf');
                            break;
                        case 'rejected':
                            $status = 'wdf_canceled';
                            $transaction['status'] = __('Canceled', 'wdf');
                            $transaction['gateway_msg'] = __('Transaction Rejected', 'wdf');
                            break;
                        default:
                            $status = 'wdf_canceled';
                            $transaction['status'] = __('Unknown', 'wdf');
                            $transaction['gateway_msg'] = __('Unknown PayPal status.', 'wdf');
                            break;
                    }

                    $wdf->update_pledge($post_title, $funder_id, $status, $transaction);

                } else {
                    header("HTTP/1.1 503 Service Unavailable");
                    _e('There was a problem verifying the IPN string with PayPal. Please try again.', 'wdf');
                    exit;
                }
            }
        }

        public function verify_dotpay()
        {
            global $wdf;

            return true;

        }

        public function admin_settings()
        {
            if (!class_exists('WpmuDev_HelpTooltips')) {
                require_once WDF_PLUGIN_BASE_DIR . '/lib/external/class.wd_help_tooltips.php';
            }

            $tips = new WpmuDev_HelpTooltips();
            $tips->set_icon_url(WDF_PLUGIN_URL . '/img/information.png');
            $settings = get_option('wdf_settings');?>
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row"> <label for="wdf_settings[dotpay_sb]"><?php echo __('Dotpay Mode', 'wdf'); ?></label>
					</th>
					<td><select name="wdf_settings[dotpay_sb]" id="wdf_settings_dotpay_sb">
							<option value="no" <?php (isset($settings['dotpay_sb']) ? selected($settings['dotpay_sb'], 'no') : '');?>><?php _e('Live', 'wdf');?></option>
							<option value="yes" <?php (isset($settings['dotpay_sb']) ? selected($settings['dotpay_sb'], 'yes') : '');?>><?php _e('Sandbox', 'wdf');?></option>
						</select></td>
				</tr>
			<?php if (in_array('simple', $settings['payment_types'])): ?>
				<tr>
					<td colspan="2">
					<h4><?php _e('Advanced Payment Options (Advanced Crowdfunding)', 'wdf');?></h4>
					</td>
				</tr>
				<?php /*?><tr>
            <th scope="row"><?php _e('Fees To Collect', 'wdf'); ?></th>
            <td><span class="description">
            <?php _e('Enter a percentage of all store sales to collect as a fee. Decimals allowed.', 'wdf') ?>
            </span><br />
            <input value="<?php echo esc_attr( (isset($settings['dotpay']['advanced']['percentage']) ? $settings['dotpay']['advanced']['percentage'] : '') ); ?>" size="3" name="wdf_settings[dotpay][advanced][percentage]" type="text" />%
            </td>
            </tr><?php */?>
				<tr>
				<tr valign="top">
					<th scope="row"><?php _e('Dotpay Currency', 'wdf')?></th>
					<td>
						<select name="wdf_settings[dotpay][advanced][currency]">
						<?php
$sel_currency = isset($settings['dotpay']['advanced']['currency']) ? $settings['dotpay']['advanced']['currency'] : $settings['currency'];
            $currencies = array(
                'AUD' => 'AUD - Australian Dollar',
                'BRL' => 'BRL - Brazilian Real',
                'CAD' => 'CAD - Canadian Dollar',
                'CHF' => 'CHF - Swiss Franc',
                'CZK' => 'CZK - Czech Koruna',
                'DKK' => 'DKK - Danish Krone',
                'EUR' => 'EUR - Euro',
                'GBP' => 'GBP - Pound Sterling',
                'ILS' => 'ILS - Israeli Shekel',
                'HKD' => 'HKD - Hong Kong Dollar',
                'HUF' => 'HUF - Hungarian Forint',
                'JPY' => 'JPY - Japanese Yen',
                'MYR' => 'MYR - Malaysian Ringgits',
                'MXN' => 'MXN - Mexican Peso',
                'NOK' => 'NOK - Norwegian Krone',
                'NZD' => 'NZD - New Zealand Dollar',
                'PHP' => 'PHP - Philippine Pesos',
                'PLN' => 'PLN - Polish Zloty',
                'SEK' => 'SEK - Swedish Krona',
                'SGD' => 'SGD - Singapore Dollar',
                'TWD' => 'TWD - Taiwan New Dollars',
                'THB' => 'THB - Thai Baht',
                'TRY' => 'TRY - Turkish lira',
                'USD' => 'USD - U.S. Dollar',
            );

            foreach ($currencies as $k => $v) {
                echo '		<option value="' . $k . '"' . ($k == $sel_currency ? ' selected' : '') . '>' . esc_html($v, true) . '</option>' . "\n";
            }
            ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php _e('Dotpay API Credentials', 'wdf')?></th>
					<td>
						<p>
							<label>
								<?php _e('Application ID', 'wdf')?>
								<br />
								<input value="<?php echo esc_attr((isset($settings['dotpay']['advanced']['app_id']) ? $settings['dotpay']['advanced']['app_id'] : '')); ?>" size="50" name="wdf_settings[dotpay][advanced][app_id]" type="text" />
							</label><?php echo $tips->add_tip(__('No application ID is required when using Dotpay in sandbox mode.', 'wdf')); ?>
						</p>
					</td>
                </tr>
                <tr>
					<th scope="row"><?php _e('Dotpay URL', 'wdf')?></th>
					<td>
						<p>
							<label>
								<input value="<?php echo esc_attr((isset($settings['dotpay']['advanced']['app_url']) ? $settings['dotpay']['advanced']['app_url'] : '')); ?>" size="50" name="wdf_settings[dotpay][advanced][app_url]" type="text" />
							</label>
						</p>
					</td>
                </tr>
                <tr>
					<th scope="row"><?php _e('Dotpay API Test Credentials', 'wdf')?></th>
					<td>
						<p>
							<label>
								<input value="<?php echo esc_attr((isset($settings['dotpay']['advanced']['app_id_test']) ? $settings['dotpay']['advanced']['app_id_test'] : '')); ?>" size="50" name="wdf_settings[dotpay][advanced][app_id_test]" type="text" />
							</label><?php echo $tips->add_tip(__('No application ID is required when using Dotpay in sandbox mode.', 'wdf')); ?>
						</p>
					</td>
                </tr>
                <tr>
					<th scope="row"><?php _e('Dotpay Test URL', 'wdf')?></th>
					<td>
						<p>
							<label>
								<input value="<?php echo esc_attr((isset($settings['dotpay']['advanced']['app_url_test']) ? $settings['dotpay']['advanced']['app_url_test'] : '')); ?>" size="50" name="wdf_settings[dotpay][advanced][app_url_test]" type="text" />
							</label>
						</p>
					</td>
                </tr>
				<?php /*?><tr>
            <th scope="row"><?php _e('Gateway Settings Page Message', 'mp'); ?></th>
            <td><span class="description">
            <?php _e('This message is displayed at the top of the gateway settings page to store admins. It\'s a good place to inform them of your fees or put any sales messages. Optional, HTML allowed.', 'mp') ?>
            </span><br />
            <textarea class="mp_msgs_txt" name="mp[gateways][dotpay-chained][msg]"><?php echo esc_html($settings['gateways']['dotpay-chained']['msg']); ?></textarea></td>
            </tr><?php */?>

			<?php endif;?>
				</tbody>
			</table>
			<?php
}
        public function save_gateway_settings()
        {

            if (isset($_POST['wdf_settings']['dotpay'])) {
                // Init array for new settings
                $new = array();

                // Advanced Settings
                if (isset($_POST['wdf_settings']['dotpay']['advanced']) && is_array($_POST['wdf_settings']['dotpay']['advanced'])) {
                    $new['dotpay']['advanced'] = $_POST['wdf_settings']['dotpay']['advanced'];
                    $new['dotpay']['advanced'] = array_map('esc_attr', $new['dotpay']['advanced']);

                    $settings = get_option('wdf_settings');
                    $settings = array_merge($settings, $new);
                    update_option('wdf_settings', $settings);
                }

            }
        }

    }
    wdf_register_gateway_plugin('WDF_Gateway_Dotpay', 'dotpay', __('Dotpay', 'wdf'), array('simple', 'standard', 'advanced'));
}
?>