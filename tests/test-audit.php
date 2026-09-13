<?php
/**
 * Audit reports.
 *
 * The link-back logic and the hub-health report are exercised here. The two
 * paginated queries (missing_hub(), no_link_back()) build WP_Meta_Query
 * structures — OR groups, named ordering clauses, offsets — that the stub
 * WP_Query in harness.php deliberately does not emulate, so they are exercised
 * against a real WordPress install rather than here.
 */

require_once __DIR__ . '/harness.php';

/* ---------------------------------------------------- link keys (no queries) */

reset_store();

mock_post( 10, [ 'post_type' => 'page', 'post_title' => 'France', 'post_name' => 'france' ] );
mock_post( 11, [ 'post_type' => 'page', 'post_title' => 'Paris', 'post_name' => 'paris' ] );
mock_post( 12, [ 'post_type' => 'page', 'post_title' => 'London with kids', 'post_name' => 'london-with-kids' ] );

/** Do these link keys point at this post? */
function points_at( array $keys, int $post_id ): bool {
	return (bool) array_intersect( MHM_Audit::post_keys( $post_id ), $keys );
}

ok( points_at( MHM_Audit::url_keys( 'https://www.mamanvoyage.com/france/' ), 10 ), 'an absolute internal URL matches the page' );
ok( points_at( MHM_Audit::url_keys( '/france/' ), 10 ), 'a relative URL matches the page' );
ok( points_at( MHM_Audit::url_keys( '/france' ), 10 ), 'a missing trailing slash still matches' );
ok( points_at( MHM_Audit::url_keys( '/france/#carte' ), 10 ), 'a fragment is ignored' );
ok( points_at( MHM_Audit::url_keys( '/2024/05/france/' ), 10 ), 'a dated permalink matches by its last segment' );
ok( points_at( MHM_Audit::url_keys( '/?p=10' ), 10 ), 'a ?p= link matches by ID' );
ok( ! points_at( MHM_Audit::url_keys( '/paris/' ), 10 ), 'another page does not match' );
is_same( [], MHM_Audit::url_keys( 'https://example.com/france/' ), 'an external URL yields no keys' );
is_same( [], MHM_Audit::url_keys( '#anchor' ), 'a fragment-only link yields no keys' );
is_same( [], MHM_Audit::url_keys( 'mailto:hello@example.com' ), 'a mailto link yields no keys' );

/* ------------------------------------------------- shortcode link targets */

ok(
	points_at( MHM_Audit::shortcode_link_keys( '<p>Texte.</p>[mavo_hub_strip slug="france" text="Retrouvez notre {guide France}."]' ), 10 ),
	'mavo_hub_strip slug resolves to the hub page'
);

ok(
	points_at( MHM_Audit::shortcode_link_keys( "[mavo_hub_strip slug='/france/' text='{France}']" ), 10 ),
	'a slug with slashes and single quotes resolves the same way'
);

ok(
	points_at( MHM_Audit::shortcode_link_keys( '[mavo_hub_strip slug="en/london-with-kids" text="{London}"]' ), 12 ),
	'a multi-segment slug resolves by its last path segment'
);

ok(
	points_at( MHM_Audit::shortcode_link_keys( '[mavo_hub_strip slug="https://www.mamanvoyage.com/france/" text="{France}"]' ), 10 ),
	'a full internal URL in the slug attribute resolves'
);

is_same(
	[],
	MHM_Audit::shortcode_link_keys( '[mavo_hub_strip slug="https://example.com/france/" text="{France}"]' ),
	'an external URL in the slug attribute is ignored'
);

is_same(
	[],
	MHM_Audit::shortcode_link_keys( '[mavo_hub_strip_extra slug="france" text="{France}"]' ),
	'a different shortcode tag is not mistaken for the link-back one'
);

is_same( [], MHM_Audit::shortcode_link_keys( 'No shortcode here.' ), 'plain content yields no shortcode targets' );

/* ------------------------------------------ which hubs a slugless strip means */

