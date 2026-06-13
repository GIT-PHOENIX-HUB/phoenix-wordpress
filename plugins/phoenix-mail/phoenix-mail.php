<?php
/**
 * Plugin Name:       Phoenix Mail
 * Plugin URI:        https://phoenixelectric.life
 * Description:       Routes all WordPress email through Microsoft Graph (app-only / client credentials) instead of SMTP. A self-hosted replacement for WP Mail SMTP for Microsoft 365 mailboxes. Credentials are entered in Settings → Phoenix Mail (or defined in wp-config.php) — never hardcoded.
 * Version:           1.0.0
 * Requires at least: 5.7
 * Requires PHP:      7.4
 * Author:            Phoenix Electric
 * Author URI:        https://phoenixelectric.life
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       phoenix-mail
 *
 * Built by Claude (the Builder) for Shane Warehime / Phoenix Electric.
 */

defined( 'ABSPATH' ) || exit;

define( 'PHX_MAIL_VERSION', '1.0.0' );
define( 'PHX_MAIL_OPT', 'phoenix_mail_options' );        // option key (array)
define( 'PHX_MAIL_TOKEN_TRANSIENT', 'phoenix_mail_token' );

// ===========================================================================
//  CONFIG  —  read from wp-config constants first (preferred for secrets),
//  then from the saved options. Secrets in wp-config never touch the DB.
// ===========================================================================

/**
 * Resolve the effective config (constants override stored options).
 *
 * @return array { enabled, tenant_id, client_id, client_secret, sender }
 */
function phx_mail_config() {
	$o = get_option( PHX_MAIL_OPT, array() );
	if ( ! is_array( $o ) ) {
		$o = array();
	}

	$tenant = defined( 'PHOENIX_MAIL_TENANT_ID' ) ? PHOENIX_MAIL_TENANT_ID : ( isset( $o['tenant_id'] ) ? $o['tenant_id'] : '' );
	$client = defined( 'PHOENIX_MAIL_CLIENT_ID' ) ? PHOENIX_MAIL_CLIENT_ID : ( isset( $o['client_id'] ) ? $o['client_id'] : '' );
	$secret = defined( 'PHOENIX_MAIL_CLIENT_SECRET' ) ? PHOENIX_MAIL_CLIENT_SECRET : ( isset( $o['client_secret'] ) ? $o['client_secret'] : '' );
	$sender = defined( 'PHOENIX_MAIL_SENDER' ) ? PHOENIX_MAIL_SENDER : ( isset( $o['sender'] ) && $o['sender'] ? $o['sender'] : 'contact@phoenixelectric.life' );

	return array(
		'enabled'       => ! empty( $o['enabled'] ),
		'tenant_id'     => trim( $tenant ),
		'client_id'     => trim( $client ),
		'client_secret' => trim( $secret ),
		'sender'        => trim( $sender ),
	);
}

/**
 * True when we have everything needed to talk to Graph.
 *
 * @param array $c Config.
 * @return bool
 */
function phx_mail_is_configured( $c ) {
	return $c['tenant_id'] && $c['client_id'] && $c['client_secret'] && is_email( $c['sender'] );
}

// ===========================================================================
//  GRAPH TOKEN  —  client-credentials flow, cached in a transient.
// ===========================================================================

/**
 * Get a Graph access token (cached). Returns token string or WP_Error.
 *
 * @param array $c     Config.
 * @param bool  $force Bypass the cache.
 * @return string|WP_Error
 */
