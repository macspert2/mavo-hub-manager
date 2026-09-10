<?php
/**
 * Minimal WordPress stubs so the hub model and scanner can be exercised on the
 * command line. Only the functions these two classes actually call are here.
 */

define( 'ABSPATH', '/' );
define( 'OBJECT', 'OBJECT' );
define( 'ENT_QUOTES_FALLBACK', ENT_QUOTES );

$GLOBALS['MOCK_POSTS']   = []; // id => WP_Post-ish array
$GLOBALS['MOCK_META']    = []; // id => [ key => value ]
$GLOBALS['MOCK_ACTIONS'] = []; // recorded do_action() calls
$GLOBALS['MOCK_HOME']    = 'https://www.mamanvoyage.com';
$GLOBALS['MOCK_PLL']     = false;

class WP_Post {
	public $ID = 0;
	public $post_type = 'post';
	public $post_status = 'publish';
	public $post_title = '';
	public $post_content = '';
	public $post_name = '';
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) ); }
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return $text; }
function apply_filters( $tag, $value ) { return $value; }
function do_action( $tag, ...$args ) { $GLOBALS['MOCK_ACTIONS'][] = array_merge( [ $tag ], $args ); }
function is_ssl() { return true; }
function home_url( $path = '' ) { return rtrim( $GLOBALS['MOCK_HOME'], '/' ) . ( $path ? '/' . ltrim( $path, '/' ) : '' ); }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function untrailingslashit( $string ) { return rtrim( (string) $string, '/\\' ); }

/* ------------------------------------------------------------------ posts */

function mock_post( int $id, array $fields = [] ): WP_Post {
	$post               = new WP_Post();
	$post->ID           = $id;
	$post->post_type    = $fields['post_type'] ?? 'post';
	$post->post_status  = $fields['post_status'] ?? 'publish';
	$post->post_title   = $fields['post_title'] ?? ( 'Post ' . $id );
	$post->post_content = $fields['post_content'] ?? '';
	$post->post_name    = $fields['post_name'] ?? sanitize_title_stub( $post->post_title );

	$GLOBALS['MOCK_POSTS'][ $id ] = $post;

	if ( isset( $fields['lang'] ) ) {
		$GLOBALS['MOCK_LANG'][ $id ] = $fields['lang'];
	}

	return $post;
}

function sanitize_title_stub( string $title ): string {
	$slug = strtolower( strtr( $title, [ 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'û' => 'u' ] ) );

	return trim( preg_replace( '/[^a-z0-9]+/', '-', $slug ), '-' );
}

function get_post( $id ) {
	$id = (int) ( is_object( $id ) ? $id->ID : $id );

	return $GLOBALS['MOCK_POSTS'][ $id ] ?? null;
}

function get_the_title( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );

	return $post ? $post->post_title : '';
}

function get_permalink( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	if ( ! $post ) { return ''; }

	return 'page' === $post->post_type
		? home_url( '/' . $post->post_name . '/' )
		: home_url( '/2024/05/' . $post->post_name . '/' );
}

/* ------------------------------------------------------------------- meta */

function get_post_meta( $post_id, $key = '', $single = false ) {
	$value = $GLOBALS['MOCK_META'][ (int) $post_id ][ $key ] ?? '';

	return $single ? $value : ( '' === $value ? [] : [ $value ] );
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['MOCK_META'][ (int) $post_id ][ $key ] = $value;

	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['MOCK_META'][ (int) $post_id ][ $key ] );

	return true;
}

/* ------------------------------------------------------------ shortcodes */

/** Simplified core shortcode_parse_atts(): quoted and bare key=value pairs. */
function shortcode_parse_atts( $text ) {
	$atts = [];
	$text = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', (string) $text );

	if ( preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"]+)/', $text, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $match ) {
			if ( ! empty( $match[1] ) ) {
				$atts[ strtolower( $match[1] ) ] = stripcslashes( $match[2] );
			} elseif ( ! empty( $match[3] ) ) {
				$atts[ strtolower( $match[3] ) ] = stripcslashes( $match[4] );
			} elseif ( ! empty( $match[5] ) ) {
				$atts[ strtolower( $match[5] ) ] = stripcslashes( $match[6] );
			}
		}
	}

	return $atts;
}

/* ------------------------------------------------------------- Polylang */

function mock_enable_polylang( bool $on = true ): void {
	$GLOBALS['MOCK_PLL'] = $on;
}

// A test can simulate an install without Polylang by setting MHM_NO_PLL=1
// before including this harness: the function is then never defined at all.
if ( '1' !== getenv( 'MHM_NO_PLL' ) ) {
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		if ( ! $GLOBALS['MOCK_PLL'] ) { return ''; }

		return $GLOBALS['MOCK_LANG'][ (int) $post_id ] ?? '';
	}
}

/* ------------------------------------------------------------- WP_Query */

class WP_Query {
	public $posts = [];