is_same( [ 'geo', 'theme' ], MHM_Audit::shortcode_hub_types( [] ), 'a bare strip means both primary hubs' );
is_same( [ 'geo', 'theme' ], MHM_Audit::shortcode_hub_types( [ 'hub' => 'both' ] ), 'hub="both" means both' );
is_same( [ 'geo' ], MHM_Audit::shortcode_hub_types( [ 'hub' => 'geo' ] ), 'hub="geo" means the geographic hub only' );
is_same( [ 'theme' ], MHM_Audit::shortcode_hub_types( [ 'hub' => 'Theme' ] ), 'the hub attribute is case-insensitive' );
is_same( [], MHM_Audit::shortcode_hub_types( [ 'hub' => 'nonsense' ] ), 'an unknown hub attribute points at nothing' );

is_same(
	[ 'geo' ],
	MHM_Audit::shortcode_hub_types( [ 'text' => 'Voir aussi notre {geo:guide France}.' ] ),
	'in sentence mode the markers decide, not the hub attribute'
);
is_same(
	[ 'geo', 'theme' ],
	MHM_Audit::shortcode_hub_types( [ 'text' => 'Voir {geo:la France} et {theme:nos city trips}.' ] ),
	'both markers in one sentence are found'
);
is_same(
	[ 'theme' ],
	MHM_Audit::shortcode_hub_types( [ 'hub' => 'geo', 'text' => 'Voir {theme:nos city trips}.' ] ),
	'a text attribute overrides the hub attribute'
);
is_same(
	[],
	MHM_Audit::shortcode_hub_types( [ 'text' => 'Voir aussi {Paris : la ville}.' ] ),
	'an unnamed marker names no hub type'
);

/* ----------------------------------------------------------- link back to hub */

reset_store();
MHM_Audit::flush_caches();

// France ← Paris ← Paris en famille ← articles.
mock_post( 1, [ 'post_type' => 'page', 'post_title' => 'France', 'post_name' => 'france' ] );
mock_post( 2, [ 'post_type' => 'page', 'post_title' => 'Paris', 'post_name' => 'paris' ] );
mock_post( 3, [ 'post_type' => 'page', 'post_title' => 'Paris en famille', 'post_name' => 'paris-en-famille' ] );

MHM_Model::set_hub_type( 1, 'geo' );
MHM_Model::set_hub_type( 2, 'geo' );
MHM_Model::set_hub_type( 3, 'geo' );
MHM_Model::set_primary_hub( 2, 1, 'geo' );
MHM_Model::set_primary_hub( 3, 2, 'geo' );

mock_post( 20, [
	'post_title'   => 'Anchor child',
	'post_name'    => 'anchor-child',
	'post_content' => '<p>Voir <a href="https://www.mamanvoyage.com/paris-en-famille/">notre guide</a>.</p>',
] );
mock_post( 21, [
	'post_title'   => 'Shortcode child',
	'post_name'    => 'shortcode-child',
	'post_content' => '<p>Texte.</p>[mavo_hub_strip slug="paris-en-famille" text="Voir aussi {Paris en famille}."]',
] );
mock_post( 22, [
	'post_title'   => 'Ancestor child',
	'post_name'    => 'ancestor-child',
	'post_content' => '<p>Voir <a href="/france/">la France</a>.</p>',
] );
mock_post( 23, [
	'post_title'   => 'Orphan child',
	'post_name'    => 'orphan-child',
	'post_content' => '<p>Aucun lien interne. <a href="https://example.com/">Externe</a>.</p>',
] );
mock_post( 24, [
	'post_title'   => 'Relative child',
	'post_name'    => 'relative-child',
	'post_content' => '<p><a href="/paris-en-famille/#activites">Ancre</a></p>',
] );

// A thematic hub alongside the geographic one, so a child can have both.
mock_post( 4, [ 'post_type' => 'page', 'post_title' => 'City trips', 'post_name' => 'city-trips' ] );
MHM_Model::set_hub_type( 4, 'theme' );

