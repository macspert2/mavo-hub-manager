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

foreach ( [ 20, 21, 22, 23, 24 ] as $child ) {
	MHM_Model::set_primary_hub( $child, 3, 'geo' );
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

/* ------------------------------------------------------------- views meta */

update_post_meta( 20, 'views', '4200' );

is_same( 4200, MHM_Audit::get_views( 20 ), 'the view counter is read from the views meta key' );
is_same( null, MHM_Audit::get_views( 21 ), 'a post with no counter reports null, not zero' );
is_same( 'views', MHM_Audit::views_meta_key(), 'the default ordering key is "views"' );

/* -------------------------------------------------------------- hub health */

$health = MHM_Audit::hub_health( [ 'stale' => true ] );

is_same( 3, $health['summary']['hubs'], 'every marked hub appears in the health report' );
is_same( 3, $health['summary']['geo'], 'all three are geographic' );
is_same( 1, $health['summary']['top_level'], 'only France has no parent hub' );
is_same( 0, $health['summary']['with_issues'], 'a clean hierarchy reports no problems' );

$by_hub = [];
foreach ( $health['rows'] as $row ) {
	$by_hub[ $row['hub'] ] = $row;
}

is_same( 5, $by_hub[3]['children'], 'direct children are counted from the children\'s own meta' );
is_same( 2, $by_hub[3]['depth'], 'Paris en famille sits two levels below France' );
is_same( 1, $by_hub[2]['parent'], 'Paris keeps France as its parent hub' );
is_same( null, $by_hub[1]['parent'], 'France is top-level' );

// "Paris en famille" has five assigned children but its own content links to none
// of them: every relationship is stale from the hub's point of view.
is_same( 5, $by_hub[3]['stale'], 'children the hub no longer links to are counted as stale' );
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
