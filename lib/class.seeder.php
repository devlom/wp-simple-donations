<?php
if (!defined('ABSPATH')) exit;

/**
 * WDF_Seeder — creates sample donation campaign with example pledges on first activation.
 *
 * Runs once (controlled by wdf_seeded option). Can also be triggered manually
 * via Tools > WP Simple Donations Seeder or by calling WDF_Seeder::seed().
 */
class WDF_Seeder {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		// Run on first activation (after post types are registered)
		add_action('init', array(__CLASS__, 'maybe_seed'), 20);

		// Admin page for manual re-seed
		add_action('admin_menu', array(__CLASS__, 'admin_menu'));
		add_action('admin_init', array(__CLASS__, 'handle_reseed'));
	}

	/**
	 * Seed once on first plugin activation.
	 */
	public static function maybe_seed() {
		if (get_option('wdf_seeded')) {
			return;
		}

		// Only seed if no funders exist yet
		$existing = get_posts(array(
			'post_type'   => 'funder',
			'numberposts' => 1,
			'post_status' => 'any',
		));

		if (!empty($existing)) {
			update_option('wdf_seeded', 1);
			return;
		}

		self::seed();
		update_option('wdf_seeded', 1);
	}

	/**
	 * Create sample campaign + pledges.
	 *
	 * @return int|false Funder post ID or false on failure.
	 */
	public static function seed() {
		$settings = get_option('wdf_settings');

		// Set Polish locale defaults if locale is pl_PL
		$locale = get_locale();
		if (strpos($locale, 'pl') === 0) {
			$settings['currency'] = 'PLN';
			$settings['curr_symbol_position'] = 4; // 100 zł
			$settings['donation_labels'] = array(
				'backer_single' => 'Wspierający',
				'backer_plural' => 'Wspierających',
				'singular_name' => 'Wpłata',
				'plural_name'   => 'Wpłaty',
				'action_name'   => 'Wesprzyj projekt',
			);
			update_option('wdf_settings', $settings);
		}

		$currency = isset($settings['currency']) ? $settings['currency'] : 'PLN';

		// --- 1. Create sample funder (simple donation) ---
		$funder_id = wp_insert_post(array(
			'post_type'    => 'funder',
			'post_status'  => 'publish',
			'post_title'   => __('Przykładowa zbiórka', 'wdf'),
			'post_content' => self::get_sample_content(),
			'post_author'  => self::get_admin_id(),
		));

		if (is_wp_error($funder_id) || !$funder_id) {
			return false;
		}

		// Funder meta fields
		$meta = array(
			'wdf_type'           => 'simple',
			'wdf_style'          => isset($settings['default_style']) ? $settings['default_style'] : 'wdf-default',
			'wdf_checkout_type'  => isset($settings['checkout_type']) ? $settings['checkout_type'] : '1',
			'wdf_recurring'      => 'no',
			'wdf_panel_pos'      => 'top',
			'wdf_has_goal'       => '1',
			'wdf_goal_amount'    => '5000',
			'wdf_goal_start'     => date('Y-m-d'),
			'wdf_goal_end'       => date('Y-m-d', strtotime('+90 days')),
			'wdf_has_reward'     => '0',
			'wdf_collect_address' => '0',
			'wdf_thanks_type'    => 'default',
			'wdf_send_email'     => '0',
		);

		foreach ($meta as $key => $value) {
			update_post_meta($funder_id, $key, $value);
		}

		// --- 2. Create sample pledges ---
		$donors = array(
			array(
				'first_name' => 'Jan',
				'last_name'  => 'Kowalski',
				'email'      => 'jan.kowalski@example.com',
				'amount'     => '150.00',
				'days_ago'   => 5,
			),
			array(
				'first_name' => 'Anna',
				'last_name'  => 'Nowak',
				'email'      => 'anna.nowak@example.com',
				'amount'     => '75.00',
				'days_ago'   => 3,
			),
			array(
				'first_name' => 'Piotr',
				'last_name'  => 'Wiśniewski',
				'email'      => 'piotr.w@example.com',
				'amount'     => '200.00',
				'days_ago'   => 1,
			),
		);

		foreach ($donors as $donor) {
			self::create_pledge($funder_id, $donor, $currency);
		}

		// Flush rewrite rules so the new post is accessible
		flush_rewrite_rules();

		return $funder_id;
	}

	/**
	 * Create a single pledge/donation record.
	 */
	private static function create_pledge($funder_id, $donor, $currency) {
		$pledge_id = wp_insert_post(array(
			'post_type'   => 'donation',
			'post_status' => 'draft',
			'post_title'  => substr(md5($donor['email'] . $donor['amount'] . wp_rand()), 0, 12),
			'post_parent' => $funder_id,
			'post_date'   => date('Y-m-d H:i:s', strtotime("-{$donor['days_ago']} days")),
			'post_author' => self::get_admin_id(),
		));

		if (is_wp_error($pledge_id) || !$pledge_id) {
			return false;
		}

		// Set custom status directly — wp_insert_post may not accept custom statuses
		global $wpdb;
		$wpdb->update($wpdb->posts, array('post_status' => 'wdf_complete'), array('ID' => $pledge_id));
		clean_post_cache($pledge_id);

		$transaction = array(
			'first_name'     => $donor['first_name'],
			'last_name'      => $donor['last_name'],
			'payer_email'    => $donor['email'],
			'gross'          => $donor['amount'],
			'currency_code'  => $currency,
			'type'           => 'simple',
			'gateway'        => 'manual',
			'gateway_public' => __('Ręczna płatność', 'wdf'),
			'status'         => 'Manual Payment',
			'gateway_msg'    => __('Przykładowa wpłata', 'wdf'),
			'recurring'      => 0,
		);

		update_post_meta($pledge_id, 'wdf_transaction', $transaction);
		update_post_meta($pledge_id, 'wdf_native', '1');

		return $pledge_id;
	}

	/**
	 * Sample campaign content (Polish).
	 */
	private static function get_sample_content() {
		return '<p>' . __('To jest przykładowa zbiórka stworzona automatycznie przez wtyczkę WP Simple Donations.', 'wdf') . '</p>'
			. '<p>' . __('Możesz ją edytować, usunąć lub użyć jako wzór do stworzenia własnych zbiórek.', 'wdf') . '</p>'
			. '<h3>' . __('Jak to działa?', 'wdf') . '</h3>'
			. '<ol>'
			. '<li>' . __('Ustaw cel i opis zbiórki', 'wdf') . '</li>'
			. '<li>' . __('Skonfiguruj bramkę płatności (PayPal, Przelewy24 lub płatność ręczna)', 'wdf') . '</li>'
			. '<li>' . __('Opublikuj i udostępnij link do zbiórki', 'wdf') . '</li>'
			. '</ol>';
	}

	/**
	 * Get first administrator ID.
	 */
	private static function get_admin_id() {
		$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
		return !empty($admins) ? (int) $admins[0] : 1;
	}

	// --- Admin UI for manual re-seed ---

	public static function admin_menu() {
		add_management_page(
			__('WP Simple Donations — Przykładowe dane', 'wdf'),
			__('Seeder zbiórek', 'wdf'),
			'manage_options',
			'wdf-seeder',
			array(__CLASS__, 'admin_page')
		);
	}

	public static function admin_page() {
		?>
		<div class="wrap">
			<h1><?php _e('WP Simple Donations — Przykładowe dane', 'wdf'); ?></h1>

			<?php if (isset($_GET['wdf_seeded'])) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php _e('Przykładowa zbiórka z wpłatami została utworzona.', 'wdf'); ?></p>
				</div>
			<?php endif; ?>

			<p><?php _e('Kliknij przycisk poniżej, aby utworzyć przykładową zbiórkę z trzema testowymi wpłatami.', 'wdf'); ?></p>

			<form method="post">
				<?php wp_nonce_field('wdf_reseed', 'wdf_reseed_nonce'); ?>
				<p>
					<input type="submit" name="wdf_reseed" class="button button-primary"
						value="<?php esc_attr_e('Utwórz przykładową zbiórkę', 'wdf'); ?>" />
				</p>
			</form>
		</div>
		<?php
	}

	public static function handle_reseed() {
		if (!isset($_POST['wdf_reseed'])) {
			return;
		}
		if (!current_user_can('manage_options')) {
			return;
		}
		if (!isset($_POST['wdf_reseed_nonce']) || !wp_verify_nonce($_POST['wdf_reseed_nonce'], 'wdf_reseed')) {
			return;
		}

		self::seed();

		wp_safe_redirect(admin_url('tools.php?page=wdf-seeder&wdf_seeded=1'));
		exit;
	}
}
