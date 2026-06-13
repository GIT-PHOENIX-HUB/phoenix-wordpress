<?php
/**
 * Plugin Name:       Phoenix Electric Estimate Form
 * Plugin URI:        https://phoenixelectric.life
 * Description:       Lightweight estimate-request form for Phoenix Electric. Drop the [phoenix_estimate_form] shortcode on any page to collect leads — saves each submission to the database, emails the office, and gives you an admin screen with CSV export. No third-party form service required.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Phoenix Electric
 * Author URI:        https://phoenixelectric.life
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       phoenix-estimate-form
 *
 * Built by Claude (the Builder) for Shane Warehime / Phoenix Electric.
 */

// ---------------------------------------------------------------------------
// Hard exit if accessed directly.
// ---------------------------------------------------------------------------
defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants.
// ---------------------------------------------------------------------------
define( 'PE_EF_VERSION', '1.0.0' );
define( 'PE_EF_DB_VERSION', '1.0.0' );
define( 'PE_EF_TABLE', 'pe_estimate_entries' );          // un-prefixed; real name is $wpdb->prefix . PE_EF_TABLE
define( 'PE_EF_NOTIFY_EMAIL', 'contact@phoenixelectric.life' );
define( 'PE_EF_NONCE_ACTION', 'pe_ef_submit_action' );
define( 'PE_EF_NONCE_FIELD', 'pe_ef_nonce' );

/**
 * Fully-qualified table name (with the site's table prefix).
 *
 * @return string
 */
function pe_ef_table_name() {
	global $wpdb;
	return $wpdb->prefix . PE_EF_TABLE;
}

/**
 * Canonical list of the form's fields.
 *
 * Each entry: key => [ label, required(bool), type ('text'|'email'|'textarea'|'state') ].
 * This single definition drives the form, validation, DB insert, admin table,
 * email body, and CSV export — change a field here and it flows everywhere.
 *
 * @return array
 */
function pe_ef_fields() {
	return array(
		'first_name'  => array( 'label' => 'First Name',         'required' => true,  'type' => 'text' ),
		'last_name'   => array( 'label' => 'Last Name',          'required' => true,  'type' => 'text' ),
		'address'     => array( 'label' => 'Street Address',     'required' => true,  'type' => 'text' ),
		'city'        => array( 'label' => 'City',               'required' => true,  'type' => 'text' ),
		'state'       => array( 'label' => 'State',              'required' => true,  'type' => 'state' ),
		'zip'         => array( 'label' => 'ZIP',                'required' => true,  'type' => 'text' ),
		'phone'       => array( 'label' => 'Phone',              'required' => true,  'type' => 'text' ),
		'email'       => array( 'label' => 'Email',              'required' => true,  'type' => 'email' ),
		'description' => array( 'label' => 'Description of Work', 'required' => true,  'type' => 'textarea' ),
	);
}

// ===========================================================================
//  ACTIVATION  —  create the database table.
// ===========================================================================

register_activation_hook( __FILE__, 'pe_ef_activate' );

function pe_ef_activate() {
	pe_ef_create_table();
	add_option( 'pe_ef_db_version', PE_EF_DB_VERSION );
}

/**
 * Create / upgrade the submissions table using dbDelta.
 */
