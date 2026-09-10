<?php
/** Hub type, relationship validity and inferred hierarchy. */
require __DIR__ . '/harness.php';

echo "Hub type\n";

mock_post( 1, [ 'post_title' => 'France', 'post_type' => 'page' ] );
mock_post( 2, [ 'post_title' => 'Paris', 'post_type' => 'page' ] );
mock_post( 3, [ 'post_title' => 'Paris en famille' ] );
mock_post( 4, [ 'post_title' => '10 activites a Paris en famille' ] );
mock_post( 5, [ 'post_title' => 'City trips en famille', 'post_type' => 'page' ] );

ok( ! is_wp_error( MHM_Model::set_hub_type( 4, 'geo' ) ), 'a post can be marked geo' );
is_same( 'geo', MHM_Model::get_hub_type( 4 ), 'the geo mark reads back' );
ok( ! is_wp_error( MHM_Model::set_hub_type( 5, 'theme' ) ), 'a page can be marked theme' );
is_same( 'theme', MHM_Model::get_hub_type( 5 ), 'the theme mark reads back' );

is_same( 'mhm_invalid_type', error_code( MHM_Model::set_hub_type( 4, 'both' ) ), 'an invalid hub type is rejected' );
is_same( 'mhm_invalid_post', error_code( MHM_Model::set_hub_type( 999, 'geo' ) ), 'a missing post cannot be marked' );
is_same( null, MHM_Model::get_hub_type( 3 ), 'an unmarked post has no hub type' );

// Type change with no children needs no confirmation.
$changed = MHM_Model::set_hub_type( 4, 'theme' );
ok( ! is_wp_error( $changed ), 'a childless hub changes type freely' );
is_same( 0, $changed['removed'], 'nothing was removed' );
MHM_Model::remove_hub_type( 4 );
is_same( null, MHM_Model::get_hub_type( 4 ), 'the hub was unmarked' );

echo "\nPrimary relationships\n";

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );
MHM_Model::set_hub_type( 3, 'geo' );
MHM_Model::set_hub_type( 5, 'theme' );

ok( true === MHM_Model::set_primary_hub( 4, 3, 'geo' ), 'a geo child accepts a geo hub' );
is_same( 3, MHM_Model::get_primary_hub( 4, 'geo' ), 'the geo relationship reads back' );

is_same( 'mhm_wrong_hub_type', error_code( MHM_Model::set_primary_hub( 4, 5, 'geo' ) ), 'a theme hub is refused as a geo hub' );
is_same( 'mhm_wrong_hub_type', error_code( MHM_Model::set_primary_hub( 4, 3, 'theme' ) ), 'a geo hub is refused as a theme hub' );

ok( true === MHM_Model::set_primary_hub( 4, 5, 'theme' ), 'the same child accepts a theme hub too' );
is_same( 5, MHM_Model::get_primary_hub( 4, 'theme' ), 'geo and theme relationships coexist' );

is_same( 'mhm_self_reference', error_code( MHM_Model::set_primary_hub( 3, 3, 'geo' ) ), 'a post cannot be its own hub' );
is_same( 'mhm_invalid_hub', error_code( MHM_Model::set_primary_hub( 4, 12345, 'geo' ) ), 'a missing target is refused' );

mock_post( 90, [ 'post_title' => 'An image', 'post_type' => 'attachment' ] );
update_post_meta( 90, MHM_Model::META_HUB_TYPE, 'geo' );
is_same( 'mhm_invalid_hub', error_code( MHM_Model::set_primary_hub( 4, 90, 'geo' ) ), 'a non post/page target is refused' );
is_same( 'mhm_invalid_child', error_code( MHM_Model::set_primary_hub( 90, 3, 'geo' ) ), 'a non post/page child is refused' );

// A hub is never overwritten by accident: overwriting is a deliberate call.
is_same( 3, MHM_Model::get_primary_hub( 4, 'geo' ), 'the existing geo hub is untouched by the refusals' );
ok( true === MHM_Model::set_primary_hub( 4, 2, 'geo' ), 'an explicit call may overwrite' );
is_same( 2, MHM_Model::get_primary_hub( 4, 'geo' ), 'the overwrite took effect' );

