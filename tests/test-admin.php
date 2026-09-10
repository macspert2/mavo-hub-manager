<?php
/** The admin router and the page renderer. */
require __DIR__ . '/admin-harness.php';

mock_enable_polylang( true );

mock_post( 1, [ 'post_title' => 'France', 'post_type' => 'page', 'post_name' => 'france', 'lang' => 'fr' ] );
mock_post( 2, [ 'post_title' => 'Paris en famille', 'post_type' => 'page', 'post_name' => 'paris-en-famille', 'lang' => 'fr' ] );
mock_post( 3, [ 'post_title' => 'Le Louvre', 'post_name' => 'le-louvre', 'lang' => 'fr' ] );
mock_post( 4, [ 'post_title' => 'Tour Eiffel', 'post_name' => 'tour-eiffel', 'lang' => 'fr' ] );
mock_post( 5, [ 'post_title' => 'Autre hub', 'post_type' => 'page', 'post_name' => 'autre-hub', 'lang' => 'fr' ] );
mock_post( 6, [ 'post_title' => 'Paris with kids', 'post_name' => 'paris-with-kids', 'lang' => 'en' ] );

$GLOBALS['MOCK_POSTS'][2]->post_content =
	'<a href="/2024/05/le-louvre/">Louvre</a>' .
	'<a href="/2024/05/tour-eiffel/">Eiffel</a>' .
	'<a href="/2024/05/paris-with-kids/">EN</a>';

echo "Marking hubs\n";

post_task( [ 'task' => 'mark_hub', 'post_id' => 2, 'hub_type' => 'geo' ] );
is_same( 'geo', MHM_Model::get_hub_type( 2 ), 'the mark_hub task marks a hub' );
ok( str_contains( implode( ' ', queued_notices() ), 'is now a Geographic hub' ), 'a success notice is queued' );
render_page(); // Drains the notices.

post_task( [ 'task' => 'mark_hub', 'post_id' => 1, 'hub_type' => 'geo' ] );
post_task( [ 'task' => 'mark_hub', 'post_id' => 5, 'hub_type' => 'geo' ] );
MHM_Model::set_primary_hub( 4, 5, 'geo' ); // Tour Eiffel already belongs to another hub.
render_page();

echo "\nBatch assignment from the scanner\n";

$redirect = post_task( [ 'task' => 'assign_children', 'hub' => 2, 'targets' => [ 3, 4, 6 ] ] );

is_same( 2, MHM_Model::get_primary_hub( 3, 'geo' ), 'the unassigned target was assigned' );
is_same( 5, MHM_Model::get_primary_hub( 4, 'geo' ), 'the conflicting target was left alone' );
is_same( null, MHM_Model::get_primary_hub( 6, 'geo' ), 'the cross-language target was left alone' );
ok( str_contains( $redirect, 'scan=1' ), 'the redirect returns to the scan view' );

$notices = implode( ' | ', queued_notices() );
ok( str_contains( $notices, '1 post assigned' ), 'the summary counts the assignment' );
ok( str_contains( $notices, '1 conflict was left unchanged' ), 'the summary counts the conflict' );
ok( str_contains( $notices, '1 cross-language link was ignored' ), 'the summary counts the cross-language link' );
render_page();

echo "\nDestructive actions need confirmation\n";

post_task( [ 'task' => 'unmark_hub', 'post_id' => 2 ] );
is_same( 'geo', MHM_Model::get_hub_type( 2 ), 'unmarking a hub with children does nothing yet' );
$pending = get_transient( 'mhm_pending_1' );
ok( is_array( $pending ) && 'unmark_hub' === $pending['fields']['task'], 'a confirmation is parked instead' );

$html = render_page( [ 'page' => 'mavo-hub-manager', 'hub' => 2 ] );
ok( str_contains( $html, 'Confirmation required' ), 'the page renders the confirmation prompt' );
ok( str_contains( $html, 'name="mhm_confirm" value="1"' ), 'the confirmation form carries the confirm flag' );