mock_post( 25, [
	'post_title'   => 'Slugless child',
	'post_name'    => 'slugless-child',
	'post_content' => '<p>Texte.</p>[mavo_hub_strip]',
] );
mock_post( 26, [
	'post_title'   => 'Geo-only strip child',
	'post_name'    => 'geo-only-strip-child',
	'post_content' => '[mavo_hub_strip hub="geo"]',
] );
mock_post( 27, [
	'post_title'   => 'Marker child',
	'post_name'    => 'marker-child',
	'post_content' => '[mavo_hub_strip text="Voir {geo:Paris en famille} et {theme:nos city trips}."]',
] );
mock_post( 28, [
	'post_title'   => 'Theme-only strip child',
	'post_name'    => 'theme-only-strip-child',
	'post_content' => '[mavo_hub_strip hub="theme"]',
] );
mock_post( 29, [
	'post_title'   => 'Geo related child',
	'post_name'    => 'geo-related-child',
	'post_content' => "<p>Du texte.</p>\n\n[geo_related]\n\nEncore du texte.",
] );
mock_post( 30, [
	'post_title'   => 'Plain child',
	'post_name'    => 'plain-child',
	'post_content' => '<p>Du texte, et aucun shortcode.</p>',
] );
mock_post( 31, [
	'post_title'   => 'Geo related with level',
	'post_name'    => 'geo-related-level-child',
	'post_content' => '[geo_related level="city" limit="6"]',
] );

foreach ( [ 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31 ] as $child ) {
	MHM_Model::set_primary_hub( $child, 3, 'geo' );
}

foreach ( [ 25, 26, 27, 28, 29, 30, 31 ] as $child ) {
	MHM_Model::set_primary_hub( $child, 4, 'theme' );
}

$anchor = MHM_Audit::link_back_status( 20, 3, 'geo' );
is_same( true, $anchor['linked'], 'an absolute anchor to the hub counts as a link back' );
is_same( MHM_Audit::LINK_ANCHOR, $anchor['via'], 'the anchor route is reported' );

$shortcode = MHM_Audit::link_back_status( 21, 3, 'geo' );
is_same( true, $shortcode['linked'], 'a mavo_hub_strip pointing at the hub counts as a link back' );
is_same( MHM_Audit::LINK_SHORTCODE, $shortcode['via'], 'the shortcode route is reported' );

$ancestor = MHM_Audit::link_back_status( 22, 3, 'geo' );
is_same( false, $ancestor['linked'], 'a link to an ancestor is not a link back to the hub itself' );
is_same( MHM_Audit::LINK_ANCESTOR, $ancestor['via'], 'the ancestor case is reported separately' );
is_same( 1, $ancestor['ancestor'], 'the ancestor that was linked is named' );

$none = MHM_Audit::link_back_status( 23, 3, 'geo' );
is_same( false, $none['linked'], 'a post with no internal link has no link back' );
is_same( MHM_Audit::LINK_NONE, $none['via'], 'nothing links back' );

$relative = MHM_Audit::link_back_status( 24, 3, 'geo' );
is_same( true, $relative['linked'], 'a relative link with a fragment still resolves to the hub' );

// mavo_post_links_back_to_hub() delegates to link_back_status(); the bootstrap
// that defines it is not loaded by this stub harness.
is_same( null, MHM_Model::get_primary_hub( 23, 'theme' ), 'a post with no thematic hub has nothing to link back to' );

// The hub itself linking to a child is not a link back.
is_same(
	false,
	MHM_Audit::link_back_status( 3, 20, 'geo' )['linked'],
	'the report looks at the child\'s content, not the hub\'s'
);

/* --------------------------------- a slugless strip resolves the post's hubs */

$slugless = MHM_Audit::link_back_status( 25, 3, 'geo' );
is_same( true, $slugless['linked'], 'a bare [mavo_hub_strip] counts as a link back to the geographic hub' );
is_same( MHM_Audit::LINK_SHORTCODE, $slugless['via'], 'the slugless strip is reported as a shortcode link' );

is_same(
	true,
	MHM_Audit::link_back_status( 25, 4, 'theme' )['linked'],
	'the same bare strip also links back to the thematic hub'
);

is_same(
	true,
	MHM_Audit::link_back_status( 26, 3, 'geo' )['linked'],
	'hub="geo" links back to the geographic hub'
);
is_same(
	false,
	MHM_Audit::link_back_status( 26, 4, 'theme' )['linked'],
	'hub="geo" does not claim a link back to the thematic hub'
);

is_same(
	true,
	MHM_Audit::link_back_status( 27, 3, 'geo' )['linked'],
	'a {geo:...} marker links back to the geographic hub'
);
is_same(
	true,
	MHM_Audit::link_back_status( 27, 4, 'theme' )['linked'],
	'a {theme:...} marker links back to the thematic hub'
);