	public function __construct( array $args = [] ) {
		$types = (array) ( $args['post_type'] ?? [ 'post', 'page' ] );
		$found = [];

		foreach ( $GLOBALS['MOCK_POSTS'] as $id => $post ) {
			if ( ! in_array( $post->post_type, $types, true ) ) { continue; }
			if ( ! self::matches_meta( $id, $args ) ) { continue; }
			if ( ! self::matches_search( $post, $args ) ) { continue; }

			$found[] = (int) $id;
		}

		usort(
			$found,
			static fn( $a, $b ) => strcasecmp(
				$GLOBALS['MOCK_POSTS'][ $a ]->post_title,
				$GLOBALS['MOCK_POSTS'][ $b ]->post_title
			)
		);

		$limit = (int) ( $args['posts_per_page'] ?? -1 );
		if ( $limit > 0 ) {
			$found = array_slice( $found, 0, $limit );
		}

		$this->posts = $found;
	}

	private static function matches_meta( int $id, array $args ): bool {
		if ( isset( $args['meta_key'] ) ) {
			$stored = (string) ( $GLOBALS['MOCK_META'][ $id ][ $args['meta_key'] ] ?? '' );

			if ( isset( $args['meta_value'] ) && $stored !== (string) $args['meta_value'] ) {
				return false;
			}
			if ( ! isset( $args['meta_value'] ) && '' === $stored ) {
				return false;
			}
		}

		foreach ( (array) ( $args['meta_query'] ?? [] ) as $clause ) {
			if ( ! is_array( $clause ) || empty( $clause['key'] ) ) { continue; }

			$stored  = (string) ( $GLOBALS['MOCK_META'][ $id ][ $clause['key'] ] ?? '' );
			$compare = $clause['compare'] ?? '=';

			if ( 'EXISTS' === $compare && '' === $stored ) { return false; }
			if ( 'IN' === $compare && ! in_array( $stored, array_map( 'strval', (array) $clause['value'] ), true ) ) { return false; }
			if ( '=' === $compare && isset( $clause['value'] ) && $stored !== (string) $clause['value'] ) { return false; }
		}

		return true;
	}

	private static function matches_search( WP_Post $post, array $args ): bool {
		$term = (string) ( $args['s'] ?? '' );

		return '' === $term || false !== stripos( $post->post_title, $term );
	}
}

function get_posts( array $args = [] ) {
	$name  = (string) ( $args['name'] ?? '' );
	$types = (array) ( $args['post_type'] ?? [ 'post', 'page' ] );
	$found = [];

	foreach ( $GLOBALS['MOCK_POSTS'] as $id => $post ) {
		if ( $name && $post->post_name !== $name ) { continue; }
		if ( ! in_array( $post->post_type, $types, true ) ) { continue; }

		$found[] = (int) $id;
	}

	return $found;
}

function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	$path  = trim( (string) $path, '/' );
	$types = (array) $post_type;

	foreach ( $GLOBALS['MOCK_POSTS'] as $post ) {
		if ( in_array( $post->post_type, $types, true ) && $post->post_name === $path ) {
			return $post;
		}
	}

	return null;
}

/** Resolve internal permalinks the way WordPress would for this stub site. */
function url_to_postid( $url ) {
	$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	$site = preg_replace( '/^www\./', '', strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ) );

	if ( preg_replace( '/^www\./', '', $host ) !== $site ) {
		return 0;
	}

	$path     = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
	$segments = array_filter( explode( '/', $path ) );
	$slug     = (string) end( $segments );

	if ( '' === $slug ) { return 0; }

	foreach ( $GLOBALS['MOCK_POSTS'] as $id => $post ) {
		if ( $post->post_name === $slug ) { return (int) $id; }
	}

	return 0;
}

/* ------------------------------------------------------------- assertions */

$GLOBALS['MOCK_TESTS']  = 0;
$GLOBALS['MOCK_FAILED'] = 0;

function ok( bool $condition, string $label ): void {
	$GLOBALS['MOCK_TESTS']++;

	if ( $condition ) {
		echo "  ok   $label\n";

		return;
	}

	$GLOBALS['MOCK_FAILED']++;
	echo "  FAIL $label\n";
}

function is_same( $expected, $actual, string $label ): void {
	ok(
		$expected === $actual,
		$label . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) )
	);
}

function error_code( $thing ): string {
	return $thing instanceof WP_Error ? $thing->get_error_code() : '(not an error)';
}

function reset_store(): void {
	$GLOBALS['MOCK_POSTS']   = [];
	$GLOBALS['MOCK_META']    = [];
	$GLOBALS['MOCK_LANG']    = [];
	$GLOBALS['MOCK_ACTIONS'] = [];
	$GLOBALS['MOCK_PLL']     = false;
}

function finish(): void {
	$total  = $GLOBALS['MOCK_TESTS'];
	$failed = $GLOBALS['MOCK_FAILED'];

	echo sprintf( "\n%d assertions, %d failed\n", $total, $failed );

	exit( $failed ? 1 : 0 );
}

require_once __DIR__ . '/../includes/class-mavo-hub-manager-model.php';
require_once __DIR__ . '/../includes/class-mavo-hub-manager-scanner.php';
require_once __DIR__ . '/../includes/class-mavo-hub-manager-audit.php';

reset_store();