function phx_mail_get_token( $c, $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( PHX_MAIL_TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}
	}

	$url = 'https://login.microsoftonline.com/' . rawurlencode( $c['tenant_id'] ) . '/oauth2/v2.0/token';

	$resp = wp_remote_post(
		$url,
		array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			'body'    => array(
				'client_id'     => $c['client_id'],
				'client_secret' => $c['client_secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
				'grant_type'    => 'client_credentials',
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( 200 !== $code || empty( $body['access_token'] ) ) {
		$msg = isset( $body['error_description'] ) ? $body['error_description'] : 'Token request failed (HTTP ' . $code . ').';
		return new WP_Error( 'phx_mail_token', $msg );
	}

	$ttl = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
	set_transient( PHX_MAIL_TOKEN_TRANSIENT, $body['access_token'], max( 60, $ttl - 120 ) );

	return $body['access_token'];
}

// ===========================================================================
//  SEND  —  build a Graph message from wp_mail-style args and POST it.
// ===========================================================================

/**
 * Send one message through Graph. Returns true on success or WP_Error.
 *
 * @param array|string $to          Recipient(s).
 * @param string       $subject     Subject.
 * @param string       $message     Body.
 * @param array|string $headers     wp_mail headers.
 * @param array        $attachments File paths.
 * @return true|WP_Error
 */
function phx_mail_send( $to, $subject, $message, $headers = array(), $attachments = array() ) {
	$c = phx_mail_config();
	if ( ! phx_mail_is_configured( $c ) ) {
		return new WP_Error( 'phx_mail_config', 'Phoenix Mail is not fully configured (tenant, client id/secret, sender).' );
	}

	$token = phx_mail_get_token( $c );
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	$parsed       = phx_mail_parse_headers( $headers );
	$content_type = $parsed['content_type'] ? $parsed['content_type'] : 'text/plain';
	$is_html      = ( false !== stripos( $content_type, 'text/html' ) );

	$graph_message = array(
		'subject'      => (string) $subject,
		'body'         => array(
			'contentType' => $is_html ? 'HTML' : 'Text',
			'content'     => (string) $message,
		),
		'toRecipients' => phx_mail_recipients( $to ),
	);

	if ( ! empty( $parsed['cc'] ) ) {
		$graph_message['ccRecipients'] = phx_mail_recipients( $parsed['cc'] );
	}
	if ( ! empty( $parsed['bcc'] ) ) {
		$graph_message['bccRecipients'] = phx_mail_recipients( $parsed['bcc'] );
	}
	if ( ! empty( $parsed['reply_to'] ) ) {
		$graph_message['replyTo'] = phx_mail_recipients( $parsed['reply_to'] );
	}

	// Always send AS the configured mailbox (app-only). Honor a From display name if given.
	$from_addr = ! empty( $parsed['from_email'] ) ? $parsed['from_email'] : $c['sender'];
	$graph_message['from'] = array(
		'emailAddress' => array(
			'address' => $c['sender'],
			'name'    => $parsed['from_name'] ? $parsed['from_name'] : '',
		),
	);
	unset( $from_addr ); // sending mailbox is fixed to $c['sender']; From header name preserved above.

	$att = phx_mail_attachments( $attachments );
	if ( ! empty( $att ) ) {
		$graph_message['attachments'] = $att;
	}

	$endpoint = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode( $c['sender'] ) . '/sendMail';

	$resp = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode(
				array(
					'message'         => $graph_message,
					'saveToSentItems' => true,
				)
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
		phx_mail_record_error( $resp->get_error_message() );
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );

	// Graph sendMail returns 202 Accepted on success.
	if ( 202 === $code ) {
		update_option( 'phoenix_mail_last_ok', current_time( 'mysql' ), false );
		return true;
	}

	// On 401, the token may be stale — retry once with a fresh token.
	if ( 401 === $code ) {
		$token = phx_mail_get_token( $c, true );
		if ( ! is_wp_error( $token ) ) {
			$resp = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 25,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'message'         => $graph_message,
							'saveToSentItems' => true,
						)
					),
				)
			);
			if ( ! is_wp_error( $resp ) && 202 === wp_remote_retrieve_response_code( $resp ) ) {
				update_option( 'phoenix_mail_last_ok', current_time( 'mysql' ), false );
				return true;
			}
		}
	}

	$body = json_decode( wp_remote_retrieve_body( $resp ), true );
	$err  = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Graph sendMail failed (HTTP ' . $code . ').';
	if ( 403 === $code ) {
		$err .= ' — Check that the app has Microsoft Graph "Mail.Send" APPLICATION permission with admin consent, and that the sender is a valid M365 mailbox.';
	}
	phx_mail_record_error( $err );
	return new WP_Error( 'phx_mail_send', $err );
}

/**
 * Store the last send error for display on the settings screen.
 *
 * @param string $msg Error message.
 */
function phx_mail_record_error( $msg ) {
	update_option(
		'phoenix_mail_last_error',
		array(
			'when'    => current_time( 'mysql' ),
			'message' => (string) $msg,
		),
		false
	);
}