is_same(
	false,
	MHM_Audit::link_back_status( 28, 3, 'geo' )['linked'],
	'hub="theme" leaves the geographic hub unlinked'
);
is_same(
	MHM_Audit::LINK_NONE,
	MHM_Audit::link_back_status( 28, 3, 'geo' )['via'],
	'the thematic-only strip is not mistaken for an ancestor link either'
);

// Without a post ID there is nothing to resolve the implicit target against.
is_same(
	[],
	MHM_Audit::shortcode_link_keys( '[mavo_hub_strip]' ),
	'a slugless strip yields no keys when no post is given'
);

/* ------------------------- [geo_related] leads with the post's own hub cards */

$related = MHM_Audit::link_back_status( 29, 3, 'geo' );
is_same( true, $related['linked'], '[geo_related] counts as a link back to the geographic hub' );
is_same( MHM_Audit::LINK_SHORTCODE, $related['via'], '[geo_related] is reported as a shortcode link' );

is_same(
	true,
	MHM_Audit::link_back_status( 29, 4, 'theme' )['linked'],
	'[geo_related] links back to the thematic hub as well'
);

is_same(
	true,
	MHM_Audit::link_back_status( 31, 3, 'geo' )['linked'],
	'its own level/limit attributes do not change which hubs it points at'
);

is_same(
	[ 'id:3', 'id:4' ],
	MHM_Audit::shortcode_link_keys( '[geo_related]', 29 ),
	'[geo_related] resolves to both of the post\'s primary hubs'
);

is_same(
	[],
	MHM_Audit::shortcode_link_keys( '[geo_related]' ),
	'and to nothing at all without a post to resolve against'
);

// The tag must not swallow its own alias, or a longer neighbour.
is_same(
	[],
	MHM_Audit::shortcode_link_keys( '[geo_related_elsewhere]', 29 ),
	'a longer tag starting with geo_related is not matched'
);

/* ------------------------------------------------------------- views meta */

update_post_meta( 20, 'views', '4200' );

is_same( 4200, MHM_Audit::get_views( 20 ), 'the view counter is read from the views meta key' );
is_same( null, MHM_Audit::get_views( 21 ), 'a post with no counter reports null, not zero' );
is_same( 'views', MHM_Audit::views_meta_key(), 'the default ordering key is "views"' );

/* -------------------------------------------------------------- hub health */

$health = MHM_Audit::hub_health( [ 'stale' => true ] );

is_same( 4, $health['summary']['hubs'], 'every marked hub appears in the health report' );
is_same( 3, $health['summary']['geo'], 'three of the four are geographic' );
is_same( 2, $health['summary']['top_level'], 'France and City trips have no parent hub' );
is_same( 0, $health['summary']['with_issues'], 'a clean hierarchy reports no problems' );

$by_hub = [];
foreach ( $health['rows'] as $row ) {
	$by_hub[ $row['hub'] ] = $row;
}

is_same( 12, $by_hub[3]['children'], 'direct children are counted from the children\'s own meta' );
is_same( 2, $by_hub[3]['depth'], 'Paris en famille sits two levels below France' );
is_same( 1, $by_hub[2]['parent'], 'Paris keeps France as its parent hub' );
is_same( null, $by_hub[1]['parent'], 'France is top-level' );

// "Paris en famille" has five assigned children but its own content links to none
// of them: every relationship is stale from the hub's point of view.
is_same( 12, $by_hub[3]['stale'], 'children the hub no longer links to are counted as stale' );
is_same( 0, $by_hub[3]['unassigned'], 'the hub links to nothing that is unassigned' );

// A broken hierarchy surfaces as a problem on the hub row.
update_post_meta( 1, MHM_Model::META_GEO_HUB, 999 );
$broken = MHM_Audit::hub_health();
$issues = 0;
foreach ( $broken['rows'] as $row ) {
	if ( 1 === $row['hub'] ) {
		$issues = count( $row['issues'] );
	}
}
ok( $issues > 0, 'a primary hub pointing at a missing object is reported on the hub row' );
is_same( 1, $broken['summary']['with_issues'], 'the summary counts hubs with problems' );

/* --------------------------------------------------------- hub candidates */

reset_store();
MHM_Audit::flush_caches();