function pe_ef_create_table() {
	global $wpdb;

	$table           = pe_ef_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one space
	// between column words, lowercase types. Do not "tidy" this block.
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		first_name varchar(100) NOT NULL DEFAULT '',
		last_name varchar(100) NOT NULL DEFAULT '',
		address varchar(255) NOT NULL DEFAULT '',
		city varchar(100) NOT NULL DEFAULT '',
		state varchar(100) NOT NULL DEFAULT '',
		zip varchar(20) NOT NULL DEFAULT '',
		phone varchar(50) NOT NULL DEFAULT '',
		email varchar(190) NOT NULL DEFAULT '',
		description text NOT NULL,
		ip_address varchar(45) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY created_at (created_at)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

/**
 * Self-healing: if the table is missing on a normal request (e.g. plugin files
 * copied in without re-activation), create it. Cheap option check, runs once.
 */
add_action( 'init', 'pe_ef_maybe_upgrade' );
function pe_ef_maybe_upgrade() {
	if ( get_option( 'pe_ef_db_version' ) !== PE_EF_DB_VERSION ) {
		pe_ef_create_table();
		update_option( 'pe_ef_db_version', PE_EF_DB_VERSION );
	}
}

// ===========================================================================
//  FRONT END  —  shortcode + submission handling.
// ===========================================================================

add_shortcode( 'phoenix_estimate_form', 'pe_ef_render_shortcode' );

/**
 * Render the estimate form (and any success / error flash from a prior submit).
 *
 * @return string
 */
function pe_ef_render_shortcode() {
	$flash = pe_ef_get_flash();
	$errors = isset( $flash['errors'] ) ? (array) $flash['errors'] : array();
	$old    = isset( $flash['old'] ) ? (array) $flash['old'] : array();
	$success = ! empty( $flash['success'] );

	ob_start();
	pe_ef_print_styles_once();
	?>
	<div class="pe-ef-wrap" id="pe-ef-form">

		<?php if ( $success ) : ?>
			<div class="pe-ef-notice pe-ef-notice--success" role="status">
				<strong>Thank you!</strong> Your estimate request has been received. A member of the Phoenix Electric team will reach out shortly.
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="pe-ef-notice pe-ef-notice--error" role="alert">
				<strong>Please fix the following:</strong>
				<ul>
					<?php foreach ( $errors as $err ) : ?>
						<li><?php echo esc_html( $err ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form class="pe-ef" method="post" action="">
			<?php wp_nonce_field( PE_EF_NONCE_ACTION, PE_EF_NONCE_FIELD ); ?>
			<input type="hidden" name="pe_ef_submit" value="1" />

			<?php // Honeypot: hidden from humans, irresistible to bots. ?>
			<div class="pe-ef-hp" aria-hidden="true">
				<label for="pe_ef_hp">Leave this field empty</label>
				<input type="text" name="pe_ef_hp" id="pe_ef_hp" tabindex="-1" autocomplete="off" value="" />
			</div>

			<div class="pe-ef-row">
				<?php
				pe_ef_field_input( 'first_name', $old );
				pe_ef_field_input( 'last_name', $old );
				?>
			</div>

			<?php pe_ef_field_input( 'address', $old ); ?>

			<div class="pe-ef-row pe-ef-row--csz">
				<?php
				pe_ef_field_input( 'city', $old );
				pe_ef_field_input( 'state', $old );
				pe_ef_field_input( 'zip', $old );
				?>
			</div>

			<div class="pe-ef-row">
				<?php
				pe_ef_field_input( 'phone', $old );
				pe_ef_field_input( 'email', $old );
				?>
			</div>

			<?php pe_ef_field_input( 'description', $old ); ?>

			<div class="pe-ef-actions">
				<button type="submit" class="pe-ef-submit">Request My Estimate</button>
			</div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Render one labelled field based on the field definition.
 *
 * @param string $key Field key.
 * @param array  $old Repopulation values from a failed submit.
 */
function pe_ef_field_input( $key, $old ) {
	$fields = pe_ef_fields();
	if ( ! isset( $fields[ $key ] ) ) {
		return;
	}
	$def      = $fields[ $key ];
	$label    = $def['label'];
	$required = ! empty( $def['required'] );
	$value    = isset( $old[ $key ] ) ? $old[ $key ] : '';
	$id       = 'pe_ef_' . $key;
	$req_attr = $required ? ' required' : '';
	$req_mark = $required ? ' <span class="pe-ef-req" aria-hidden="true">*</span>' : '';
	?>
	<div class="pe-ef-field pe-ef-field--<?php echo esc_attr( $key ); ?>">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ) . $req_mark; ?></label>
		<?php if ( 'textarea' === $def['type'] ) : ?>
			<textarea name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $id ); ?>" rows="5"<?php echo $req_attr; ?>><?php echo esc_textarea( $value ); ?></textarea>
		<?php elseif ( 'state' === $def['type'] ) : ?>
			<select name="<?php echo esc_attr( $key ); ?>" id="<?php echo esc_attr( $id ); ?>"<?php echo $req_attr; ?>>
				<option value="">— Select —</option>
				<?php foreach ( pe_ef_states() as $abbr => $name ) : ?>
					<option value="<?php echo esc_attr( $abbr ); ?>" <?php selected( $value, $abbr ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input
				type="<?php echo 'email' === $def['type'] ? 'email' : 'text'; ?>"
				name="<?php echo esc_attr( $key ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				<?php echo $req_attr; ?>
			/>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Handle the form submission early, before any output (Post/Redirect/Get).
 */
add_action( 'template_redirect', 'pe_ef_handle_submission' );

function pe_ef_handle_submission() {
	if ( empty( $_POST['pe_ef_submit'] ) ) {
		return;
	}

	$redirect_base = wp_get_referer();
	if ( ! $redirect_base ) {
		$redirect_base = home_url( '/' );
	}

	// 1. Nonce check.
	if ( ! isset( $_POST[ PE_EF_NONCE_FIELD ] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ PE_EF_NONCE_FIELD ] ) ), PE_EF_NONCE_ACTION ) ) {
		pe_ef_redirect_with_flash( $redirect_base, array(
			'errors' => array( 'Your session expired. Please try submitting the form again.' ),
		) );
	}

	// 2. Honeypot — if filled, it's a bot. Silently accept and drop.
	if ( ! empty( $_POST['pe_ef_hp'] ) ) {
		pe_ef_redirect_with_flash( $redirect_base, array( 'success' => true ) );
	}

	// 3. Collect + sanitize.
	$fields = pe_ef_fields();
	$clean  = array();
	foreach ( $fields as $key => $def ) {
		$raw = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
		if ( 'email' === $def['type'] ) {
			$clean[ $key ] = sanitize_email( $raw );
		} elseif ( 'textarea' === $def['type'] ) {
			$clean[ $key ] = sanitize_textarea_field( $raw );
		} else {
			$clean[ $key ] = sanitize_text_field( $raw );
		}
	}

	// 4. Validate.
	$errors = array();
	foreach ( $fields as $key => $def ) {
		if ( ! empty( $def['required'] ) && '' === trim( (string) $clean[ $key ] ) ) {
			$errors[] = $def['label'] . ' is required.';
		}
	}
	if ( '' !== $clean['email'] && ! is_email( $clean['email'] ) ) {
		$errors[] = 'Please enter a valid email address.';
	}

	if ( ! empty( $errors ) ) {
		pe_ef_redirect_with_flash( $redirect_base, array(
			'errors' => $errors,
			'old'    => $clean,
		) );
	}

	// 5. Persist.
	global $wpdb;
	$ip = isset( $_SERVER['REMOTE_ADDR'] )
		? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 )
		: '';

	$row = array(
		'created_at'  => current_time( 'mysql' ),
		'first_name'  => $clean['first_name'],
		'last_name'   => $clean['last_name'],
		'address'     => $clean['address'],
		'city'        => $clean['city'],
		'state'       => $clean['state'],
		'zip'         => $clean['zip'],
		'phone'       => $clean['phone'],
		'email'       => $clean['email'],
		'description' => $clean['description'],
		'ip_address'  => $ip,
	);

	$wpdb->insert(
		pe_ef_table_name(),
		$row,
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	// 6. Notify the office.
	pe_ef_send_notification( $row );

	// 7. PRG redirect with success flash.
	pe_ef_redirect_with_flash( $redirect_base, array( 'success' => true ) );
}

