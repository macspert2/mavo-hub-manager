<?php
/**
 * Plugin Name: Mavo Hub Manager
 * Plugin URI:  https://mamanvoyage.com
 * Description: Editorial hub model for Maman Voyage. Marks posts/pages as geographic or thematic hubs and assigns one primary geo hub and one primary theme hub per child. Relationships are stored only on the child; hierarchy and reverse lists are inferred.
 * Version:     0.1.0
 * Author:      Mavo
 * Text Domain: mavo-hub-manager
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MHM_VERSION',     '0.1.0' );
define( 'MHM_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MHM_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MHM_PLUGIN_FILE', __FILE__ );

require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-model.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-scanner.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-ajax.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-admin.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-audit.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-graph.php';
require_once MHM_PLUGIN_DIR . 'includes/class-mavo-hub-manager-audit-admin.php';

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'mavo-hub-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	MHM_Admin::init();
	MHM_Audit_Admin::init();
	MHM_Ajax::init();
} );

/*
 * ---------------------------------------------------------------------------
 * Stable procedural API for other mavo-* plugins.
 *
 * Relationship semantics — see also the admin help text:
 *
 *   Primary geographic hub = the most immediate geographic editorial hub that
 *   owns this content.
 *
 *   Primary thematic hub   = the most immediate thematic editorial hub that
 *   owns this content.
 *
 * "Primary" is NOT "every hub that links to this article" and NOT "every
 * relevant place or theme". Broader relationships are inferred by walking the
 * hub hierarchy (mavo_get_hub_ancestors()), never by storing extra records.
 * ---------------------------------------------------------------------------
 */

/** Is this post/page marked as a hub of either type? */
function mavo_is_hub( int $post_id ): bool {
	return null !== MHM_Model::get_hub_type( $post_id );
}

/** Hub type of a post/page: 'geo', 'theme', or null when it is not a hub. */
function mavo_get_hub_type( int $post_id ): ?string {
	return MHM_Model::get_hub_type( $post_id );
}

/** The child's immediate primary hub of the given type, or null. */
function mavo_get_primary_hub( int $post_id, string $type ): ?int {
	return MHM_Model::get_primary_hub( $post_id, $type );
}

/**
 * Assign a primary hub. Runs full canonical validation (existence, post type,
 * hub type, self-reference, cycle) and returns WP_Error on refusal.
 */
function mavo_set_primary_hub( int $post_id, int $hub_id, string $type ) {
	return MHM_Model::set_primary_hub( $post_id, $hub_id, $type );
}

/** Remove a primary hub relationship. True when something was removed. */
function mavo_remove_primary_hub( int $post_id, string $type ): bool {
	return MHM_Model::remove_primary_hub( $post_id, $type );
}

/**
 * Direct children of a hub, derived by querying the children's own meta.
 * No child list is ever stored on the hub.
 */
function mavo_get_hub_children( int $hub_id, string $type, array $args = [] ): array {
	return MHM_Model::get_hub_children( $hub_id, $type, $args );
}

/** Inferred ancestors for one type, nearest first. Never stored. */
function mavo_get_hub_ancestors( int $post_id, string $type ): array {
	return MHM_Model::get_hub_ancestors( $post_id, $type );
}

/** Would assigning $proposed_hub_id to $child_id close a loop? */
function mavo_would_create_hub_cycle( int $child_id, int $proposed_hub_id, string $type ): bool {
	return MHM_Model::would_create_hub_cycle( $child_id, $proposed_hub_id, $type );
}

/** Classified internal-link scan of a hub's stored post_content. */
function mavo_scan_hub_internal_links( int $hub_id ): array {
	return MHM_Scanner::scan( $hub_id );
}

/**
 * Does this post link back to the hub of the given type that owns it?
 *
 * Counts both a real <a href> and a link-back shortcode such as
 * [mavo_hub_strip slug="france"], which renders a link on the frontend only.
 * Returns null when the post has no primary hub of that type.
 */
function mavo_post_links_back_to_hub( int $post_id, string $type ): ?bool {
	$hub_id = MHM_Model::get_primary_hub( $post_id, $type );

	if ( null === $hub_id ) {
		return null;
	}

	$status = MHM_Audit::link_back_status( $post_id, $hub_id, $type );

	return (bool) $status['linked'];
}