mock_post( 40, [ 'post_type' => 'page', 'post_title' => 'Index page', 'post_name' => 'index-page' ] );
mock_post( 41, [ 'post_title' => 'Un article', 'post_name' => 'un-article' ] );
mock_post( 42, [ 'post_title' => 'Deux', 'post_name' => 'deux' ] );
mock_post( 43, [ 'post_title' => 'Trois', 'post_name' => 'trois' ] );
mock_post( 44, [ 'post_type' => 'page', 'post_title' => 'Deja hub', 'post_name' => 'deja-hub' ] );

$GLOBALS['MOCK_POSTS'][40]->post_content =
	'<a href="/un-article/">Un</a>' .
	'<a href="https://www.mamanvoyage.com/deux/">Deux</a>' .
	'<a href="/2024/05/trois/">Trois</a>' .
	'<a href="/deux/#plus-bas">Deux encore</a>' .   // Same target twice.
	'<a href="/index-page/">Soi-même</a>' .          // Self-link.
	'<a href="https://example.com/">Externe</a>' .   // External.
	'<a href="#ancre">Ancre</a>' .                   // Fragment only.
	'<a href="mailto:a@b.c">Mail</a>';               // Not a link to a page.

$GLOBALS['MOCK_POSTS'][41]->post_content = '<a href="/deux/">Deux</a>';
$GLOBALS['MOCK_POSTS'][42]->post_content = '<p>Aucun lien.</p>';
$GLOBALS['MOCK_POSTS'][44]->post_content = '<a href="/deux/">Deux</a><a href="/trois/">Trois</a>';

MHM_Model::set_hub_type( 44, 'geo' );

is_same( 3, MHM_Audit::count_internal_links( 40 ), 'distinct internal targets are counted once each' );
is_same( 1, MHM_Audit::count_internal_links( 41 ), 'one link is one candidate point' );
is_same( 0, MHM_Audit::count_internal_links( 42 ), 'a post with no internal link scores zero' );

$state = MHM_Audit::scan_candidates( [ 'status' => 'any' ] );

is_same( 4, $state['scanned'], 'a batch larger than the site scans everything' );
is_same( true, $state['done'], 'and reports the scan as complete' );
is_same( false, isset( $state['counts'][44] ), 'a page already marked as a hub is never a candidate' );
is_same( false, isset( $state['counts'][42] ), 'a post with no internal links is left out of the tally' );
is_same( [ 40 => 3, 41 => 1 ], $state['counts'], 'the tally is ordered by link count, descending' );

$page = MHM_Audit::candidates_rows( $state );

is_same( 2, count( $page['rows'] ), 'both candidates are listed' );
is_same( 40, $page['rows'][0]['post'], 'the page with the most internal links ranks first' );
is_same( 1, $page['rows'][0]['rank'], 'ranks start at one' );
is_same( 3, $page['rows'][0]['links'], 'the row carries its link count' );
is_same( false, $page['has_more'], 'one page holds them all' );

// A batch smaller than the site leaves a cursor to continue from.
$first = MHM_Audit::scan_candidates( [ 'status' => 'any', 'batch' => 2 ] );
is_same( 2, $first['scanned'], 'the first pass reads one batch' );
is_same( false, $first['done'], 'and knows there is more to read' );

$second = MHM_Audit::scan_candidates( [ 'status' => 'any', 'batch' => 2 ], $first );
is_same( 4, $second['scanned'], 'the second pass continues where the first stopped' );
is_same( [ 40 => 3, 41 => 1 ], $second['counts'], 'the tally accumulates across passes' );

// A full batch cannot know it was the last one: the end is a short batch. The
// job is never counted up front, because counting posts that lack a meta value
// means an anti-join over the whole table.
is_same( false, $second['done'], 'a full batch does not claim to be the end' );

$third = MHM_Audit::scan_candidates( [ 'status' => 'any', 'batch' => 2 ], $second );
is_same( true, $third['done'], 'the first short batch ends the scan' );
is_same( [ 40 => 3, 41 => 1 ], $third['counts'], 'and changes nothing about the tally' );

// Changing a filter must start a new scan rather than mix two of them.
// Only post 40 is a page, so a narrowed scan restarts and finds just that one.
$switched = MHM_Audit::scan_candidates( [ 'status' => 'any', 'post_type' => 'page', 'batch' => 2 ], $second );
is_same( 1, $switched['scanned'], 'changing a filter starts the scan over rather than continuing it' );
is_same( [ 40 => 3 ], $switched['counts'], 'and tallies only what the new filter matches' );
ok(
	MHM_Audit::candidates_signature( [ 'status' => 'draft' ] ) !== MHM_Audit::candidates_signature( [ 'status' => 'publish' ] ),
	'each set of filters has its own signature'
);

