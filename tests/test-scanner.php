<?php
/** Internal-link extraction, resolution, classification and reverse grouping. */
require __DIR__ . '/harness.php';

mock_enable_polylang( true );

// Hub and targets.
mock_post( 10, [ 'post_title' => 'Paris en famille', 'post_type' => 'page', 'post_name' => 'paris-en-famille', 'lang' => 'fr' ] );
mock_post( 11, [ 'post_title' => 'Paris', 'post_type' => 'page', 'post_name' => 'paris', 'lang' => 'fr' ] );
mock_post( 20, [ 'post_title' => 'Musee du Louvre', 'post_name' => 'musee-du-louvre', 'lang' => 'fr' ] );
mock_post( 21, [ 'post_title' => 'Jardin des plantes', 'post_name' => 'jardin-des-plantes', 'lang' => 'fr' ] );
mock_post( 22, [ 'post_title' => 'Tour Eiffel', 'post_name' => 'tour-eiffel', 'lang' => 'fr' ] );
mock_post( 23, [ 'post_title' => 'Disneyland', 'post_name' => 'disneyland', 'lang' => 'fr' ] );
mock_post( 24, [ 'post_title' => 'Paris with kids', 'post_name' => 'paris-with-kids', 'lang' => 'en' ] );
mock_post( 25, [ 'post_title' => 'Brouillon', 'post_name' => 'brouillon', 'post_status' => 'draft', 'lang' => 'fr' ] );
mock_post( 26, [ 'post_title' => 'Une image', 'post_type' => 'attachment', 'post_name' => 'une-image' ] );
mock_post( 27, [ 'post_title' => 'Cornouailles', 'post_name' => 'cornouailles', 'lang' => 'fr' ] );
mock_post( 28, [ 'post_title' => 'Relative only', 'post_name' => 'relative-only', 'lang' => 'fr' ] );
mock_post( 30, [ 'post_title' => 'Autre hub', 'post_type' => 'page', 'post_name' => 'autre-hub', 'lang' => 'fr' ] );

MHM_Model::set_hub_type( 10, 'geo' );
MHM_Model::set_hub_type( 30, 'geo' );
MHM_Model::set_primary_hub( 20, 10, 'geo' ); // already assigned here
MHM_Model::set_primary_hub( 22, 30, 'geo' ); // conflict

$content = <<<HTML
<p>
  <a href="https://www.mamanvoyage.com/2024/05/musee-du-louvre/">Le Louvre</a>
  <a href="https://mamanvoyage.com/2024/05/jardin-des-plantes/">Jardin</a>
  <a href="/2024/05/jardin-des-plantes/">Jardin encore</a>
  <a href="https://www.mamanvoyage.com/2024/05/tour-eiffel/#horaires">Tour Eiffel</a>
  <a href="https://www.mamanvoyage.com/2024/05/disneyland/?utm_source=x">Disneyland</a>
  <a href="https://www.mamanvoyage.com/2024/05/paris-with-kids/">Paris with kids</a>
  <a href="https://www.mamanvoyage.com/2024/05/brouillon/">Brouillon</a>
  <a href="/une-image/">Une image</a>
  <a href="/paris-en-famille/">Cette page</a>
  <a href="https://booking.com/hotel">Un hotel</a>
  <a href="#sommaire">Sommaire</a>
  <a href="mailto:hello@mamanvoyage.com">Mail</a>
  <a href="tel:+33100000000">Tel</a>
  <a href="javascript:void(0)">JS</a>
</p>
[mavo_link url="https://www.mamanvoyage.com/2020/09/cornouailles/" label="Notre article :"]Une semaine en Cornouailles[/mavo_link]
[mavo_link url="/2024/05/relative-only/"]Lien relatif[/mavo_link]
[mavo_link url="https://booking.com/hotel"]Un hotel[/mavo_link]
HTML;

$GLOBALS['MOCK_POSTS'][10]->post_content = $content;

echo "URL resolution\n";

is_same( 20, MHM_Scanner::resolve_url( 'https://www.mamanvoyage.com/2024/05/musee-du-louvre/' ), 'absolute www URL resolves' );
is_same( 21, MHM_Scanner::resolve_url( 'https://mamanvoyage.com/2024/05/jardin-des-plantes/' ), 'absolute bare-domain URL resolves' );
is_same( 21, MHM_Scanner::resolve_url( '/2024/05/jardin-des-plantes/' ), 'relative URL resolves' );
is_same( 11, MHM_Scanner::resolve_url( '/paris/' ), 'page permalink resolves' );
is_same( 22, MHM_Scanner::resolve_url( 'https://www.mamanvoyage.com/2024/05/tour-eiffel/#horaires' ), 'the fragment is stripped before resolving' );
is_same( 0, MHM_Scanner::resolve_url( 'https://booking.com/hotel' ), 'an external URL is ignored' );
is_same( 0, MHM_Scanner::resolve_url( '#sommaire' ), 'a fragment-only link is ignored' );
is_same( 0, MHM_Scanner::resolve_url( 'mailto:hello@mamanvoyage.com' ), 'mailto is ignored' );
is_same( 0, MHM_Scanner::resolve_url( 'tel:+33100000000' ), 'tel is ignored' );
is_same( 0, MHM_Scanner::resolve_url( 'javascript:void(0)' ), 'javascript is ignored' );
is_same( 0, MHM_Scanner::resolve_url( '/une-image/' ), 'an attachment is ignored' );
is_same( 14, count( MHM_Scanner::extract_links( $content ) ), 'every anchor is extracted' );

