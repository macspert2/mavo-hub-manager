<?php
/** Site-wide orphan / invalid relationship diagnostics. */
require __DIR__ . '/harness.php';

mock_enable_polylang( true );

mock_post( 1, [ 'post_title' => 'France', 'post_type' => 'page', 'lang' => 'fr' ] );
mock_post( 2, [ 'post_title' => 'City trips', 'post_type' => 'page', 'lang' => 'fr' ] );
mock_post( 3, [ 'post_title' => 'Article ok', 'lang' => 'fr' ] );
mock_post( 4, [ 'post_title' => 'Article orphelin', 'lang' => 'fr' ] );
mock_post( 5, [ 'post_title' => 'Article mal type', 'lang' => 'fr' ] );
mock_post( 6, [ 'post_title' => 'Article anglais', 'lang' => 'en' ] );
mock_post( 7, [ 'post_title' => 'Article auto', 'lang' => 'fr' ] );

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'theme' );

MHM_Model::set_primary_hub( 3, 1, 'geo' );  // healthy
MHM_Model::set_primary_hub( 6, 1, 'geo' );  // cross-language

// Damage written straight to meta, the way a bad import or a deleted post would.
update_post_meta( 4, MHM_Model::META_GEO_HUB, 999 );  // missing target
update_post_meta( 5, MHM_Model::META_GEO_HUB, 2 );    // points at a theme hub
update_post_meta( 7, MHM_Model::META_GEO_HUB, 7 );    // self-reference

$report = MHM_Model::run_diagnostics();

$children = static fn( array $rows ): array => array_column( $rows, 'child' );

is_same( [ 4 ], $children( $report['missing_target'] ), 'a primary hub pointing at a missing object is reported' );
is_same( [ 5 ], $children( $report['wrong_hub_type'] ), 'a primary hub with the wrong hub type is reported' );
is_same( [ 7 ], $children( $report['self_reference'] ), 'a self-reference is reported' );
is_same( [ 6 ], $children( $report['cross_language'] ), 'a cross-language relationship is reported' );
is_same( [], $children( $report['cycle'] ), 'no cycle is invented' );
is_same( 5, $report['scanned'], 'every stored relationship was inspected' );

// Nothing is repaired on its own.
is_same( 999, MHM_Model::get_primary_hub( 4, 'geo' ), 'the broken value is left in place' );
is_same( 2, MHM_Model::get_primary_hub( 5, 'geo' ), 'the wrong-type value is left in place' );

// A genuine loop, again written behind the model's back.
mock_post( 8, [ 'post_title' => 'Boucle A', 'post_type' => 'page', 'lang' => 'fr' ] );
mock_post( 9, [ 'post_title' => 'Boucle B', 'post_type' => 'page', 'lang' => 'fr' ] );
MHM_Model::set_hub_type( 8, 'geo' );
MHM_Model::set_hub_type( 9, 'geo' );
update_post_meta( 8, MHM_Model::META_GEO_HUB, 9 );
update_post_meta( 9, MHM_Model::META_GEO_HUB, 8 );

$report = MHM_Model::run_diagnostics();
is_same( [ 8, 9 ], $children( $report['cycle'] ), 'both sides of a loop are reported' );

finish();