/**
 * Email the office with the submission details.
 *
 * @param array $row Stored row (already sanitized).
 */
function pe_ef_send_notification( $row ) {
	$fields = pe_ef_fields();

	$subject = sprintf(
		'[Estimate Request] %s %s — %s',
		$row['first_name'],
		$row['last_name'],
		$row['city'] ? $row['city'] . ', ' . $row['state'] : $row['state']
	);

	$lines = array( 'New estimate request submitted on ' . $row['created_at'] . ':', '' );
	foreach ( $fields as $key => $def ) {
		$lines[] = $def['label'] . ': ' . $row[ $key ];
	}
	$lines[] = '';
	$lines[] = 'Submitted from IP: ' . $row['ip_address'];
	$lines[] = 'Site: ' . home_url( '/' );

	$body = implode( "\n", $lines );

	$headers = array();
	if ( $row['email'] && is_email( $row['email'] ) ) {
		$headers[] = sprintf(
			'Reply-To: %s <%s>',
			trim( $row['first_name'] . ' ' . $row['last_name'] ),
			$row['email']
		);
	}

	wp_mail( PE_EF_NOTIFY_EMAIL, $subject, $body, $headers );
}

// ---------------------------------------------------------------------------
//  Flash (transient-backed) helpers — carry errors / old input / success
//  across the PRG redirect without cookies.
// ---------------------------------------------------------------------------

/**
 * Store a flash payload in a short-lived transient and redirect back to the form.
 *
 * @param string $base    Redirect URL (the page the form lives on).
 * @param array  $payload Flash data.
 */