echo "\nShortcode URLs\n";

is_same(
	[ 'https://www.mamanvoyage.com/2020/09/cornouailles/', 'https://booking.com/hotel' ],
	MHM_Scanner::extract_shortcode_links( $content ),
	'only full URLs inside shortcodes are extracted, relative values are skipped'
);
is_same(
	[ 'https://www.mamanvoyage.com/france/' ],
	MHM_Scanner::extract_shortcode_links( '[whatever target="https://www.mamanvoyage.com/france/"]' ),
	'any shortcode attribute holding a full URL counts, not only mavo_link'
);
is_same( [], MHM_Scanner::extract_shortcode_links( 'Pas de shortcode ici.' ), 'plain text yields nothing' );
is_same( [], MHM_Scanner::extract_shortcode_links( '[mavo_link url="/france/"]France[/mavo_link]' ), 'a relative shortcode URL is deliberately ignored' );
is_same(
	[ 'https://www.mamanvoyage.com/france/' ],
	MHM_Scanner::extract_shortcode_links( '[mavo_link url=https://www.mamanvoyage.com/france/]France[/mavo_link]' ),
	'an unquoted attribute value works too'
);
is_same( [], MHM_Scanner::extract_shortcode_links( '<a href="https://www.mamanvoyage.com/france/">France</a>' ), 'an anchor is not read as a shortcode' );

echo "\nClassification\n";

$scan  = MHM_Scanner::scan( 10 );
$rows  = [];
foreach ( $scan['rows'] as $row ) { $rows[ $row['id'] ] = $row; }

is_same( 8, count( $scan['rows'] ), 'duplicate targets are deduplicated and non-targets dropped' );
is_same( 14, $scan['anchors'], 'anchors are counted separately' );
is_same( 2, $scan['shortcodes'], 'full URLs inside shortcodes are counted separately' );
is_same( MHM_Scanner::UNASSIGNED, $rows[27]['state'], 'a full URL inside a shortcode becomes a candidate' );
ok( ! isset( $rows[28] ), 'a relative shortcode URL never becomes a candidate' );
is_same( MHM_Scanner::ALREADY_ASSIGNED_HERE, $rows[20]['state'], 'a target already assigned here is informational' );
is_same( MHM_Scanner::UNASSIGNED, $rows[21]['state'], 'a free target is unassigned' );
ok( $rows[21]['preselect'], 'a published unassigned target is preselected' );
is_same( MHM_Scanner::CONFLICT, $rows[22]['state'], 'a target owned by another hub is a conflict' );
is_same( 30, $rows[22]['current_hub']['id'], 'the conflict shows the current primary hub' );
is_same( MHM_Scanner::UNASSIGNED, $rows[23]['state'], 'a query string does not break resolution' );
is_same( MHM_Scanner::CROSS_LANGUAGE, $rows[24]['state'], 'a cross-language link is flagged, not assigned' );
ok( ! $rows[24]['preselect'], 'a cross-language link is never preselected' );
is_same( MHM_Scanner::UNASSIGNED, $rows[25]['state'], 'an unpublished target still appears' );
ok( ! $rows[25]['preselect'], 'an unpublished target is not preselected' );
is_same( MHM_Scanner::SELF, $rows[10]['state'], 'the self-link is marked SELF' );
ok( ! isset( $rows[26] ), 'the attachment never reaches the result rows' );

// Nothing was written by scanning.
is_same( null, MHM_Model::get_primary_hub( 21, 'geo' ), 'scanning writes nothing' );
is_same( 30, MHM_Model::get_primary_hub( 22, 'geo' ), 'scanning never overwrites a conflict' );

echo "\nCycle-invalid target\n";

MHM_Model::set_hub_type( 11, 'geo' );
MHM_Model::set_primary_hub( 10, 11, 'geo' ); // Paris en famille → Paris
$row = MHM_Scanner::classify( 11, 10, 'geo' ); // Paris → Paris en famille would loop
is_same( MHM_Scanner::INVALID_TARGET, $row['state'], 'a link that would create a cycle is invalid, not assignable' );
MHM_Model::remove_primary_hub( 10, 'geo' );

echo "\nReverse diagnostics\n";

// 40 points at the hub but the hub does not link to it.
mock_post( 40, [ 'post_title' => 'Ancien article', 'post_name' => 'ancien-article', 'lang' => 'fr' ] );
MHM_Model::set_primary_hub( 40, 10, 'geo' );

$scan   = MHM_Scanner::scan( 10 );
$report = MHM_Scanner::reverse_report( 10, 'geo', $scan['linked_ids'] );

is_same( [ 20 ], $report['linked_assigned'], 'linked and assigned' );
ok( in_array( 21, $report['linked_not_assigned'], true ), 'linked but not assigned' );
is_same( [ 40 ], $report['assigned_not_linked'], 'assigned but no longer linked' );
is_same( 10, MHM_Model::get_primary_hub( 40, 'geo' ), 'a stale relationship is never deleted automatically' );

echo "\nUntranslated posts\n";

mock_enable_polylang( false ); // Polylang present, but no language on these posts.
is_same( '', MHM_Model::get_language( 24 ), 'an untranslated post reports no language' );
ok( MHM_Model::is_same_language( 24, 10 ), 'a missing language never blocks an assignment' );
is_same( MHM_Scanner::UNASSIGNED, MHM_Scanner::classify( 24, 10, 'geo' )['state'], 'without languages the link becomes a normal candidate' );

finish();