/**
 * Normalize a to/cc/bcc value into Graph recipient objects.
 *
 * @param array|string $value Recipients.
 * @return array
 */
function phx_mail_recipients( $value ) {
	$out = array();
	if ( empty( $value ) ) {
		return $out;
	}
	$list = is_array( $value ) ? $value : explode( ',', $value );
	foreach ( $list as $entry ) {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			continue;
		}
		// Support "Name <email@x>" form.
		if ( preg_match( '/^(.*)<([^>]+)>$/', $entry, $m ) ) {
			$name  = trim( $m[1], " \t\"'" );
			$email = trim( $m[2] );
		} else {
			$name  = '';
			$email = $entry;
		}
		if ( ! is_email( $email ) ) {
			continue;
		}
		$out[] = array(
			'emailAddress' => array_filter(
				array(
					'address' => $email,
					'name'    => $name,
				)
			),
		);
	}
	return $out;
}

/**
 * Parse wp_mail headers (string or array) into the bits we need.
 *
 * @param array|string $headers Headers.
 * @return array
 */
function phx_mail_parse_headers( $headers ) {
	$result = array(
		'content_type' => '',
		'cc'           => array(),
		'bcc'          => array(),
		'reply_to'     => array(),
		'from_email'   => '',
		'from_name'    => '',
	);

	if ( empty( $headers ) ) {
		return $result;
	}

	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
	}

	foreach ( $headers as $header ) {
		if ( false === strpos( $header, ':' ) ) {
			continue;
		}
		list( $name, $value ) = explode( ':', trim( $header ), 2 );
		$name  = strtolower( trim( $name ) );
		$value = trim( $value );

		switch ( $name ) {
			case 'content-type':
				$result['content_type'] = $value;
				break;
			case 'cc':
				$result['cc'] = array_merge( $result['cc'], explode( ',', $value ) );
				break;
			case 'bcc':
				$result['bcc'] = array_merge( $result['bcc'], explode( ',', $value ) );
				break;
			case 'reply-to':
				$result['reply_to'] = array_merge( $result['reply_to'], explode( ',', $value ) );
				break;
			case 'from':
				if ( preg_match( '/^(.*)<([^>]+)>$/', $value, $m ) ) {
					$result['from_name']  = trim( $m[1], " \t\"'" );
					$result['from_email'] = trim( $m[2] );
				} else {
					$result['from_email'] = $value;
				}
				break;
		}
	}

	return $result;
}

/**
 * Build Graph fileAttachments from wp_mail attachment paths.
 *
 * @param array|string $attachments Paths.
 * @return array
 */
function phx_mail_attachments( $attachments ) {
	$out = array();
	if ( empty( $attachments ) ) {
		return $out;
	}
	$list = is_array( $attachments ) ? $attachments : explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
	foreach ( $list as $path ) {
		$path = trim( $path );
		if ( '' === $path || ! is_readable( $path ) ) {
			continue;
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $data ) {
			continue;
		}
		$type = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream';
		$out[] = array(
			'@odata.type'  => '#microsoft.graph.fileAttachment',
			'name'         => basename( $path ),
			'contentType'  => $type ? $type : 'application/octet-stream',
			'contentBytes' => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		);
	}
	return $out;
}

// ===========================================================================
//  HOOK  —  short-circuit wp_mail when enabled + configured.
// ===========================================================================

add_filter( 'pre_wp_mail', 'phx_mail_pre_wp_mail', 10, 2 );

/**
 * @param null|bool $short Short-circuit value (null = let WP handle it).
 * @param array     $atts  wp_mail arguments.
 * @return null|bool
 */
function phx_mail_pre_wp_mail( $short, $atts ) {
	$c = phx_mail_config();

	// Not enabled, or not configured → fall through to default wp_mail / SMTP.
	if ( ! $c['enabled'] || ! phx_mail_is_configured( $c ) ) {
		return $short;
	}

	$to          = isset( $atts['to'] ) ? $atts['to'] : '';
	$subject     = isset( $atts['subject'] ) ? $atts['subject'] : '';
	$message     = isset( $atts['message'] ) ? $atts['message'] : '';
	$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
	$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

	$result = phx_mail_send( $to, $subject, $message, $headers, $attachments );

	// Return a real bool so wp_mail reports success/failure honestly (no silent fallback).
	return is_wp_error( $result ) ? false : true;
}