/* ----------------------------------------------------------- hub traffic */

reset_store();
MHM_Audit::flush_caches();

// France ← Paris ← Paris en famille, with articles at two depths.
mock_post( 50, [ 'post_type' => 'page', 'post_title' => 'France', 'post_name' => 'france' ] );
mock_post( 51, [ 'post_type' => 'page', 'post_title' => 'Paris', 'post_name' => 'paris' ] );
mock_post( 52, [ 'post_type' => 'page', 'post_title' => 'Paris en famille', 'post_name' => 'pef' ] );
mock_post( 53, [ 'post_type' => 'page', 'post_title' => 'City trips', 'post_name' => 'city-trips' ] );

foreach ( [ 50, 51, 52 ] as $hub ) {
	MHM_Model::set_hub_type( $hub, 'geo' );
}
MHM_Model::set_hub_type( 53, 'theme' );

MHM_Model::set_primary_hub( 51, 50, 'geo' );
MHM_Model::set_primary_hub( 52, 51, 'geo' );

mock_post( 54, [ 'post_title' => 'Article A', 'post_name' => 'article-a' ] );
mock_post( 55, [ 'post_title' => 'Article B', 'post_name' => 'article-b' ] );
MHM_Model::set_primary_hub( 54, 52, 'geo' );
MHM_Model::set_primary_hub( 55, 51, 'geo' );
MHM_Model::set_primary_hub( 54, 53, 'theme' );

update_post_meta( 50, 'views', 100 );   // France, the hub page itself
update_post_meta( 51, 'views', 200 );   // Paris
update_post_meta( 52, 'views', 40 );    // Paris en famille
update_post_meta( 54, 'views', 1000 );  // Article A
update_post_meta( 55, 'views', 7 );     // Article B

$traffic = MHM_Audit::hub_traffic();
$by_hub  = [];
foreach ( $traffic['rows'] as $row ) {
	$by_hub[ $row['hub'] ] = $row;
}

// France owns Paris, Paris en famille and both articles, at three depths.
is_same( 4, $by_hub[50]['posts'], 'a hub owns everything below it, at any depth' );
is_same( 1, $by_hub[50]['direct'], 'while its direct children are only the first level' );
is_same( 1247, $by_hub[50]['subtree'], 'the traffic below it is the sum of all of them' );
is_same( 100, $by_hub[50]['own'], 'its own page views are kept separate' );
is_same( 1347, $by_hub[50]['total'], 'and added for the total it owns' );

is_same( 3, $by_hub[51]['posts'], 'Paris owns its sub-hub, that sub-hub\'s article, and its own' );
is_same( 1047, $by_hub[51]['subtree'], 'counting through the sub-hub as well as directly' );
is_same( 1000, $by_hub[52]['subtree'], 'the nearest hub owns just its own child' );
is_same( 312, $by_hub[50]['per_child'], 'views per post owned is the subtree average, rounded' );

// The thematic side counts the same article again, deliberately.
is_same( 1000, $by_hub[53]['subtree'], 'a post owned by two hub types is counted under each' );
is_same( 'theme', $by_hub[53]['type'], 'and the row says which hierarchy it belongs to' );

is_same( 4, $traffic['summary']['hubs'], 'every hub gets a row' );
is_same( 0, $traffic['summary']['without_views'], 'each of these hubs owns some traffic' );

// Sorting picks a different winner per question.
is_same( 50, MHM_Audit::hub_traffic( [ 'sort' => 'total' ] )['rows'][0]['hub'], 'most traffic owned puts France first' );
is_same( 50, MHM_Audit::hub_traffic( [ 'sort' => 'children' ] )['rows'][0]['hub'], 'so does most posts owned' );
is_same( 52, MHM_Audit::hub_traffic( [ 'sort' => 'per_child' ] )['rows'][0]['hub'], 'but per post the nearest hub wins' );

is_same( 1, count( MHM_Audit::hub_traffic( [ 'type' => 'theme' ] )['rows'] ), 'the type filter narrows the report' );