post_task( [ 'task' => 'unmark_hub', 'post_id' => 2, 'mhm_confirm' => 1 ] );
is_same( null, MHM_Model::get_hub_type( 2 ), 'the confirmed unmark went through' );
is_same( null, MHM_Model::get_primary_hub( 3, 'geo' ), 'its child relationship was cleaned up' );
render_page();

echo "\nMoving a conflicting child\n";

post_task( [ 'task' => 'mark_hub', 'post_id' => 2, 'hub_type' => 'geo' ] );
render_page();

post_task( [ 'task' => 'move_child', 'hub' => 2, 'child' => 4 ] );
is_same( 5, MHM_Model::get_primary_hub( 4, 'geo' ), 'a move without confirmation is refused' );

post_task( [ 'task' => 'move_child', 'hub' => 2, 'child' => 4, 'mhm_confirm' => 1 ] );
is_same( 2, MHM_Model::get_primary_hub( 4, 'geo' ), 'the confirmed move went through' );
render_page();

echo "\nManual assignment and removal\n";

post_task( [ 'task' => 'add_child', 'hub' => 2, 'child' => 3 ] );
is_same( 2, MHM_Model::get_primary_hub( 3, 'geo' ), 'a manual assignment into an empty slot is immediate' );

post_task( [ 'task' => 'add_child', 'hub' => 1, 'child' => 3 ] );
is_same( 2, MHM_Model::get_primary_hub( 3, 'geo' ), 'a manual assignment over an existing hub waits for confirmation' );
post_task( [ 'task' => 'add_child', 'hub' => 1, 'child' => 3, 'mhm_confirm' => 1 ] );
is_same( 1, MHM_Model::get_primary_hub( 3, 'geo' ), 'the confirmed manual move went through' );

post_task( [ 'task' => 'remove_child', 'hub' => 1, 'child' => 3, 'type' => 'geo' ] );
is_same( 1, MHM_Model::get_primary_hub( 3, 'geo' ), 'removal without confirmation is refused' );
post_task( [ 'task' => 'remove_child', 'hub' => 1, 'child' => 3, 'type' => 'geo', 'mhm_confirm' => 1 ] );
is_same( null, MHM_Model::get_primary_hub( 3, 'geo' ), 'the confirmed removal went through' );
render_page();

echo "\nPage rendering\n";

$html = render_page( [ 'page' => 'mavo-hub-manager', 'hub' => 2, 'scan' => 1 ] );

ok( str_contains( $html, 'Hub registry' ), 'the registry section renders' );
ok( str_contains( $html, 'Find a post or page to mark as a hub' ), 'the mark search renders' );
ok( str_contains( $html, 'Internal-link scanner' ), 'the scanner section renders' );
ok( str_contains( $html, 'Assign selected linked posts' ), 'the batch assign button renders' );
ok( str_contains( $html, 'Assigned but no longer linked' ), 'the reverse groups render' );
ok( str_contains( $html, 'Add child manually' ), 'the manual editor renders' );
ok( str_contains( $html, 'Run relationship diagnostics' ), 'the diagnostics button renders' );
ok( str_contains( $html, 'Cross-language' ), 'the cross-language state is shown' );

// Every row-action button must have the form it points at.
preg_match_all( '/<button[^>]+form="([^"]+)"/', $html, $buttons );
$missing = array_filter(
	array_unique( $buttons[1] ),
	static fn( $id ) => ! str_contains( $html, 'id="' . $id . '"' )
);
is_same( [], array_values( $missing ), 'every deferred row form was printed' );
ok( ! preg_match( '/<form\b(?:(?!<\/form>).)*<form\b/s', $html ), 'no form is nested inside another form' );

$html = render_page( [ 'page' => 'mavo-hub-manager' ] );
ok( str_contains( $html, 'Select a hub above' ), 'with no hub selected the detail area is a hint' );
ok( ! str_contains( $html, 'Internal-link scanner' ), 'the scanner is hidden until a hub is selected' );

// Opening the page never writes.
$before = $GLOBALS['MOCK_META'];
render_page( [ 'page' => 'mavo-hub-manager', 'hub' => 2, 'scan' => 1 ] );
is_same( $before, $GLOBALS['MOCK_META'], 'rendering the page writes no relationship' );

finish();