// ===========================================================================
//  ADMIN  —  settings screen (Settings → Phoenix Mail) + test send.
// ===========================================================================

add_action( 'admin_menu', 'phx_mail_admin_menu' );
function phx_mail_admin_menu() {
	add_options_page(
		'Phoenix Mail',
		'Phoenix Mail',
		'manage_options',
		'phoenix-mail',
		'phx_mail_settings_page'
	);
}

add_action( 'admin_init', 'phx_mail_register_settings' );
function phx_mail_register_settings() {
	register_setting( 'phoenix_mail_group', PHX_MAIL_OPT, 'phx_mail_sanitize' );
}

/**
 * Sanitize + preserve the secret if the field is left blank on save.
 *
 * @param array $input Raw input.
 * @return array
 */
function phx_mail_sanitize( $input ) {
	$existing = get_option( PHX_MAIL_OPT, array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	$out = array();

	$out['enabled']   = ! empty( $input['enabled'] ) ? 1 : 0;
	$out['tenant_id'] = isset( $input['tenant_id'] ) ? sanitize_text_field( $input['tenant_id'] ) : '';
	$out['client_id'] = isset( $input['client_id'] ) ? sanitize_text_field( $input['client_id'] ) : '';
	$out['sender']    = isset( $input['sender'] ) ? sanitize_email( $input['sender'] ) : '';

	// Secret: only overwrite when a new value is typed; blank keeps the stored one.
	$typed = isset( $input['client_secret'] ) ? trim( $input['client_secret'] ) : '';
	if ( '' !== $typed ) {
		$out['client_secret'] = $typed;
	} elseif ( isset( $existing['client_secret'] ) ) {
		$out['client_secret'] = $existing['client_secret'];
	} else {
		$out['client_secret'] = '';
	}

	// A config change may invalidate the cached token.
	delete_transient( PHX_MAIL_TOKEN_TRANSIENT );

	return $out;
}

/**
 * Handle the "send test email" action.
 */
add_action( 'admin_post_phoenix_mail_test', 'phx_mail_handle_test' );
function phx_mail_handle_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No permission.', 'phoenix-mail' ) );
	}
	check_admin_referer( 'phoenix_mail_test', 'phoenix_mail_test_nonce' );

	$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
	$status = 'fail';
	$detail = 'No recipient.';

	if ( $to && is_email( $to ) ) {
		$result = phx_mail_send(
			$to,
			'Phoenix Mail test — ' . get_bloginfo( 'name' ),
			"This is a test message sent through Microsoft Graph by the Phoenix Mail plugin.\n\nIf you received this, app-only sending is working.",
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		if ( is_wp_error( $result ) ) {
			$status = 'fail';
			$detail = $result->get_error_message();
		} else {
			$status = 'ok';
			$detail = 'Sent to ' . $to . '.';
		}
	}

	update_option(
		'phoenix_mail_last_test',
		array(
			'when'   => current_time( 'mysql' ),
			'status' => $status,
			'detail' => $detail,
		),
		false
	);

	wp_safe_redirect( add_query_arg( 'phx_tested', $status, admin_url( 'options-general.php?page=phoenix-mail' ) ) );
	exit;
}

/**
 * Render the settings screen.
 */