function pe_ef_redirect_with_flash( $base, $payload ) {
	$token = wp_generate_password( 20, false, false );
	set_transient( 'pe_ef_flash_' . $token, $payload, 5 * MINUTE_IN_SECONDS );

	$url = add_query_arg( 'pe_ef_flash', $token, $base );
	$url = remove_query_arg( array( 'pe_ef_sent' ), $url );

	wp_safe_redirect( $url . '#pe-ef-form' );
	exit;
}

/**
 * Read (and consume) the flash payload for this request, if any.
 *
 * @return array
 */
function pe_ef_get_flash() {
	if ( empty( $_GET['pe_ef_flash'] ) ) {
		return array();
	}
	$token   = sanitize_text_field( wp_unslash( $_GET['pe_ef_flash'] ) );
	$key     = 'pe_ef_flash_' . $token;
	$payload = get_transient( $key );
	if ( false === $payload ) {
		return array();
	}
	delete_transient( $key );
	return is_array( $payload ) ? $payload : array();
}

// ===========================================================================
//  ADMIN  —  submissions list + CSV export.
// ===========================================================================

add_action( 'admin_menu', 'pe_ef_admin_menu' );

function pe_ef_admin_menu() {
	add_menu_page(
		'Estimate Entries',          // page title
		'Estimate Entries',          // menu title
		'manage_options',            // capability
		'pe-estimate-entries',       // slug
		'pe_ef_admin_page',          // callback
		'dashicons-clipboard',       // icon
		26                           // position
	);
}

/**
 * Whitelisted sortable columns for the admin table.
 *
 * @return array
 */
function pe_ef_sortable_columns() {
	return array( 'id', 'created_at', 'last_name', 'city', 'state', 'email' );
}

/**
 * Render the admin submissions screen (sortable + paginated).
 */
function pe_ef_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'phoenix-estimate-form' ) );
	}

	global $wpdb;
	$table = pe_ef_table_name();

	// --- Sorting (whitelisted) ---
	$sortable = pe_ef_sortable_columns();
	$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
	if ( ! in_array( $orderby, $sortable, true ) ) {
		$orderby = 'created_at';
	}
	$order = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'ASC' : 'DESC';

	// --- Pagination ---
	$per_page = 25;
	$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$offset   = ( $paged - 1 ) * $per_page;

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );

	// $orderby/$order are whitelisted above; values are interpolated safely.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
			$per_page,
			$offset
		),
		ARRAY_A
	);

	$export_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=pe_ef_export_csv' ),
		'pe_ef_export_csv',
		'pe_ef_export_nonce'
	);

	// Columns shown in the table (in order).
	$columns = array(
		'created_at'  => 'Date',
		'first_name'  => 'First',
		'last_name'   => 'Last',
		'phone'       => 'Phone',
		'email'       => 'Email',
		'city'        => 'City',
		'state'       => 'State',
		'zip'         => 'ZIP',
		'description' => 'Description',
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Estimate Entries</h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
		<hr class="wp-header-end" />

		<p><?php echo esc_html( number_format_i18n( $total ) ); ?> total submission<?php echo 1 === $total ? '' : 's'; ?>.</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<?php foreach ( $columns as $key => $label ) : ?>
						<?php
						if ( in_array( $key, $sortable, true ) ) {
							$next_order = ( $orderby === $key && 'ASC' === $order ) ? 'desc' : 'asc';
							$arrow      = '';
							if ( $orderby === $key ) {
								$arrow = ' ' . ( 'ASC' === $order ? '&uarr;' : '&darr;' );
							}
							$link = add_query_arg(
								array(
									'page'    => 'pe-estimate-entries',
									'orderby' => $key,
									'order'   => $next_order,
									'paged'   => $paged,
								),
								admin_url( 'admin.php' )
							);
							echo '<th scope="col"><a href="' . esc_url( $link ) . '">' . esc_html( $label ) . wp_kses_post( $arrow ) . '</a></th>';
						} else {
							echo '<th scope="col">' . esc_html( $label ) . '</th>';
						}
						?>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="<?php echo count( $columns ); ?>">No submissions yet.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<?php foreach ( $columns as $key => $label ) : ?>
								<td>
									<?php
									if ( 'created_at' === $key ) {
										echo esc_html( mysql2date( 'M j, Y g:i a', $r['created_at'] ) );
									} elseif ( 'email' === $key && ! empty( $r['email'] ) ) {
										echo '<a href="mailto:' . esc_attr( $r['email'] ) . '">' . esc_html( $r['email'] ) . '</a>';
									} elseif ( 'description' === $key ) {
										echo esc_html( wp_trim_words( $r['description'], 18, '…' ) );
									} else {
										echo esc_html( isset( $r[ $key ] ) ? $r[ $key ] : '' );
									}
									?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					// Build the base WITHOUT the paged token so add_query_arg does not
					// URL-encode "%#%"; the token is appended via the format param.
					$pagination_base = add_query_arg(
						array(
							'page'    => 'pe-estimate-entries',
							'orderby' => $orderby,
							'order'   => strtolower( $order ),
						),
						admin_url( 'admin.php' )
					);
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => $pagination_base . '%_%',
								'format'    => '&paged=%#%',
								'current'   => $paged,
								'total'     => $total_pages,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Stream all submissions as a CSV download.
 */
add_action( 'admin_post_pe_ef_export_csv', 'pe_ef_export_csv' );

function pe_ef_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to export.', 'phoenix-estimate-form' ) );
	}
	check_admin_referer( 'pe_ef_export_csv', 'pe_ef_export_nonce' );

	global $wpdb;
	$table  = pe_ef_table_name();
	$fields = pe_ef_fields();

	$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

	$filename = 'estimate-entries-' . gmdate( 'Y-m-d' ) . '.csv';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );

	// Header row.
	$header = array( 'ID', 'Submitted' );
	foreach ( $fields as $def ) {
		$header[] = $def['label'];
	}
	$header[] = 'IP Address';
	fputcsv( $out, $header );

	// Data rows.
	if ( $rows ) {
		foreach ( $rows as $r ) {
			$line = array( $r['id'], $r['created_at'] );
			foreach ( $fields as $key => $def ) {
				$line[] = isset( $r[ $key ] ) ? $r[ $key ] : '';
			}
			$line[] = isset( $r['ip_address'] ) ? $r['ip_address'] : '';
			fputcsv( $out, $line );
		}
	}

	fclose( $out );
	exit;
}

