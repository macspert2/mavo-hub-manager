<?php
/**
 * Post status, across every path that answers "what are this hub's children?".
 *
 * Three answers have to agree: the model's WP_Query, the grouped SQL in
 * MHM_Audit, and the display count. They used to disagree about trashed
 * children — the SQL had no status condition at all while every WP_Query used
 * 'any', which excludes trash — and no test could see it, because the audit's
 * SQL branch returns null without a $wpdb and the harness had none.
 *
 * A fourth rule pulls the other way, deliberately: the cleanup paths must
 * reach trashed children, because their meta survives the trash and would
 * otherwise come back pointing at a post that is no longer a hub.
 */

require __DIR__ . '/harness.php';

/* The SQL branch must actually run, or everything below proves nothing. */

mock_post( 50, [ 'post_title' => 'France', 'post_type' => 'page' ] );
mock_post( 51, [ 'post_title' => 'Published child' ] );
mock_post( 52, [ 'post_title' => 'Draft child', 'post_status' => 'draft' ] );
mock_post( 53, [ 'post_title' => 'Trashed child', 'post_status' => 'trash' ] );

MHM_Model::set_hub_type( 50, 'geo' );
foreach ( [ 51, 52, 53 ] as $child ) {
	update_post_meta( $child, MHM_Model::META_GEO_HUB, 50 );
}

$map    = MHM_Audit::relationship_map( 'geo' );
$counts = MHM_Audit::child_count_map( 'geo' );

is_same( true, is_array( $map ), 'relationship_map() takes the $wpdb branch, not the null fallback' );
is_same( true, is_array( $counts ), 'child_count_map() takes the $wpdb branch too' );

/* The three views agree. */

// The no-args call is what mavo_get_hub_children() forwards, so this is also
// the guarantee an outside consumer gets: never a draft unless it asks.
is_same( [ 51 ], MHM_Model::get_hub_children( 50, 'geo' ), 'the default is published only' );
is_same(
	[ 52, 51 ],
	MHM_Model::get_hub_children( 50, 'geo', [ 'post_status' => MHM_Model::EDITORIAL_STATUSES ] ),
	'the editorial set adds the draft and still excludes the trashed one'
);
is_same( 2, MHM_Model::count_hub_children( 50, 'geo' ), 'the display count matches the editorial set' );
is_same( 2, $counts[50] ?? 0, 'the grouped SQL count agrees with it' );
is_same( [ 51 => 50, 52 => 50 ], $map, 'the relationship map excludes the trashed child' );

/* Cleanup reaches further than display, on purpose. */

$result = MHM_Model::remove_hub_type( 50, true );

is_same( 3, $result['removed'], 'unmarking the hub clears all three, trashed included' );
is_same( '', (string) get_post_meta( 53, MHM_Model::META_GEO_HUB, true ), 'the trashed child keeps no dangling pointer' );

finish();