function phx_mail_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No permission.', 'phoenix-mail' ) );
	}

	$o = get_option( PHX_MAIL_OPT, array() );
	if ( ! is_array( $o ) ) {
		$o = array();
	}
	$c = phx_mail_config();

	$secret_via_const = defined( 'PHOENIX_MAIL_CLIENT_SECRET' );
	$has_secret       = $secret_via_const || ! empty( $o['client_secret'] );
	$last_error       = get_option( 'phoenix_mail_last_error' );
	$last_test        = get_option( 'phoenix_mail_last_test' );
	$last_ok          = get_option( 'phoenix_mail_last_ok' );
	?>
	<div class="wrap">
		<h1>Phoenix Mail</h1>
		<p>Sends WordPress email through Microsoft Graph (app-only). A replacement for WP Mail SMTP for Microsoft&nbsp;365 mailboxes.</p>

		<?php if ( ! $c['enabled'] ) : ?>
			<div class="notice notice-warning inline"><p><strong>Override is OFF.</strong> WordPress is still using its default mailer (e.g. WP Mail SMTP). Configure below, send a test, then turn the override on.</p></div>
		<?php else : ?>
			<div class="notice notice-success inline"><p><strong>Override is ON.</strong> All WordPress email is being sent through Microsoft Graph.</p></div>
		<?php endif; ?>

		<?php if ( $last_error && is_array( $last_error ) ) : ?>
			<div class="notice notice-error inline"><p><strong>Last error</strong> (<?php echo esc_html( $last_error['when'] ); ?>): <?php echo esc_html( $last_error['message'] ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'phoenix_mail_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Enable Graph override</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( PHX_MAIL_OPT ); ?>[enabled]" value="1" <?php checked( ! empty( $o['enabled'] ) ); ?> />
							Route all <code>wp_mail()</code> through Microsoft Graph
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phx_tenant">Tenant ID</label></th>
					<td>
						<?php if ( defined( 'PHOENIX_MAIL_TENANT_ID' ) ) : ?>
							<em>Defined in <code>wp-config.php</code>.</em>
						<?php else : ?>
							<input type="text" id="phx_tenant" class="regular-text" name="<?php echo esc_attr( PHX_MAIL_OPT ); ?>[tenant_id]" value="<?php echo esc_attr( isset( $o['tenant_id'] ) ? $o['tenant_id'] : '' ); ?>" autocomplete="off" />
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phx_client">Client ID</label></th>
					<td>
						<?php if ( defined( 'PHOENIX_MAIL_CLIENT_ID' ) ) : ?>
							<em>Defined in <code>wp-config.php</code>.</em>
						<?php else : ?>
							<input type="text" id="phx_client" class="regular-text" name="<?php echo esc_attr( PHX_MAIL_OPT ); ?>[client_id]" value="<?php echo esc_attr( isset( $o['client_id'] ) ? $o['client_id'] : '' ); ?>" autocomplete="off" />
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phx_secret">Client Secret</label></th>
					<td>
						<?php if ( $secret_via_const ) : ?>
							<em>Defined in <code>wp-config.php</code>.</em>
						<?php else : ?>
							<input type="password" id="phx_secret" class="regular-text" name="<?php echo esc_attr( PHX_MAIL_OPT ); ?>[client_secret]" value="" placeholder="<?php echo $has_secret ? '•••••••• (saved — leave blank to keep)' : 'Paste client secret'; ?>" autocomplete="off" />
							<p class="description">Stored in the database. For tighter security, define <code>PHOENIX_MAIL_CLIENT_SECRET</code> in <code>wp-config.php</code> instead.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phx_sender">Sender mailbox</label></th>
					<td>
						<?php if ( defined( 'PHOENIX_MAIL_SENDER' ) ) : ?>
							<em>Defined in <code>wp-config.php</code> (<?php echo esc_html( $c['sender'] ); ?>).</em>
						<?php else : ?>
							<input type="email" id="phx_sender" class="regular-text" name="<?php echo esc_attr( PHX_MAIL_OPT ); ?>[sender]" value="<?php echo esc_attr( $c['sender'] ); ?>" />
							<p class="description">The M365 mailbox mail is sent as. Must be a real mailbox the app may send from.</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>

		<hr />
		<h2>Send a test email</h2>
		<p>Sends through Graph using the saved settings, regardless of the override toggle — use it to verify before turning the override on.</p>
		<?php if ( $last_test && is_array( $last_test ) ) : ?>
			<div class="notice notice-<?php echo 'ok' === $last_test['status'] ? 'success' : 'error'; ?> inline">
				<p><strong>Last test (<?php echo esc_html( $last_test['when'] ); ?>):</strong> <?php echo esc_html( $last_test['detail'] ); ?></p>
			</div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="phoenix_mail_test" />
			<?php wp_nonce_field( 'phoenix_mail_test', 'phoenix_mail_test_nonce' ); ?>
			<input type="email" name="test_to" class="regular-text" placeholder="you@example.com" required />
			<?php submit_button( 'Send test', 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $last_ok ) : ?>
			<p class="description" style="margin-top:12px;">Last successful Graph send: <?php echo esc_html( $last_ok ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}
