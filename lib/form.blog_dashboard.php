<?php if (!defined('ABSPATH')) exit; ?>
<div id="wdf_dashboard" class="wrap">
	<div id="icon-wdf-admin" class="icon32"><br></div>
	<h2 id="wdf-getting-started"><?php _e('Getting Started Guide','wdf'); ?></h2>
	<p><?php _e('WP Simple Donations can help you fund your important projects.','wdf') ?></p>
	<div class="metabox-holder">

		<?php if(current_user_can('wdf_edit_settings')) { ?>
		<div class="postbox">
			<h3 class="hndle"><span><?php _e('First Time Setup Guide','wdf'); ?></span></h3>
			<div class="inside">
				<div class="updated below-h2">
					<p><?php _e('Welcome to WP Simple Donations!','wdf'); ?></p>
				</div>
				<ol id="wdf_steps">
					<li><?php _e('Configure your settings to start taking simple donations or setup advanced payments to start your own crowdfunding page.','wdf'); ?><a href="<?php echo admin_url('edit.php?post_type=funder&page=wdf_settings'); ?>" class="button wdf_goto_step"><?php _e('Configure Settings','wdf'); ?></a></li>
					<li><?php _e('Create your first campaign, set a goal, and choose a display style.','wdf'); ?><a href="<?php echo admin_url('post-new.php?post_type=funder'); ?>" class="button wdf_goto_step"><?php _e('Create A Campaign','wdf'); ?></a></li>
					<li><?php _e('Choose your presentation style using available widgets or shortcodes.','wdf'); ?><a href="<?php echo admin_url('widgets.php?wdf_show_widgets=1'); ?>" class="button wdf_goto_step"><?php _e('View All Widgets','wdf'); ?></a></li>
				</ol>
			</div>
		</div>
		<?php } ?>

		<div class="postbox">
			<h3 class="hndle"><span><?php _e('Available Shortcodes','wdf'); ?></span></h3>
			<div class="inside">
				<p class="wdf_shortcode_ss"><img src="<?php echo WDF_PLUGIN_URL . '/img/shortcode-generator-screenshot.jpg' ?>" /></p>
				<ul class="wdf_shortcode_breakdown">
					<li>
						<h4><strong><?php _e('Donation Panel','wdf'); ?></strong></h4>
						<p class="description"><?php _e('The donation panel displays relevant information about a particular campaign like: Total Pledges, Goal Information, and links to the pledge checkout page.','wdf'); ?></p>
						<code>[fundraiser_panel id="" style="" show_title="" show_content=""]</code>
						<p class="attr_description">id: <span class="description"><?php _e('The ID of the campaign you wish to display','wdf'); ?></span></p>
						<p class="attr_description">style: <span class="description"><?php _e('A valid loaded style name. This will use the default style if no style is given.','wdf'); ?></span></p>
						<p class="attr_description">show_title: <span class="description"><?php _e('(Yes/No) Shows the title of the campaign above the panel','wdf'); ?></span></p>
						<p class="attr_description">show_content: <span class="description"><?php _e('(Yes/No) Shows the post content of the campaign above the panel','wdf'); ?></span></p>
					</li>
					<li>
						<h4><strong><?php _e('Simple Donate Button','wdf'); ?></strong></h4>
						<p class="description"><?php _e('The simple donation button allows you to take simple paypal donations with one click.','wdf'); ?></p>
						<code>[donate_button title="" description="" donation_amount="" button_type="default/custom" style="" button_text="" show_cc="yes/no" small_button="yes/no" paypal_email=""]</code>
						<?php /*?>
						<p class="attr_description">title: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">description: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">donation_amount: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">button_type: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">style: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">button_text: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p>
						<p class="attr_description">type: <span class="description"><?php //_e('The type of donate_button to display.  paypal is the only type accepted at this time.','wdf'); ?></span></p><?php */?>
					</li>
					<li>
						<h4><strong><?php _e('Donation Progress Bar','wdf'); ?></strong></h4>
						<p class="description"><?php _e('Display a progress bar for a particular campaign.','wdf'); ?></p>
						<code>[progress_bar id="" style="" show_title="yes/no" show_totals="yes/no"]</code>
						<p class="attr_description">id: <span class="description"><?php _e('The ID of the campaign you wish to display a progress bar for.','wdf'); ?></span></p>
						<p class="attr_description">style: <span class="description"><?php _e('A valid loaded style name. This will use the default style if no style is given.','wdf'); ?></span></p>
						<p class="attr_description">show_title: <span class="description"><?php _e('(Yes/No) Shows the title of the campaign above the progress bar - Default: no','wdf'); ?></span></p>
						<p class="attr_description">show_totals: <span class="description"><?php _e('(Yes/No) Shows the donation goal and amount raised above the progress bar. - Default: no','wdf'); ?></span></p>
					</li>
				</ul>
			</div>
		</div>

		<?php if(current_user_can('wdf_edit_settings')) { ?>
		<div class="postbox">
			<h3 class="hndle"><span><?php _e('Need Help?','wdf'); ?></span></h3>
			<div class="inside">
				<form action="<?php echo admin_url('edit.php?post_type=funder&page=wdf'); ?>" method="post">
					<label><?php _e('Restart the getting started walkthrough?','wdf'); ?></label>
					<input type="submit" name="wdf_restart_tutorial" class="button" value="<?php esc_attr(_e('Restart the Tutorial','wdf')); ?>" />
				</form>
			</div>
		</div>
		<?php } ?>

	</div>
</div><!-- #wdf_dashboard -->