// ===========================================================================
//  PRESENTATION  —  scoped front-end styles (printed once).
// ===========================================================================

function pe_ef_print_styles_once() {
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;
	?>
	<style>
		.pe-ef-wrap { max-width: 680px; margin: 0 auto; }
		.pe-ef-hp { position: absolute; left: -9999px; top: -9999px; height: 0; width: 0; overflow: hidden; }
		.pe-ef-notice { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; line-height: 1.5; }
		.pe-ef-notice--success { background: #e7f6ec; border: 1px solid #3fae6b; color: #14532d; }
		.pe-ef-notice--error { background: #fdecec; border: 1px solid #d9534f; color: #842029; }
		.pe-ef-notice ul { margin: 8px 0 0 18px; }
		.pe-ef-row { display: flex; gap: 16px; flex-wrap: wrap; }
		.pe-ef-row .pe-ef-field { flex: 1 1 200px; }
		.pe-ef-row--csz .pe-ef-field--city { flex: 2 1 200px; }
		.pe-ef-row--csz .pe-ef-field--state { flex: 1 1 120px; }
		.pe-ef-row--csz .pe-ef-field--zip { flex: 1 1 120px; }
		.pe-ef-field { margin-bottom: 16px; }
		.pe-ef-field label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px; }
		.pe-ef-req { color: #d9534f; }
		.pe-ef-field input,
		.pe-ef-field select,
		.pe-ef-field textarea {
			width: 100%; padding: 11px 12px; border: 1px solid #c3c4c7;
			border-radius: 6px; font-size: 16px; box-sizing: border-box; background: #fff;
		}
		.pe-ef-field input:focus,
		.pe-ef-field select:focus,
		.pe-ef-field textarea:focus { outline: none; border-color: #d97706; box-shadow: 0 0 0 2px rgba(217,119,6,0.2); }
		.pe-ef-actions { margin-top: 8px; }
		.pe-ef-submit {
			background: #d97706; color: #fff; border: 0; padding: 14px 28px;
			font-size: 16px; font-weight: 700; border-radius: 6px; cursor: pointer;
			transition: background .15s ease;
		}
		.pe-ef-submit:hover { background: #b45309; }
	</style>
	<?php
}

// ===========================================================================
//  US states (abbr => name) — used by the State dropdown.
// ===========================================================================

function pe_ef_states() {
	return array(
		'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
		'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
		'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
		'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
		'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
		'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
		'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
		'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
		'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
		'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
		'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
		'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
		'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
	);
}
