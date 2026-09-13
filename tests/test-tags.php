<?php
/**
 * Finding children by tag.
 *
 * The point of these: a tag list must classify exactly like the link scanner,
 * because both feed the same batch-assign path.
 */
require_once __DIR__ . '/harness.php';

reset_store();

mock_post( 1, [ 'post_type' => 'page', 'post_title' => 'Italie', 'post_name' => 'italie' ] );
mock_post( 2, [ 'post_type' => 'page', 'post_title' => 'Toscane', 'post_name' => 'toscane' ] );
MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );

$italie  = mock_term( 10, 'Italie', 'italie', 142 );
$toscane = mock_term( 11, 'Toscane', 'toscane', 12 );

echo "Suggesting a tag for a hub\n";

is_same( 10, MHM_Tags::suggest_for_hub( 1 )->term_id, 'the tag whose slug matches the hub is suggested' );
is_same( 11, MHM_Tags::suggest_for_hub( 2 )->term_id, 'each hub gets its own' );

mock_post( 3, [ 'post_type' => 'page', 'post_title' => 'Sardaigne', 'post_name' => 'sardaigne' ] );
MHM_Model::set_hub_type( 3, 'geo' );
is_same( null, MHM_Tags::suggest_for_hub( 3 ), 'a hub with no matching tag suggests nothing, which is not an error' );

// A hub whose slug differs but whose title still names the tag.
mock_post( 4, [ 'post_type' => 'page', 'post_title' => 'Italie', 'post_name' => 'guide-italie-2026' ] );
MHM_Model::set_hub_type( 4, 'geo' );
is_same( 10, MHM_Tags::suggest_for_hub( 4 )->term_id, 'the title is tried when the slug does not match' );

echo "\nListing and classifying tagged posts\n";

mock_post( 20, [ 'post_title' => 'Rome en famille', 'post_name' => 'rome' ] );
mock_post( 21, [ 'post_title' => 'Venise avec des enfants', 'post_name' => 'venise' ] );
mock_post( 22, [ 'post_title' => 'Florence en 3 jours', 'post_name' => 'florence' ] );
mock_post( 23, [ 'post_title' => 'Deja assigne ici', 'post_name' => 'deja' ] );
mock_post( 24, [ 'post_title' => 'Brouillon', 'post_name' => 'brouillon', 'post_status' => 'draft' ] );
mock_post( 25, [ 'post_title' => 'Pas de tag', 'post_name' => 'pas-de-tag' ] );

foreach ( [ 20, 21, 22, 23, 24 ] as $post_id ) {
	mock_tag_post( $post_id, 10 );
}

MHM_Model::set_primary_hub( 22, 2, 'geo' );  // Florence already belongs to Toscane.
MHM_Model::set_primary_hub( 23, 1, 'geo' );  // Already assigned to this hub.

$found = MHM_Tags::candidates( 1, 10, [ 'status' => 'any' ] );

$states = [];
foreach ( $found['rows'] as $row ) {
	$states[ $row['id'] ] = $row['state'];
}

is_same( 5, count( $found['rows'] ), 'every post carrying the tag is listed' );
is_same( false, isset( $states[25] ), 'and nothing else is' );
is_same( MHM_Scanner::UNASSIGNED, $states[20], 'a free post is a candidate' );
is_same( MHM_Scanner::CONFLICT, $states[22], 'a post owned by another hub is a conflict, not a candidate' );
is_same( MHM_Scanner::ALREADY_ASSIGNED_HERE, $states[23], 'one already assigned here is informational' );
is_same( MHM_Scanner::UNASSIGNED, $states[24], 'a draft is listed' );

foreach ( $found['rows'] as $row ) {
	if ( 24 === $row['id'] ) {
		is_same( false, $row['preselect'], 'but never preselected, exactly as in the link scanner' );
	}
	if ( 20 === $row['id'] ) {
		is_same( true, $row['preselect'], 'while a published free post is ticked for you' );
	}
}

is_same( 3, $found['counts'][ MHM_Scanner::UNASSIGNED ], 'the states are counted for the bar under the table' );
is_same( 1, $found['counts'][ MHM_Scanner::CONFLICT ], 'including conflicts' );

is_same( 4, count( MHM_Tags::candidates( 1, 10 )['rows'] ), 'published only is the default' );

echo "\nPaging\n";

$page_one = MHM_Tags::candidates( 1, 10, [ 'status' => 'any', 'per_page' => 2 ] );
is_same( 2, count( $page_one['rows'] ), 'a page holds what it was asked for' );
is_same( true, $page_one['has_more'], 'and knows there is more without counting the tag' );

$page_three = MHM_Tags::candidates( 1, 10, [ 'status' => 'any', 'per_page' => 2, 'paged' => 3 ] );
is_same( 1, count( $page_three['rows'] ), 'the last page holds the remainder' );
is_same( false, $page_three['has_more'], 'and says so' );

echo "\nCross-language and refusals\n";

mock_enable_polylang();
$GLOBALS['MOCK_LANG'][1]  = 'fr';
$GLOBALS['MOCK_LANG'][26] = 'en';

mock_post( 26, [ 'post_title' => 'English post', 'post_name' => 'english', 'lang' => 'en' ] );
mock_tag_post( 26, 10 );

$multilingual = MHM_Tags::candidates( 1, 10, [ 'status' => 'any' ] );
$cross        = null;
foreach ( $multilingual['rows'] as $row ) {
	if ( 26 === $row['id'] ) {
		$cross = $row;
	}
}

is_same( MHM_Scanner::CROSS_LANGUAGE, $cross['state'] ?? '', 'a post in another language is shown as a diagnostic' );
is_same( false, $cross['preselect'] ?? true, 'and never preselected' );

mock_enable_polylang( false );

ok( is_wp_error( MHM_Tags::candidates( 20, 10 ) ), 'a post that is not a hub cannot gather children' );
ok( is_wp_error( MHM_Tags::candidates( 1, 999 ) ), 'a tag that does not exist is an error, not an empty list' );

echo "\nSearching for a tag\n";

is_same( 1, count( MHM_Tags::search( 'tosc' ) ), 'the picker finds a tag by a fragment of its name' );
is_same( 10, MHM_Tags::search( 'ital' )[0]->term_id, 'and returns the term itself' );

finish();
