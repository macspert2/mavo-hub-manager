<?php
/**
 * Extra stubs for exercising the admin page: rendering and the admin-post
 * router, without WordPress. wp_safe_redirect() throws so a test can inspect
 * where a mutation would have sent the admin.
 */
require_once __DIR__ . '/harness.php';

define( 'MINUTE_IN_SECONDS', 60 );
define( 'MHM_PLUGIN_URL', 'https://www.mamanvoyage.com/wp-content/plugins/mavo-hub-manager/' );
define( 'MHM_VERSION', 'test' );

$GLOBALS['MOCK_TRANSIENTS'] = [];
$GLOBALS['MOCK_REDIRECT']   = '';

class MHM_Redirect extends Exception {}

function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 1; }
function check_admin_referer( $action = -1, $name = '_wpnonce' ) { return true; }
function wp_die( $message = '', $code = 0 ) { throw new Exception( 'wp_die: ' . $message ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_attr__( $text, $domain = '' ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
function wp_kses_post( $html ) { return $html; }
function admin_url( $path = '' ) { return 'https://www.mamanvoyage.com/wp-admin/' . ltrim( (string) $path, '/' ); }
function get_edit_post_link( $id, $context = 'display' ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce" />'; }
function checked( $checked, $current = true, $echo = true ) { return $checked == $current ? " checked='checked'" : ''; }
function selected( $selected, $current = true, $echo = true ) { return $selected == $current ? " selected='selected'" : ''; }
function add_query_arg( $args, $url = '' ) {
	$parts = parse_url( $url );
	$query = [];
	if ( ! empty( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	$query = array_merge( $query, (array) $args );

	return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( $parts['path'] ?? '' ) . '?' . http_build_query( $query );
}
function get_transient( $key ) { return $GLOBALS['MOCK_TRANSIENTS'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['MOCK_TRANSIENTS'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['MOCK_TRANSIENTS'][ $key ] ); return true; }
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['MOCK_REDIRECT'] = $location;

	throw new MHM_Redirect( $location );
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function add_management_page( ...$args ) {}

require_once __DIR__ . '/../includes/class-mavo-hub-manager-admin.php';

/** Run a POST task through the router and return the redirect URL. */
function post_task( array $fields ): string {
	$_POST                    = $fields;
	$GLOBALS['MOCK_REDIRECT'] = '';

	try {
		MHM_Admin::handle_post();
	} catch ( MHM_Redirect $e ) {
		// Expected: every task ends in a redirect.
	}

	$_POST = [];

	return $GLOBALS['MOCK_REDIRECT'];
}

/** Render the page with the given query args and return its HTML. */
function render_page( array $query = [] ): string {
	$_GET = $query;

	ob_start();
	MHM_Admin::render_page();
	$html = ob_get_clean();

	$_GET = [];

	return $html;
}

/** Messages queued for the next page load. */
function queued_notices(): array {
	return array_column( (array) get_transient( 'mhm_notices_1' ), 'message' );
}
