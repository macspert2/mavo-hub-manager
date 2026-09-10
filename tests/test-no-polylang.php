<?php
/** The plugin must not fatal, and must stay usable, without Polylang. */
putenv( 'MHM_NO_PLL=1' ); // The harness then never defines pll_get_post_language().
require __DIR__ . '/harness.php';

ok( ! function_exists( 'pll_get_post_language' ), 'Polylang is genuinely absent in this run' );

mock_post( 1, [ 'post_title' => 'France', 'post_type' => 'page', 'post_name' => 'france' ] );
mock_post( 2, [ 'post_title' => 'Paris', 'post_type' => 'page', 'post_name' => 'paris' ] );
mock_post( 3, [ 'post_title' => 'Le Louvre', 'post_name' => 'le-louvre' ] );

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );

ok( ! MHM_Model::has_polylang(), 'the model reports Polylang as unavailable' );
is_same( '', MHM_Model::get_language( 1 ), 'the language lookup returns an empty string' );
is_same( [], MHM_Model::get_hub_languages(), 'the language filter has nothing to offer' );
ok( MHM_Model::is_same_language( 3, 1 ), 'every pair counts as same-language' );

ok( true === MHM_Model::set_primary_hub( 2, 1, 'geo' ), 'relationships still work' );
ok( true === MHM_Model::set_primary_hub( 3, 2, 'geo' ), 'a second level still works' );
is_same( [ 2, 1 ], MHM_Model::get_hub_ancestors( 3, 'geo' ), 'hierarchy still works' );

$GLOBALS['MOCK_POSTS'][1]->post_content = '<a href="/paris/">Paris</a>';
$scan = MHM_Scanner::scan( 1 );
ok( ! is_wp_error( $scan ), 'the scanner still runs' );
is_same( MHM_Scanner::ALREADY_ASSIGNED_HERE, $scan['rows'][0]['state'], 'classification still works' );

finish();
