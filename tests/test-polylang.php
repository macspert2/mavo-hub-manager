<?php
/** Polylang behaviour when the plugin is active. */
require __DIR__ . '/harness.php';

mock_enable_polylang( true );

mock_post( 1, [ 'post_title' => 'Paris en famille', 'post_type' => 'page', 'post_name' => 'paris-en-famille', 'lang' => 'fr' ] );
mock_post( 2, [ 'post_title' => 'Paris with kids', 'post_type' => 'page', 'post_name' => 'paris-with-kids', 'lang' => 'en' ] );
mock_post( 3, [ 'post_title' => 'Le Louvre', 'post_name' => 'le-louvre', 'lang' => 'fr' ] );
mock_post( 4, [ 'post_title' => 'The Louvre', 'post_name' => 'the-louvre', 'lang' => 'en' ] );

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );

echo "Language reporting\n";

ok( MHM_Model::has_polylang(), 'Polylang is detected' );
is_same( 'fr', MHM_Model::get_language( 1 ), 'the hub language is reported' );
is_same( 'en', MHM_Model::get_language( 4 ), 'the target language is reported' );
is_same( [ 'en', 'fr' ], MHM_Model::get_hub_languages(), 'the registry lists the languages in use' );

echo "\nSame vs cross language\n";

ok( MHM_Model::is_same_language( 3, 1 ), 'fr child and fr hub match' );
ok( ! MHM_Model::is_same_language( 4, 1 ), 'en child and fr hub do not match' );

is_same( MHM_Scanner::UNASSIGNED, MHM_Scanner::classify( 3, 1, 'geo' )['state'], 'a same-language link is assignable' );
is_same( MHM_Scanner::CROSS_LANGUAGE, MHM_Scanner::classify( 4, 1, 'geo' )['state'], 'a cross-language link is never auto-assigned' );

ok( true === MHM_Model::set_primary_hub( 3, 1, 'geo' ), 'a same-language assignment is allowed' );

// Language is a rule for automatic paths, not a storage constraint: an
// explicit, confirmed admin assignment still goes through the model.
ok( true === MHM_Model::set_primary_hub( 4, 1, 'geo' ), 'an explicit cross-language assignment is still possible' );
$cross = array_column( MHM_Model::run_diagnostics()['cross_language'], 'child' );
is_same( [ 4 ], $cross, 'the cross-language relationship is reported as a diagnostic' );

echo "\nNo translated hubs are invented\n";

is_same( null, MHM_Model::get_primary_hub( 4, 'theme' ), 'no theme relationship appeared' );
is_same( [ 3, 4 ], MHM_Model::get_hub_children( 1, 'geo' ), 'children of the fr hub are exactly what was written' );
is_same( [], MHM_Model::get_hub_children( 2, 'geo' ), 'nothing was mirrored onto the en hub' );

finish();
