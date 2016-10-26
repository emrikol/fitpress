<?php
/**
 * FitPress Settings Page
 *
 * @package  WordPress
 */

?>
<div class='wrap fitpress-settings'>
	<h2>FitPress Settings</h2>

	<form method='post' action='options.php'>
		<?php settings_fields( 'fitpress_settings' ); ?>
		<?php do_settings_sections( 'fitpress_settings' ); ?>

		<h3>FitPress API Credentials</h3>
		<div class='form-padding'>
		<table class='form-table'>
			<tr valign='top'>
			<th scope='row'>OAuth 2.0 Client ID:</th>
			<td>
				<input type='text' name='fitpress_api_id' value='<?php echo esc_attr( get_option( 'fitpress_api_id' ) ); ?>' />
			</td>
			</tr>

			<tr valign='top'>
			<th scope='row'>Client Secret:</th>
			<td>
				<input type='text' name='fitpress_api_secret' value='<?php echo esc_attr( get_option( 'fitpress_api_secret' ) ); ?>' />
			</td>
			</tr>

			<tr valign='top'>
			<th scope='row'>Debug access token:</th>
			<td>
				<input type='text' name='fitpress_token_override' value='<?php echo esc_attr( get_option( 'fitpress_token_override' ) ); ?>' />
			</td>
			</tr>
		</table> <!-- .form-table -->
		<p>
			<strong>Instructions:</strong>
			<ol>
				<li>Register as a FitBit Developer at <a href='https://dev.fitbit.com/' target="_blank">dev.fitbit.com</a>.</li>
				<li>Click "Register an application"</li>
				<li>Enter the name, basic description, plus your "Application Website" URL: <code><?php echo esc_url_raw( $blog_url ); ?></code></li>
				<li>Set your "Callback URL" to: <code><?php echo esc_url_raw( admin_url( 'admin-post.php?action=fitpress_auth_callback' ) ); ?></code></li>
				<li>Set the "OAuth 2.0 Application Type" type to "Server"</li>
				<li>Set the "Default Access Type" to "Read-Only", and hit <em>register</em></li>
				<li>Paste your <em>OAuth 2.0 Client ID/Client Secret</em> provided by FitBit into the fields above, then click the Save all settings button.</li>
			</ol>
		</p>
		<?php submit_button( 'Save all settings' ); ?>
	</form>
</div>