$GLOBALS['MOCK_ACTIONS'] = [];
ok( MHM_Model::remove_primary_hub( 4, 'geo' ), 'removing a relationship reports true' );
is_same( null, MHM_Model::get_primary_hub( 4, 'geo' ), 'the relationship is gone' );
ok( ! MHM_Model::remove_primary_hub( 4, 'geo' ), 'removing again reports false' );
is_same( 'mavo_hub_relationship_changed', $GLOBALS['MOCK_ACTIONS'][0][0] ?? '', 'the relationship hook fired' );

echo "\nHierarchy\n";

MHM_Model::set_primary_hub( 2, 1, 'geo' ); // Paris → France
MHM_Model::set_primary_hub( 3, 2, 'geo' ); // Paris en famille → Paris
MHM_Model::set_primary_hub( 4, 3, 'geo' ); // Article → Paris en famille

is_same( [ 3, 2, 1 ], MHM_Model::get_hub_ancestors( 4, 'geo' ), 'ancestors are nearest first' );
is_same( [], MHM_Model::get_hub_ancestors( 1, 'geo' ), 'a top-level hub has no ancestors' );
is_same( [ 5 ], MHM_Model::get_hub_ancestors( 4, 'theme' ), 'the theme chain is independent of the geo chain' );

ok( MHM_Model::would_create_hub_cycle( 1, 3, 'geo' ), 'France → Paris en famille would close the loop' );
is_same( 'mhm_cycle', error_code( MHM_Model::set_primary_hub( 1, 3, 'geo' ) ), 'the cycle assignment is rejected' );
is_same( null, MHM_Model::get_primary_hub( 1, 'geo' ), 'nothing was written for the rejected cycle' );
ok( MHM_Model::would_create_hub_cycle( 3, 3, 'geo' ), 'self-assignment counts as a cycle' );
ok( ! MHM_Model::would_create_hub_cycle( 5, 1, 'geo' ), 'an unrelated assignment is not a cycle' );

// A chain corrupted outside the model must not hang the walker.
update_post_meta( 1, MHM_Model::META_GEO_HUB, 3 );
ok( MHM_Model::has_cycle( 4, 'geo' ), 'a corrupted chain is detected as a cycle' );
ok( count( MHM_Model::get_hub_ancestors( 4, 'geo' ) ) <= MHM_Model::MAX_DEPTH, 'the ancestor walk stays bounded' );
delete_post_meta( 1, MHM_Model::META_GEO_HUB );

echo "\nChildren and type changes\n";

is_same( [ 4 ], MHM_Model::get_hub_children( 3, 'geo' ), 'children are derived from child meta' );
is_same( 1, MHM_Model::count_hub_children( 3, 'geo' ), 'the direct child count is derived' );
is_same( [], MHM_Model::get_hub_children( 3, 'theme' ), 'the other type has no children here' );

// Nothing is ever mirrored onto the hub.
is_same( [], array_keys( array_diff_key( $GLOBALS['MOCK_META'][3] ?? [], [ MHM_Model::META_HUB_TYPE => 1, MHM_Model::META_GEO_HUB => 1 ] ) ), 'the hub stores no child list' );

$blocked = MHM_Model::set_hub_type( 3, 'theme' );
is_same( 'mhm_confirm_required', error_code( $blocked ), 'a type change with children needs confirmation' );
is_same( 'geo', MHM_Model::get_hub_type( 3 ), 'the hub type did not change' );
is_same( 3, MHM_Model::get_primary_hub( 4, 'geo' ), 'the child relationship survived the refusal' );

$confirmed = MHM_Model::set_hub_type( 3, 'theme', true );
ok( ! is_wp_error( $confirmed ), 'the confirmed type change succeeds' );
is_same( 1, $confirmed['removed'], 'the invalid child relationship was removed' );
is_same( null, MHM_Model::get_primary_hub( 4, 'geo' ), 'the child no longer points at the retyped hub' );

MHM_Model::set_hub_type( 3, 'geo', true );
MHM_Model::set_primary_hub( 4, 3, 'geo' );

$blocked = MHM_Model::remove_hub_type( 3 );
is_same( 'mhm_confirm_required', error_code( $blocked ), 'unmarking with children needs confirmation' );
is_same( 'geo', MHM_Model::get_hub_type( 3 ), 'the hub is still marked' );

$unmarked = MHM_Model::remove_hub_type( 3, true );
is_same( 1, $unmarked['removed'], 'unmarking removed the dangling relationship' );
is_same( null, MHM_Model::get_hub_type( 3 ), 'the hub mark is gone' );
is_same( null, MHM_Model::get_primary_hub( 4, 'geo' ), 'no dangling primary hub ID is left behind' );

finish();