// A hub with nothing under it still appears, at zero.
mock_post( 56, [ 'post_type' => 'page', 'post_title' => 'Empty hub', 'post_name' => 'empty-hub' ] );
MHM_Model::set_hub_type( 56, 'geo' );

$with_empty = MHM_Audit::hub_traffic();
$empty_row  = null;
foreach ( $with_empty['rows'] as $row ) {
	if ( 56 === $row['hub'] ) {
		$empty_row = $row;
	}
}
is_same( 0, $empty_row['total'] ?? -1, 'a hub owning nothing reports zero rather than vanishing' );
is_same( 1, $with_empty['summary']['without_views'], 'and is counted as owning no traffic' );

// A cycle in the stored data must not spin the roll-up.
MHM_Model::set_hub_type( 57, 'geo' );
mock_post( 57, [ 'post_type' => 'page', 'post_title' => 'Loop A', 'post_name' => 'loop-a' ] );
mock_post( 58, [ 'post_type' => 'page', 'post_title' => 'Loop B', 'post_name' => 'loop-b' ] );
MHM_Model::set_hub_type( 57, 'geo' );
MHM_Model::set_hub_type( 58, 'geo' );
update_post_meta( 57, MHM_Model::META_GEO_HUB, 58 );
update_post_meta( 58, MHM_Model::META_GEO_HUB, 57 );

$looped = MHM_Audit::hub_traffic();
ok( is_array( $looped['rows'] ), 'a cycle in the stored data is walked safely' );

/* -------------------------------------------------------- post relations */

reset_store();
MHM_Audit::flush_caches();

// France ← Paris ← Paris en famille ← the article, with Lyon beside Paris.
mock_post( 60, [ 'post_type' => 'page', 'post_title' => 'France', 'post_name' => 'france' ] );
mock_post( 61, [ 'post_type' => 'page', 'post_title' => 'Paris', 'post_name' => 'paris' ] );
mock_post( 62, [ 'post_type' => 'page', 'post_title' => 'Paris en famille', 'post_name' => 'paris-en-famille' ] );
mock_post( 63, [ 'post_type' => 'page', 'post_title' => 'Paris insolite', 'post_name' => 'paris-insolite' ] );
mock_post( 65, [ 'post_type' => 'page', 'post_title' => 'Lyon', 'post_name' => 'lyon' ] );
mock_post( 64, [ 'post_type' => 'page', 'post_title' => 'City trips', 'post_name' => 'city-trips' ] );

foreach ( [ 60, 61, 62, 63, 65 ] as $hub ) {
	MHM_Model::set_hub_type( $hub, 'geo' );
}
MHM_Model::set_hub_type( 64, 'theme' );

MHM_Model::set_primary_hub( 61, 60, 'geo' );  // Paris → France
MHM_Model::set_primary_hub( 62, 61, 'geo' );  // Paris en famille → Paris
MHM_Model::set_primary_hub( 63, 61, 'geo' );  // Paris insolite → Paris, beside the hub
MHM_Model::set_primary_hub( 65, 60, 'geo' );  // Lyon → France: further away, not a cousin

// The post itself, four siblings, and two cousins under Paris insolite.
mock_post( 70, [ 'post_title' => 'Le Louvre', 'post_name' => 'le-louvre' ] );
foreach ( [ 71, 72, 73, 74 ] as $sibling ) {
	mock_post( $sibling, [ 'post_title' => 'Sibling ' . $sibling, 'post_name' => 'sibling-' . $sibling ] );
	MHM_Model::set_primary_hub( $sibling, 62, 'geo' );
}
foreach ( [ 80, 81 ] as $cousin ) {
	mock_post( $cousin, [ 'post_title' => 'Cousin ' . $cousin, 'post_name' => 'cousin-' . $cousin ] );
	MHM_Model::set_primary_hub( $cousin, 63, 'geo' );
}

MHM_Model::set_primary_hub( 70, 62, 'geo' );
MHM_Model::set_primary_hub( 70, 64, 'theme' );

$graph = MHM_Audit::relation_graph( 70 );

