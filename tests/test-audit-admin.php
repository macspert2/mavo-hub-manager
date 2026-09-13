<?php
/**
 * Tools → Hub Audit rendering.
 *
 * A smoke test: every tab must render without touching a relationship. The
 * stub WP_Query does not emulate OR groups or offsets, so the row filtering
 * itself is covered by test-audit.php, not here.
 */
require __DIR__ . '/admin-harness.php';

mock_enable_polylang( true );

mock_post( 1, [ 'post_title' => 'France', 'post_type' => 'page', 'post_name' => 'france', 'lang' => 'fr' ] );
mock_post( 2, [ 'post_title' => 'Paris en famille', 'post_type' => 'page', 'post_name' => 'paris-en-famille', 'lang' => 'fr' ] );
mock_post( 3, [ 'post_title' => 'Le Louvre', 'post_name' => 'le-louvre', 'lang' => 'fr' ] );
mock_post( 4, [ 'post_title' => 'Tour Eiffel', 'post_name' => 'tour-eiffel', 'lang' => 'fr' ] );

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );
MHM_Model::set_primary_hub( 2, 1, 'geo' );
MHM_Model::set_primary_hub( 3, 2, 'geo' ); // Le Louvre never links back.
update_post_meta( 3, 'views', 1200 );
update_post_meta( 4, 'views', 800 );  // Tour Eiffel: no hub at all, so it lands in the missing tab.

// Le Louvre points at two posts of its own — neither is its hub, so it stays in
// the link-back report while ranking as a hub candidate.
mock_post( 5, [ 'post_title' => 'Autre article', 'post_name' => 'autre-article', 'lang' => 'fr' ] );
$GLOBALS['MOCK_POSTS'][3]->post_content =
	'<a href="/2024/05/tour-eiffel/">Tour Eiffel</a><a href="/2024/05/autre-article/">Autre</a>';

/** Render one audit tab. */
function render_audit( array $query = [] ): string {
	$_GET = array_merge( [ 'page' => MHM_Audit_Admin::PAGE_SLUG ], $query );

	ob_start();
	MHM_Audit_Admin::render_page();

	return (string) ob_get_clean();
}

echo "Tabs\n";

$before = $GLOBALS['MOCK_META'];

$missing = render_audit( [ 'tab' => 'missing' ] );
ok( str_contains( $missing, 'Posts without a hub' ), 'the missing-hub tab renders' );
ok( str_contains( $missing, 'Views' ), 'it shows the view counter column' );
ok( str_contains( $missing, 'nav-tab-active' ), 'the active tab is marked' );

$candidates = render_audit( [ 'tab' => 'candidates' ] );
ok( str_contains( $candidates, 'Hub candidates' ), 'the candidates tab renders' );
ok( str_contains( $candidates, 'Nothing scanned yet' ), 'and scans nothing until asked' );
ok( ! str_contains( $candidates, 'Internal links' ), 'so there is no leaderboard yet' );

$linkback = render_audit( [ 'tab' => 'linkback' ] );
ok( str_contains( $linkback, 'no link back to their hub' ), 'the link-back tab renders' );
ok( str_contains( $linkback, 'Le Louvre' ), 'a child that never links back is listed' );
ok(
	str_contains( $linkback, 'mavo_hub_strip text="{geo:Paris en famille}"' ),
	'the suggested shortcode names the hub type, so it follows the stored relationship'
);

$health = render_audit( [ 'tab' => 'health' ] );
ok( str_contains( $health, 'Hub health' ), 'the health tab renders' );
ok( str_contains( $health, 'top-level' ), 'a hub with no parent is marked top-level' );

$errors = render_audit( [ 'tab' => 'errors' ] );
ok( str_contains( $errors, 'Run relationship diagnostics' ), 'the errors tab offers the on-demand run' );
ok( ! str_contains( $errors, 'stored relationship(s) inspected' ), 'diagnostics do not run until asked' );

$ran = render_audit( [ 'tab' => 'errors', 'run' => 1 ] );
ok( str_contains( $ran, 'stored relationship(s) inspected' ), 'the run parameter runs the diagnostics' );

is_same( $before, $GLOBALS['MOCK_META'], 'rendering an audit tab writes nothing' );

echo "\nScanning for hub candidates\n";

$_GET  = [];
$_POST = [];

try {
	$_POST = [ 'task' => 'scan_candidates', 'tab' => 'candidates', 'status' => 'any' ];
	MHM_Audit_Admin::handle_post();
} catch ( MHM_Redirect $redirect ) {
	// Expected: the scan is a POST so a reload never re-runs it.
}

ok( str_contains( $GLOBALS['MOCK_REDIRECT'], 'tab=candidates' ), 'the scan returns to its own tab' );
ok( str_contains( implode( ' ', queued_notices() ), 'Scanned' ), 'the scan reports its progress' );

$state = get_transient( 'mhm_candidates_1' );
ok( is_array( $state ) && ! empty( $state['counts'] ), 'the tally is cached, not written to post meta' );
is_same( [], array_intersect( array_keys( $state['counts'] ), [ 1, 2 ] ), 'no hub is offered as a candidate' );

$scanned = render_audit( [ 'tab' => 'candidates', 'status' => 'any' ] );
ok( str_contains( $scanned, 'Internal links' ), 'the leaderboard renders after a scan' );
ok( str_contains( $scanned, 'Le Louvre' ), 'and lists the pages that link out the most' );
ok( str_contains( $scanned, 'name="hub_type" value="geo"' ), 'each row can be marked as a hub in one click' );

// A different filter must not read the tally built for another one.
$other = render_audit( [ 'tab' => 'candidates', 'status' => 'draft' ] );
ok( str_contains( $other, 'have not been scanned yet' ), 'changing a filter starts a new scan' );

$before = $GLOBALS['MOCK_META'];
render_audit( [ 'tab' => 'candidates', 'status' => 'any' ] );
is_same( $before, $GLOBALS['MOCK_META'], 'rendering the leaderboard writes no meta' );

echo "\nRemoving one broken relationship\n";

$_GET = [];
try {
	$_POST = [ 'child' => 3, 'type' => 'geo', 'tab' => 'errors', 'run' => '1' ];
	MHM_Audit_Admin::handle_post();
} catch ( MHM_Redirect $redirect ) {
	// Expected: every mutation redirects.
}

is_same( null, MHM_Model::get_primary_hub( 3, 'geo' ), 'the relationship was removed' );
ok( str_contains( $GLOBALS['MOCK_REDIRECT'], 'tab=errors' ), 'the redirect returns to the same tab' );
ok( str_contains( $GLOBALS['MOCK_REDIRECT'], 'run=1' ), 'and keeps the diagnostics running' );
ok( str_contains( implode( ' ', queued_notices() ), 'Removed the Geographic primary hub' ), 'a notice reports the removal' );

finish();