is_same( 70, $graph['post'], 'the graph is centred on the post asked for' );
is_same( 62, $graph['types']['geo']['hub'], 'its geographic hub is the immediate one' );
is_same( 64, $graph['types']['theme']['hub'], 'and its thematic hub sits on the other side' );
is_same( [ 61, 60 ], $graph['types']['geo']['ancestors'], 'the hub\'s own hubs are listed nearest first' );
is_same( [], $graph['types']['theme']['ancestors'], 'a top-level thematic hub has nothing above it' );
is_same( 4, count( $graph['types']['geo']['siblings'] ), 'the hub\'s other children are the siblings' );
is_same( false, in_array( 70, $graph['types']['geo']['siblings'], true ), 'the post is never its own sibling' );
is_same( false, $graph['types']['geo']['siblings_more'], 'four siblings fit under the default cap' );
is_same( [], $graph['types']['geo']['aunts'], 'cousins are off unless asked for' );

// Every node on screen carries the facts its card shows.
ok( isset( $graph['nodes'][62]['hub_type'] ), 'each node is described once' );
is_same( 'geo', $graph['nodes'][62]['hub_type'], 'a hub node knows its hub type' );
is_same( null, $graph['nodes'][71]['hub_type'], 'an ordinary sibling is not a hub' );
is_same( 62, $graph['nodes'][71]['geo_hub'], 'and carries its own primary hub for the card' );

$with_cousins = MHM_Audit::relation_graph( 70, [ 'cousins' => true ] );

is_same( 1, count( $with_cousins['types']['geo']['aunts'] ), 'only a hub beside this one contributes cousins' );
is_same( 63, $with_cousins['types']['geo']['aunts'][0]['hub'], 'and it is named' );
is_same(
	false,
	in_array( 65, array_column( $with_cousins['types']['geo']['aunts'], 'hub' ), true ),
	'a hub one level further up is not an aunt'
);
is_same( [ 80, 81 ], $with_cousins['types']['geo']['aunts'][0]['children'], 'its children are the cousins' );
ok( isset( $with_cousins['nodes'][80] ), 'cousins are described too' );

// Caps keep the picture from filling up.
$capped = MHM_Audit::relation_graph( 70, [ 'max' => 2 ] );
is_same( 2, count( $capped['types']['geo']['siblings'] ), 'the sibling cap is honoured' );
is_same( true, $capped['types']['geo']['siblings_more'], 'and the view is told there are more' );

// A stored hub of the wrong type is reported rather than drawn.
mock_post( 90, [ 'post_title' => 'Broken child', 'post_name' => 'broken-child' ] );
update_post_meta( 90, MHM_Model::META_GEO_HUB, 64 ); // 64 is a thematic hub.

$broken = MHM_Audit::relation_graph( 90 );
is_same( null, $broken['types']['geo']['hub'], 'a hub of the wrong type is not drawn as the hub' );
is_same( 64, $broken['types']['geo']['broken_hub'], 'but it is reported so the page can say so' );

ok( is_wp_error( MHM_Audit::relation_graph( 999999 ) ), 'a post that does not exist is an error, not an empty graph' );

/* ------------------------------------------------------------- Polylang */

reset_store();
MHM_Audit::flush_caches();
mock_enable_polylang();

mock_post( 30, [ 'post_type' => 'page', 'post_title' => 'France', 'post_name' => 'france', 'lang' => 'fr' ] );
mock_post( 31, [ 'post_title' => 'Article FR', 'post_name' => 'article-fr', 'lang' => 'fr', 'post_content' => '<a href="/france/">France</a>' ] );
mock_post( 32, [ 'post_title' => 'Article EN', 'post_name' => 'article-en', 'lang' => 'en', 'post_content' => '<a href="/france/">France</a>' ] );

MHM_Model::set_hub_type( 30, 'geo' );
MHM_Model::set_primary_hub( 31, 30, 'geo' );
MHM_Model::set_primary_hub( 32, 30, 'geo' );

is_same( true, MHM_Audit::link_back_status( 31, 30, 'geo' )['linked'], 'a same-language link back is found' );
is_same(
	true,
	MHM_Audit::link_back_status( 32, 30, 'geo' )['linked'],
	'a cross-language child that links back is still reported as linked — language is a diagnostic elsewhere'
);

$langs = MHM_Audit::hub_health( [ 'lang' => 'fr' ] );
is_same( 1, $langs['summary']['hubs'], 'the health report honours the language filter' );
is_same( 0, MHM_Audit::hub_health( [ 'lang' => 'de' ] )['summary']['hubs'], 'a language with no hubs reports none' );

finish();
