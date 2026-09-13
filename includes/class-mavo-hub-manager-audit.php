<?php
/**
 * Read-only audit queries over the hub model.
 *
 * Nothing in this class writes. It answers editorial questions the stored
 * model can already answer, but that the per-hub screens cannot show at site
 * scale:
 *
 *   1. Which posts/pages still have no primary hub?
 *   2. Which pages look like hubs but are not marked as one?
 *   3. Which children never link back to the hub that owns them?
 *   4. What is one post related to, through its hubs?
 *   5. How much traffic does each hub own, counting everything below it?
 *   6. How healthy is each hub (children, parent, stale links, issues)?
 *   7. Which stored relationships are broken? (delegates to MHM_Model)
 *
 * Everything is derived from the same three meta keys. No audit result is
 * cached in post meta, and no audit run repairs anything.
 *
 * ---------------------------------------------------------------------------
 * Cost rules, learned the hard way — these reports run over the whole site:
 *
 *   * Every meta condition is AND'ed and uses EXISTS / NOT EXISTS only. An OR
 *     group over postmeta makes WordPress emit one LEFT JOIN per branch and
 *     MySQL then joins them against each other; three such groups is enough to
 *     hang the request.
 *   * No SQL_CALC_FOUND_ROWS: 'no_found_rows' is always on and one extra row is
 *     fetched to answer "is there a next page?".
 *   * The link-back check never resolves a URL to an ID. url_to_postid() runs
 *     the rewrite rules plus a query per link, which is thousands of queries
 *     for one batch. Links are compared to the hub's own permalink instead —
 *     see url_keys() — which costs nothing.
 * ---------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

class MHM_Audit {

	public const DEFAULT_PER_PAGE = 50;

	/** How many assigned children one "no link back" pass reads content for. */
	public const DEFAULT_BATCH = 100;

	/** How many posts one "hub candidates" pass reads content for. */
	public const DEFAULT_CANDIDATE_BATCH = 150;

	/** Ceiling on the cached candidate tally. */
	public const MAX_CANDIDATES = 1000;

	/** Link-back outcomes. */
	public const LINK_ANCHOR    = 'anchor';
	public const LINK_SHORTCODE = 'shortcode';
	public const LINK_ANCESTOR  = 'ancestor';
	public const LINK_NONE      = 'none';

	/** Per-request caches: identity keys per post, link keys per post. */
	private static array $post_keys_cache = [];
	private static array $link_keys_cache = [];

	/* ------------------------------------------------------------ settings */

	/**
	 * Meta key holding the view counter used for ordering.
	 *
	 * Maman Voyage stores it as plain `views` (see mavo-geo-explorer and
	 * postsByTagOrderedByViews).
	 */
	public static function views_meta_key(): string {
		return (string) apply_filters( 'mavo_hub_manager_views_meta_key', 'views' );
	}

	public static function get_views( int $post_id ): ?int {
		$raw = get_post_meta( absint( $post_id ), self::views_meta_key(), true );

		return ( '' === $raw || null === $raw ) ? null : (int) $raw;
	}

	/**
	 * Shortcodes that render a link back to a hub, as tag => attribute.
	 *
	 * `[mavo_hub_strip slug="france" text="…{guide France}…"]` renders a link
	 * back to /france/ on the frontend, so a post carrying it is NOT missing a
	 * link back — even though the stored content contains no <a href>.
	 *
	 * The attribute names where the shortcode keeps its target. An empty one
	 * means the shortcode has no target attribute at all and always links to
	 * the post's own hubs — `[geo_related]` from mavo-for-you, whose block
	 * places the post's primary hubs as its first cards. Such a tag must also
	 * be listed in link_back_hub_shortcodes() for the hub lookup to happen.
	 *
	 * No shortcode has to be registered, and none is ever rendered: the stored
	 * content is parsed as text, exactly like the internal-link scanner.
	 */
	public static function link_back_shortcodes(): array {
		return (array) apply_filters(
			'mavo_hub_manager_link_back_shortcodes',
			[
				'mavo_hub_strip' => 'slug',
				'geo_related'    => '',
			]
		);
	}

	/**
	 * Link-back shortcodes whose target attribute is optional, as a tag list.
	 *
	 * Since the hub hierarchy exists, `[mavo_hub_strip]` needs no slug: it
	 * links to the post's own primary hubs. Reading only the slug attribute
	 * would report exactly those posts as missing a link back, so a slugless
	 * occurrence is resolved against the child's own hub meta instead.
	 *
	 * Which hubs it points at is read from the shortcode the same way the
	 * shortcode itself reads it, still without rendering anything:
	 *
	 *   [mavo_hub_strip]                     both primary hubs
	 *   [mavo_hub_strip hub="geo"]           the geographic hub only
	 *   [mavo_hub_strip text="…{geo:…}…"]    the types its markers name
	 *   [geo_related]                        both primary hubs
	 *
	 * `[geo_related]` takes no target attribute: its block leads with the
	 * post's own hub cards, so carrying it counts as a link back to both
	 * primary hubs. It is a recommendation block, though, not a fixed link —
	 * the hub card can be crowded out by the block's own limits, and the
	 * frontend may swap the block for personalized picks — so this is a
	 * deliberate editorial decision, not a guarantee the link is on the page.
	 *
	 * @return string[]
	 */
	public static function link_back_hub_shortcodes(): array {
		return array_values( array_filter( array_map(
			'strval',
			(array) apply_filters(
				'mavo_hub_manager_link_back_hub_shortcodes',
				[ 'mavo_hub_strip', 'geo_related' ]
			)
		) ) );
	}

	/**
	 * The hub types one slugless link-back shortcode points at.
	 *
	 * @param array $atts Parsed shortcode attributes.
	 * @return string[] Any of 'geo', 'theme'.
	 */
	public static function shortcode_hub_types( array $atts ): array {
		$text = isset( $atts['text'] ) ? (string) $atts['text'] : '';

		// Sentence mode: the {geo:…} / {theme:…} markers decide, not `hub`.
		if ( '' !== trim( $text ) ) {
			$found = [];

			if ( preg_match_all( '/\{(geo|theme)\s*:/i', $text, $matches ) ) {
				foreach ( $matches[1] as $marker ) {
					$found[ strtolower( (string) $marker ) ] = true;
				}
			}

			return array_keys( $found );
		}

		$hub = isset( $atts['hub'] ) ? strtolower( trim( (string) $atts['hub'] ) ) : '';

		if ( MHM_Model::is_valid_type( $hub ) ) {
			return [ $hub ];
		}

		return ( '' === $hub || 'both' === $hub ) ? MHM_Model::types() : [];
	}

	/* ------------------------------------------------------- link matching */

	/**
	 * Comparable keys for one href, without touching the database.
	 *
	 * A link and a post match when they share any key:
	 *
	 *   path:/paris-en-famille/   the normalised site path
	 *   slug:paris-en-famille     the last path segment, so a dated permalink
	 *                             (/2024/05/slug/) matches a plain one
	 *   id:1234                   ?p=/?page_id=/?post= links
	 *
	 * External, fragment-only, mailto/tel/javascript links produce no keys.
	 *
	 * @return string[]
	 */
	public static function url_keys( string $url ): array {
		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $url || str_starts_with( $url, '#' ) ) {
			return [];
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( $scheme && ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return [];
		}

		$absolute = MHM_Scanner::to_internal_absolute_url( $url );
		if ( '' === $absolute ) {
			return [];
		}

		$parts = wp_parse_url( $absolute );
		if ( ! is_array( $parts ) ) {
			return [];
		}

		$keys = [];
		$path = isset( $parts['path'] ) ? strtolower( rawurldecode( (string) $parts['path'] ) ) : '';
		$path = trim( $path, '/' );

		if ( '' !== $path ) {
			$keys[] = 'path:/' . $path . '/';

			$segments = array_values( array_filter( explode( '/', $path ) ) );
			$slug     = (string) end( $segments );

			// A trailing /page/2/ or /amp/ still points at the same object.
			if ( in_array( $slug, [ 'amp', 'feed' ], true ) || ctype_digit( $slug ) ) {
				array_pop( $segments );
				$slug = (string) end( $segments );
			}

			if ( '' !== $slug ) {
				$keys[] = 'slug:' . $slug;
			}
		}

		if ( ! empty( $parts['query'] ) ) {
			$query = [];
			parse_str( (string) $parts['query'], $query );

			foreach ( [ 'p', 'page_id', 'post' ] as $arg ) {
				if ( ! empty( $query[ $arg ] ) && ctype_digit( (string) $query[ $arg ] ) ) {
					$keys[] = 'id:' . (int) $query[ $arg ];
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * The keys that identify one post as a link target.
	 *
	 * @return string[]
	 */
	public static function post_keys( int $post_id ): array {
		$post_id = absint( $post_id );

		if ( isset( self::$post_keys_cache[ $post_id ] ) ) {
			return self::$post_keys_cache[ $post_id ];
		}

		$post = MHM_Model::get_eligible_post( $post_id );
		if ( ! $post ) {
			return self::$post_keys_cache[ $post_id ] = [];
		}

		$keys = [ 'id:' . $post_id ];

		$permalink = (string) get_permalink( $post_id );
		if ( '' !== $permalink ) {
			$keys = array_merge( $keys, self::url_keys( $permalink ) );
		}

		if ( '' !== (string) $post->post_name ) {
			$keys[] = 'slug:' . strtolower( (string) $post->post_name );
		}

		return self::$post_keys_cache[ $post_id ] = array_values( array_unique( $keys ) );
	}

	/**
	 * Every link key this post points at, split by how it links.
	 *
	 * @return array{anchor:string[],shortcode:string[]}
	 */
	public static function outbound_link_keys( int $post_id ): array {
		$post_id = absint( $post_id );

		if ( isset( self::$link_keys_cache[ $post_id ] ) ) {
			return self::$link_keys_cache[ $post_id ];
		}

		$post    = MHM_Model::get_eligible_post( $post_id );
		$content = $post ? (string) $post->post_content : '';

		$anchor = [];
		foreach ( MHM_Scanner::extract_links( $content ) as $url ) {
			foreach ( self::url_keys( $url ) as $key ) {
				$anchor[ $key ] = true;
			}
		}

		$shortcode = [];
		foreach ( self::shortcode_link_keys( $content, $post_id ) as $key ) {
			$shortcode[ $key ] = true;
		}

		return self::$link_keys_cache[ $post_id ] = [
			'anchor'    => array_keys( $anchor ),
			'shortcode' => array_keys( $shortcode ),
		];
	}

	/** Drop the per-post caches so a long batch does not hold every post. */
	public static function flush_caches(): void {
		self::$link_keys_cache = [];
		self::$post_keys_cache = [];
	}

	/**
	 * Link keys referenced by link-back shortcodes in stored content.
	 *
	 * @param string $content Stored post content.
	 * @param int    $post_id The post the content belongs to. Needed only to
	 *                        resolve slugless shortcodes against its own hubs.
	 * @return string[]
	 */
	public static function shortcode_link_keys( string $content, int $post_id = 0 ): array {
		if ( '' === trim( $content ) ) {
			return [];
		}

		$post_id   = absint( $post_id );
		$implicit  = self::link_back_hub_shortcodes();
		$keys      = [];

		foreach ( self::link_back_shortcodes() as $tag => $attribute ) {
			$tag       = (string) $tag;
			$attribute = (string) $attribute;

			if ( '' === $tag || false === stripos( $content, '[' . $tag ) ) {
				continue;
			}

			$pattern = '/\[' . preg_quote( $tag, '/' ) . '(?![\w-])([^\]]*)\]/i';
			if ( ! preg_match_all( $pattern, $content, $matches ) ) {
				continue;
			}

			$resolves_hubs = $post_id && in_array( $tag, $implicit, true );

			foreach ( $matches[1] as $raw_atts ) {
				$atts  = shortcode_parse_atts( $raw_atts );
				$atts  = is_array( $atts ) ? $atts : [];
				$value = ( '' !== $attribute && isset( $atts[ $attribute ] ) ) ? trim( (string) $atts[ $attribute ] ) : '';

				if ( '' === $value ) {
					if ( $resolves_hubs ) {
						foreach ( self::shortcode_hub_types( $atts ) as $type ) {
							$hub_id = MHM_Model::get_primary_hub( $post_id, $type );

							if ( $hub_id ) {
								$keys[ 'id:' . (int) $hub_id ] = true;
							}
						}
					}

					continue;
				}

				foreach ( self::url_keys( self::shortcode_value_to_url( $value ) ) as $key ) {
					$keys[ $key ] = true;
				}
			}
		}

		return array_keys( $keys );
	}

	/**
	 * Turn a shortcode slug into a site URL, the same way mavo_hub_strip does:
	 * "france", "/france/" and "en/london-with-kids" all become /<path>/.
	 * A full URL is passed through and judged internal by the scanner.
	 */
	public static function shortcode_value_to_url( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return $value;
		}

		return home_url( '/' . trim( $value, '/' ) . '/' );
	}

	/**
	 * Does this child link back to the hub that owns it?
	 *
	 * A direct anchor and a link-back shortcode both count as linked. A link to
	 * an ancestor of the hub (Paris instead of Paris en famille) does not: it is
	 * reported separately so the editor can judge it.
	 *
	 * Pure PHP — the child's content and the hub's permalink are all it needs.
	 *
	 * @return array{linked:bool,via:string,ancestor:int}
	 */
	public static function link_back_status( int $child_id, int $hub_id, string $type ): array {
		$child_id = absint( $child_id );
		$hub_id   = absint( $hub_id );

		$result = [ 'linked' => false, 'via' => self::LINK_NONE, 'ancestor' => 0 ];

		if ( ! $child_id || ! $hub_id || $child_id === $hub_id ) {
			return $result;
		}

		$links    = self::outbound_link_keys( $child_id );
		$hub_keys = self::post_keys( $hub_id );

		if ( ! $hub_keys ) {
			return $result; // The hub no longer exists; the errors tab owns that case.
		}

		if ( array_intersect( $hub_keys, $links['anchor'] ) ) {
			return [ 'linked' => true, 'via' => self::LINK_ANCHOR, 'ancestor' => 0 ];
		}

		if ( array_intersect( $hub_keys, $links['shortcode'] ) ) {
			return [ 'linked' => true, 'via' => self::LINK_SHORTCODE, 'ancestor' => 0 ];
		}

		$reachable = array_merge( $links['anchor'], $links['shortcode'] );
		if ( ! $reachable ) {
			return $result;
		}

		foreach ( MHM_Model::get_hub_ancestors( $hub_id, $type ) as $ancestor ) {
			if ( array_intersect( self::post_keys( (int) $ancestor ), $reachable ) ) {
				$result['via']      = self::LINK_ANCESTOR;
				$result['ancestor'] = (int) $ancestor;
				break;
			}
		}

		return $result;
	}

	/* ------------------------------------------------------- query helpers */

	/**
	 * Shared query scaffolding.
	 *
	 * `no_found_rows` is deliberate: the reports page with prev/next links
	 * instead of a total, because counting every matching row is exactly the
	 * part that does not scale.
	 */
	private static function base_args( array $args ): array {
		$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any';
		$status    = isset( $args['status'] ) ? (string) $args['status'] : 'publish';

		$query = [
			'post_type'              => in_array( $post_type, MHM_Model::POST_TYPES, true ) ? [ $post_type ] : MHM_Model::POST_TYPES,
			'post_status'            => in_array( $status, [ 'publish', 'draft', 'pending', 'future', 'private' ], true ) ? $status : 'any',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		];

		// Polylang filters admin queries by the admin language unless told
		// otherwise; '' means every language.
		$query['lang'] = isset( $args['lang'] ) ? (string) $args['lang'] : '';

		return $query;
	}

	/**
	 * Ordering. 'views' inner-joins the counter — which means posts that have
	 * no counter at all are not in that list; 'date' is the way to see those.
	 */
	private static function order_args( string $sort ): array {
		if ( 'views' === $sort ) {
			// Ordering by a meta value sorts the whole matching set before the
			// LIMIT can help. On a large postmeta table that is the expensive
			// choice, which is why 'date' is the default.
			return [
				'clause'  => [ 'key' => self::views_meta_key(), 'compare' => 'EXISTS', 'type' => 'NUMERIC' ],
				'orderby' => [ 'mhm_views' => 'DESC' ],
			];
		}

		return [ 'clause' => null, 'orderby' => [ 'date' => 'DESC' ] ];
	}

	/** Warm the post and meta caches for one page of results in two queries. */
	private static function prime( array $post_ids ): void {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );

		if ( ! $post_ids ) {
			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
		if ( function_exists( 'update_meta_cache' ) ) {
			update_meta_cache( 'post', $post_ids );
		}
	}

	/* --------------------------------------------------- 1. posts with no hub */

	/**
	 * Posts/pages with no primary hub.
	 *
	 * @param array $args mode: geo|theme|both, sort: views|date, lang,
	 *                    post_type, status, paged, per_page, include_hubs.
	 * @return array{rows:int[],paged:int,per_page:int,has_more:bool,mode:string,sort:string}
	 */
	public static function missing_hub( array $args = [] ): array {
		$mode = isset( $args['mode'] ) && in_array( $args['mode'], [ 'geo', 'theme', 'both' ], true )
			? (string) $args['mode']
			: 'geo';
		$sort = isset( $args['sort'] ) && 'views' === $args['sort'] ? 'views' : 'date';

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, absint( $args['per_page'] ) ) ) : self::DEFAULT_PER_PAGE;
		$paged    = isset( $args['paged'] ) ? max( 1, absint( $args['paged'] ) ) : 1;

		$meta_query = [ 'relation' => 'AND' ];
		$order      = self::order_args( $sort );

		if ( $order['clause'] ) {
			$meta_query['mhm_views'] = $order['clause'];
		}

		// One NOT EXISTS per key, AND'ed. A stored empty or 0 value would slip
		// through here; the Relationship errors tab is where those show up.
		foreach ( ( 'both' === $mode ? MHM_Model::types() : [ $mode ] ) as $type ) {
			$meta_query[] = [ 'key' => MHM_Model::meta_key_for_type( $type ), 'compare' => 'NOT EXISTS' ];
		}

		// Hubs are hidden by default: a top-level hub legitimately has no parent.
		if ( empty( $args['include_hubs'] ) ) {
			$meta_query[] = [ 'key' => MHM_Model::META_HUB_TYPE, 'compare' => 'NOT EXISTS' ];
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				// One extra row answers "is there a next page?" without counting.
				'posts_per_page' => $per_page + 1,
				'offset'         => ( $paged - 1 ) * $per_page,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- on-demand audit screen.
				'orderby'        => $order['orderby'],
			]
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = (string) $args['search'];
		}

		$rows     = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );
		$has_more = count( $rows ) > $per_page;
		$rows     = array_slice( $rows, 0, $per_page );

		self::prime( $rows );

		return [
			'rows'     => $rows,
			'paged'    => $paged,
			'per_page' => $per_page,
			'has_more' => $has_more,
			'mode'     => $mode,
			'sort'     => $sort,
		];
	}

	/* ------------------------------------------------- 2. hub candidates */

	/**
	 * How many distinct internal targets this post's own content links to.
	 *
	 * Counted from link keys, so it costs no queries — which also means the
	 * links are not resolved to post IDs: a link to a category, a tag or an
	 * attachment counts here, and only the hub scanner (once the page is
	 * actually marked as a hub) decides what is an eligible child. It is a
	 * ranking signal, not a child count.
	 *
	 * Links to the post itself are ignored, and several links to one target
	 * count once.
	 */
	public static function count_internal_links( int $post_id ): int {
		$post_id = absint( $post_id );
		$post    = MHM_Model::get_eligible_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$self    = self::post_keys( $post_id );
		$targets = [];

		foreach ( MHM_Scanner::extract_links( (string) $post->post_content ) as $url ) {
			$keys = self::url_keys( $url );

			if ( ! $keys || array_intersect( $keys, $self ) ) {
				continue; // External, unusable, or a self-link.
			}

			$targets[ self::canonical_key( $keys ) ] = true;
		}

		return count( $targets );
	}

	/**
	 * One key per link target, so two spellings of the same destination
	 * (/slug/ and /2024/05/slug/) are not counted twice.
	 */
	private static function canonical_key( array $keys ): string {
		foreach ( [ 'slug:', 'path:', 'id:' ] as $prefix ) {
			foreach ( $keys as $key ) {
				if ( str_starts_with( $key, $prefix ) ) {
					return $key;
				}
			}
		}

		return (string) reset( $keys );
	}

	/** A fresh, empty candidates scan for one set of filters. */
	public static function candidates_state( string $signature = '' ): array {
		return [
			'signature' => $signature,
			'cursor'    => 0,
			'scanned'   => 0,
			'counts'    => [],
			'done'      => false,
		];
	}

	/** Filters that a scan belongs to. Changing any of them starts a new one. */
	public static function candidates_signature( array $args ): string {
		return md5(
			(string) wp_json_encode(
				[
					'lang'   => (string) ( $args['lang'] ?? '' ),
					'ptype'  => (string) ( $args['post_type'] ?? 'any' ),
					'status' => (string) ( $args['status'] ?? 'publish' ),
				]
			)
		);
	}

	/**
	 * Scan the next batch of not-yet-hub posts and add them to the tally.
	 *
	 * Reading content is what costs, so one pass reads one batch and the
	 * caller keeps the returned state — the leaderboard is sorted over
	 * everything scanned so far and becomes site-wide once `done` is true.
	 * Nothing is written to post meta; the state is a cache the caller owns.
	 *
	 * @param array $args  lang, post_type, status, batch.
	 * @param array $state State from the previous pass, or [] to start.
	 * @return array The new state.
	 */
	public static function scan_candidates( array $args = [], array $state = [] ): array {
		$batch     = isset( $args['batch'] ) ? max( 1, min( 500, absint( $args['batch'] ) ) ) : self::DEFAULT_CANDIDATE_BATCH;
		$signature = self::candidates_signature( $args );

		if ( ! isset( $state['signature'] ) || $state['signature'] !== $signature ) {
			$state = self::candidates_state( $signature );
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				'posts_per_page' => $batch,
				'offset'         => (int) $state['cursor'],
				// Scan order only decides what is read first; the leaderboard
				// is sorted by link count. ID keeps the cursor stable and needs
				// no join.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery -- on-demand audit screen.
					[ 'key' => MHM_Model::META_HUB_TYPE, 'compare' => 'NOT EXISTS' ],
				],
			]
		);

		$ids = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );

		self::prime( $ids );

		foreach ( $ids as $post_id ) {
			$links = self::count_internal_links( $post_id );

			// Only pages that link somewhere are candidates, and dropping the
			// zeroes is what keeps the cached tally small.
			if ( $links > 0 ) {
				$state['counts'][ $post_id ] = $links;
			}
		}

		self::flush_caches();

		$state['cursor']  = (int) $state['cursor'] + count( $ids );
		$state['scanned'] = (int) $state['scanned'] + count( $ids );

		// A short batch means the end. The job is deliberately not counted
		// first: counting posts that lack a meta value means an anti-join over
		// the whole table, which is the kind of query this site cannot afford.
		$state['done'] = count( $ids ) < $batch;

		arsort( $state['counts'], SORT_NUMERIC );

		// A hard ceiling on the cached tally: nobody marks the 1000th best
		// candidate, and the state has to stay small enough to cache.
		if ( count( $state['counts'] ) > self::MAX_CANDIDATES ) {
			$state['counts'] = array_slice( $state['counts'], 0, self::MAX_CANDIDATES, true );
		}

		return $state;
	}

	/**
	 * One page of the leaderboard.
	 *
	 * @return array{rows:array,paged:int,per_page:int,has_more:bool,found:int}
	 */
	public static function candidates_rows( array $state, int $paged = 1, int $per_page = self::DEFAULT_PER_PAGE ): array {
		$counts   = isset( $state['counts'] ) && is_array( $state['counts'] ) ? $state['counts'] : [];
		$paged    = max( 1, $paged );
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$page = array_slice( $counts, $offset, $per_page, true );

		self::prime( array_keys( $page ) );

		$rows = [];
		$rank = $offset;

		foreach ( $page as $post_id => $links ) {
			$post = MHM_Model::get_eligible_post( (int) $post_id );

			if ( ! $post ) {
				continue; // Deleted since the scan; the tally is only a cache.
			}

			$rows[] = [
				'post'      => (int) $post_id,
				'links'     => (int) $links,
				'rank'      => ++$rank,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'views'     => self::get_views( (int) $post_id ),
			];
		}

		return [
			'rows'     => $rows,
			'paged'    => $paged,
			'per_page' => $per_page,
			'has_more' => count( $counts ) > $offset + $per_page,
			'found'    => count( $counts ),
		];
	}

	/* ------------------------------------------- 3. children with no link back */

	/**
	 * Children that point at a hub whose page they never link back to.
	 *
	 * Reading post content is the expensive part, so this walks the assigned
	 * children in view-count order and only inspects one batch per run. The
	 * caller pages through with `offset`.
	 *
	 * @param array $args type: geo|theme, sort, lang, post_type, status, offset, batch.
	 * @return array{rows:array,offset:int,batch:int,scanned:int,linked:int,ancestor_only:int,has_more:bool,type:string,sort:string}
	 */
	public static function no_link_back( array $args = [] ): array {
		$type = isset( $args['type'] ) && MHM_Model::is_valid_type( (string) $args['type'] ) ? (string) $args['type'] : 'geo';
		$sort = isset( $args['sort'] ) && 'views' === $args['sort'] ? 'views' : 'date';

		$batch  = isset( $args['batch'] ) ? max( 1, min( 500, absint( $args['batch'] ) ) ) : self::DEFAULT_BATCH;
		$offset = isset( $args['offset'] ) ? max( 0, absint( $args['offset'] ) ) : 0;

		$order      = self::order_args( $sort );
		$meta_query = [
			'relation' => 'AND',
			[ 'key' => MHM_Model::meta_key_for_type( $type ), 'compare' => 'EXISTS' ],
		];

		if ( $order['clause'] ) {
			$meta_query['mhm_views'] = $order['clause'];
		}

		$query_args = array_merge(
			self::base_args( $args ),
			[
				'posts_per_page' => $batch + 1,
				'offset'         => $offset,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- on-demand audit screen.
				'orderby'        => $order['orderby'],
			]
		);

		$children = array_map( 'absint', (array) ( new WP_Query( $query_args ) )->posts );
		$has_more = count( $children ) > $batch;
		$children = array_slice( $children, 0, $batch );

		// Two queries for the whole batch instead of two per row.
		self::prime( $children );

		$rows          = [];
		$linked        = 0;
		$ancestor_only = 0;

		foreach ( $children as $child_id ) {
			$hub_id = MHM_Model::get_primary_hub( $child_id, $type );

			if ( null === $hub_id || $hub_id === $child_id ) {
				continue;
			}

			$status = self::link_back_status( $child_id, $hub_id, $type );

			if ( $status['linked'] ) {
				$linked++;
				continue;
			}

			if ( self::LINK_ANCESTOR === $status['via'] ) {
				$ancestor_only++;
			}

			$rows[] = [
				'child'    => $child_id,
				'type'     => $type,
				'hub'      => $hub_id,
				'via'      => $status['via'],
				'ancestor' => $status['ancestor'],
				'views'    => self::get_views( $child_id ),
				'missing'  => ! MHM_Model::get_eligible_post( $hub_id ),
			];
		}

		self::flush_caches();

		return [
			'rows'          => $rows,
			'offset'        => $offset,
			'batch'         => $batch,
			'scanned'       => count( $children ),
			'linked'        => $linked,
			'ancestor_only' => $ancestor_only,
			'has_more'      => $has_more,
			'type'          => $type,
			'sort'          => $sort,
		];
	}

	/* ------------------------------------------------ 4. one post's relations */

	/** Nodes a single group may contribute before it is truncated. */
	public const GRAPH_SIBLINGS = 8;
	public const GRAPH_AUNTS    = 3;
	public const GRAPH_COUSINS  = 3;

	/**
	 * Everything one post is related to through the hub model.
	 *
	 * Per hub type: its primary hub, that hub's ancestors, its siblings (the
	 * hub's other children), and optionally its cousins (the children of the
	 * hub's own sibling hubs). Each group is fetched one over its cap so the
	 * view can say "more" without counting the rest.
	 *
	 * Costs about a dozen queries for one post, all of them small. Nothing is
	 * stored: this is the same hierarchy every other screen infers.
	 *
	 * @param array $opts cousins (bool), max (siblings per hub).
	 * @return array|WP_Error
	 */
	public static function relation_graph( int $post_id, array $opts = [] ) {
		$post = MHM_Model::get_eligible_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'mhm_invalid_post', __( 'That post or page does not exist.', 'mavo-hub-manager' ) );
		}

		$post_id      = (int) $post->ID;
		$max          = isset( $opts['max'] ) ? max( 1, min( 40, absint( $opts['max'] ) ) ) : self::GRAPH_SIBLINGS;
		$with_cousins = ! empty( $opts['cousins'] );

		$graph = [
			'post'  => $post_id,
			'types' => [],
			'nodes' => [],
			'ids'   => [ $post_id ],
		];

		foreach ( MHM_Model::types() as $type ) {
			$stored = MHM_Model::get_primary_hub( $post_id, $type );
			$hub    = ( $stored && MHM_Model::get_hub_type( $stored ) === $type ) ? $stored : null;

			$side = [
				'hub'            => $hub,
				'broken_hub'     => ( $stored && ! $hub ) ? $stored : null,
				'ancestors'      => [],
				'siblings'       => [],
				'siblings_more'  => false,
				'aunts'          => [],
				'aunts_more'     => false,
			];

			if ( $hub ) {
				$side['ancestors'] = MHM_Model::get_hub_ancestors( $hub, $type );

				$children = MHM_Model::get_hub_children( $hub, $type, [ 'posts_per_page' => $max + 2 ] );
				$children = array_values( array_diff( $children, [ $post_id ] ) );

				$side['siblings_more'] = count( $children ) > $max;
				$side['siblings']      = array_slice( $children, 0, $max );

				if ( $with_cousins && $side['ancestors'] ) {
					$side = self::graph_aunts( $side, (int) $side['ancestors'][0], $hub, $type );
				}
			}

			$graph['types'][ $type ] = $side;

			$graph['ids'] = array_merge(
				$graph['ids'],
				array_filter( [ $hub, $side['broken_hub'] ] ),
				$side['ancestors'],
				$side['siblings'],
				array_column( $side['aunts'], 'hub' ),
				...array_column( $side['aunts'], 'children' )
			);
		}

		$graph['ids'] = array_values( array_unique( array_map( 'absint', $graph['ids'] ) ) );

		// One pass for every node on screen, instead of a query per node.
		self::prime( $graph['ids'] );

		foreach ( $graph['ids'] as $id ) {
			$graph['nodes'][ $id ] = self::graph_node( (int) $id );
		}

		return $graph;
	}

	/** The hub's own sibling hubs, and the cousins hanging under each of them. */
	private static function graph_aunts( array $side, int $grandparent, int $hub, string $type ): array {
		$candidates = MHM_Model::get_hub_children(
			$grandparent,
			$type,
			[ 'posts_per_page' => ( self::GRAPH_AUNTS + 1 ) * 3 ]
		);

		$aunts = [];
		foreach ( $candidates as $candidate ) {
			$candidate = (int) $candidate;

			// Only a hub can have cousins under it.
			if ( $candidate === $hub || MHM_Model::get_hub_type( $candidate ) !== $type ) {
				continue;
			}

			$aunts[] = $candidate;
		}

		$side['aunts_more'] = count( $aunts ) > self::GRAPH_AUNTS;
		$aunts              = array_slice( $aunts, 0, self::GRAPH_AUNTS );

		foreach ( $aunts as $aunt ) {
			$children = MHM_Model::get_hub_children( $aunt, $type, [ 'posts_per_page' => self::GRAPH_COUSINS + 1 ] );

			$side['aunts'][] = [
				'hub'      => $aunt,
				'children' => array_slice( $children, 0, self::GRAPH_COUSINS ),
				'more'     => count( $children ) > self::GRAPH_COUSINS,
			];
		}

		return $side;
	}

	/** The facts one node shows, on the graph and in its hover card. */
	private static function graph_node( int $post_id ): array {
		$post = MHM_Model::get_eligible_post( $post_id );

		if ( ! $post ) {
			return [
				'id'      => $post_id,
				'title'   => sprintf( __( '(missing #%d)', 'mavo-hub-manager' ), $post_id ),
				'missing' => true,
			];
		}

		return [
			'id'        => $post_id,
			'title'     => get_the_title( $post ) ?: sprintf( __( '(no title) #%d', 'mavo-hub-manager' ), $post_id ),
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'lang'      => MHM_Model::get_language( $post_id ),
			'hub_type'  => MHM_Model::get_hub_type( $post_id ),
			'geo_hub'   => MHM_Model::get_primary_hub( $post_id, 'geo' ),
			'theme_hub' => MHM_Model::get_primary_hub( $post_id, 'theme' ),
			'views'     => self::get_views( $post_id ),
			'missing'   => false,
		];
	}

	/* ------------------------------------------------------- 5. hub traffic */

	/**
	 * child ID => hub ID for one type, in a single query.
	 *
	 * Returns null when there is no $wpdb to ask, and the caller falls back to
	 * the model helpers — which is what the test harness runs on.
	 */
	public static function relationship_map( string $type ): ?array {
		global $wpdb;

		$key = MHM_Model::meta_key_for_type( $type );

		if ( ! $key || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( MHM_Model::POST_TYPES ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and generated placeholders.
				"SELECT pm.post_id AS child, pm.meta_value AS hub
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type IN ( {$placeholders} )",
				// phpcs:enable
				array_merge( [ $key ], MHM_Model::POST_TYPES )
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$child = absint( $row->child );
			$hub   = absint( $row->hub );

			if ( $child && $hub && $child !== $hub ) {
				$map[ $child ] = $hub;
			}
		}

		return $map;
	}

	/**
	 * post ID => view count, for the posts named, in chunked queries.
	 *
	 * Null without $wpdb, as above.
	 */
	public static function views_map( array $post_ids ): ?array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return null;
		}

		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		$views    = [];

		foreach ( array_chunk( $post_ids, 2000 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and generated placeholders.
					"SELECT post_id, meta_value
					 FROM {$wpdb->postmeta}
					 WHERE meta_key = %s
					   AND post_id IN ( {$placeholders} )",
					// phpcs:enable
					array_merge( [ self::views_meta_key() ], $chunk )
				)
			);

			foreach ( (array) $rows as $row ) {
				$views[ absint( $row->post_id ) ] = (int) $row->meta_value;
			}
		}

		return $views;
	}

	/**
	 * Traffic each hub owns: its own views, plus the views of everything below
	 * it in its own hierarchy.
	 *
	 * "Below it" is the same inferred descent every other screen uses — the
	 * children's stored primary hubs, followed downwards — so a country hub
	 * counts the traffic of its cities and of their articles. Nothing is
	 * stored; the roll-up is recomputed each run from two queries plus the hub
	 * registry.
	 *
	 * @param array $args type, lang, search, sort (total|subtree|children|per_child).
	 * @return array{rows:array,summary:array}
	 */
	public static function hub_traffic( array $args = [] ): array {
		$type_filter = isset( $args['type'] ) && MHM_Model::is_valid_type( (string) $args['type'] ) ? (string) $args['type'] : '';
		$sort        = isset( $args['sort'] ) && in_array( $args['sort'], [ 'total', 'subtree', 'children', 'per_child' ], true )
			? (string) $args['sort']
			: 'total';

		$rows    = [];
		$summary = [ 'hubs' => 0, 'views' => 0, 'posts' => 0, 'without_views' => 0 ];

		foreach ( MHM_Model::types() as $type ) {
			if ( $type_filter && $type !== $type_filter ) {
				continue;
			}

			$hubs = MHM_Model::get_hubs(
				[
					'type'   => $type,
					'lang'   => isset( $args['lang'] ) ? (string) $args['lang'] : '',
					'search' => isset( $args['search'] ) ? (string) $args['search'] : '',
				]
			);

			if ( ! $hubs ) {
				continue;
			}

			$children_of = self::children_map( $type );
			$needed      = array_merge( $hubs, array_keys( $children_of ), ...array_values( $children_of ) );
			$views       = self::views_map( $needed );

			if ( null === $views ) {
				$views = [];
				foreach ( array_unique( array_map( 'absint', $needed ) ) as $id ) {
					$counted = self::get_views( (int) $id );

					if ( null !== $counted ) {
						$views[ (int) $id ] = $counted;
					}
				}
			}

			self::prime( $hubs );

			foreach ( $hubs as $hub_id ) {
				$hub_id = (int) $hub_id;

				$descendants   = self::subtree( $hub_id, $children_of );
				$subtree_views = 0;

				foreach ( $descendants as $descendant ) {
					$subtree_views += (int) ( $views[ $descendant ] ?? 0 );
				}

				$own   = (int) ( $views[ $hub_id ] ?? 0 );
				$posts = count( $descendants );

				$rows[] = [
					'hub'       => $hub_id,
					'type'      => $type,
					'lang'      => MHM_Model::get_language( $hub_id ),
					'direct'    => count( $children_of[ $hub_id ] ?? [] ),
					'posts'     => $posts,
					'own'       => $own,
					'subtree'   => $subtree_views,
					'total'     => $own + $subtree_views,
					'per_child' => $posts ? (int) round( $subtree_views / $posts ) : 0,
				];

				$summary['hubs']++;
				$summary['views'] += $own + $subtree_views;
				$summary['posts'] += $posts;

				if ( ! $own && ! $subtree_views ) {
					$summary['without_views']++;
				}
			}
		}

		usort(
			$rows,
			static fn( $a, $b ) => [ $b[ $sort ], $b['total'] ] <=> [ $a[ $sort ], $a['total'] ]
		);

		return [ 'rows' => $rows, 'summary' => $summary, 'sort' => $sort ];
	}

	/** hub ID => child IDs, for one type, however the data can be read. */
	private static function children_map( string $type ): array {
		$map         = self::relationship_map( $type );
		$children_of = [];

		if ( null !== $map ) {
			foreach ( $map as $child => $hub ) {
				$children_of[ $hub ][] = $child;
			}

			return $children_of;
		}

		// No $wpdb: ask the model, hub by hub. Every hub of the type, not just
		// the filtered ones, or a subtree could stop at a hub outside the filter.
		foreach ( MHM_Model::get_hubs( [ 'type' => $type ] ) as $hub_id ) {
			$children = MHM_Model::get_hub_children( (int) $hub_id, $type );

			if ( $children ) {
				$children_of[ (int) $hub_id ] = array_map( 'absint', $children );
			}
		}

		return $children_of;
	}

	/**
	 * Every post below one hub, at any depth.
	 *
	 * Breadth-first with a seen set and the model's depth ceiling, so a cycle
	 * that slipped in cannot spin here.
	 *
	 * @return int[]
	 */
	private static function subtree( int $hub_id, array $children_of ): array {
		$found   = [];
		$seen    = [ $hub_id => true ];
		$current = [ $hub_id ];

		for ( $depth = 0; $depth < MHM_Model::MAX_DEPTH && $current; $depth++ ) {
			$next = [];

			foreach ( $current as $parent ) {
				foreach ( $children_of[ $parent ] ?? [] as $child ) {
					$child = (int) $child;

					if ( isset( $seen[ $child ] ) ) {
						continue;
					}

					$seen[ $child ] = true;
					$found[]        = $child;
					$next[]         = $child;
				}
			}

			$current = $next;
		}

		return $found;
	}

	/* --------------------------------------------------- 6. hub health report */

	/**
	 * Direct child counts for every hub at once, as hub ID => count.
	 *
	 * One grouped query replaces one query per hub. Returns null when there is
	 * no $wpdb to ask — the caller then counts hub by hub.
	 */
	public static function child_count_map( string $type ): ?array {
		global $wpdb;

		$key = MHM_Model::meta_key_for_type( $type );

		if ( ! $key || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return null;
		}

		$placeholders = implode( ', ', array_fill( 0, count( MHM_Model::POST_TYPES ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and generated placeholders.
				"SELECT pm.meta_value AS hub, COUNT(*) AS total
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND p.post_type IN ( {$placeholders} )
				 GROUP BY pm.meta_value",
				// phpcs:enable
				array_merge( [ $key ], MHM_Model::POST_TYPES )
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ absint( $row->hub ) ] = (int) $row->total;
		}

		return $map;
	}

	/**
	 * One row per hub: parent, depth, direct children, and — when asked —
	 * the stale/unlinked comparison that the per-hub scanner shows.
	 *
	 * @param array $args type, lang, search, stale (bool).
	 * @return array{rows:array,summary:array}
	 */
	public static function hub_health( array $args = [] ): array {
		$hubs = MHM_Model::get_hubs(
			[
				'type'   => isset( $args['type'] ) ? (string) $args['type'] : '',
				'lang'   => isset( $args['lang'] ) ? (string) $args['lang'] : '',
				'search' => isset( $args['search'] ) ? (string) $args['search'] : '',
			]
		);

		// Hubs and their ancestors are read repeatedly below; prime once.
		self::prime( $hubs );

		$with_stale = ! empty( $args['stale'] );
		$counts     = [];
		foreach ( MHM_Model::types() as $one ) {
			$counts[ $one ] = self::child_count_map( $one );
		}

		$rows    = [];
		$titles  = [];
		$summary = [
			'hubs'          => 0,
			'geo'           => 0,
			'theme'         => 0,
			'no_children'   => 0,
			'top_level'     => 0,
			'with_issues'   => 0,
			'stale'         => 0,
			'unassigned'    => 0,
			'stale_scanned' => $with_stale,
		];

		foreach ( $hubs as $hub_id ) {
			$hub_id = (int) $hub_id;
			$post   = MHM_Model::get_eligible_post( $hub_id );
			$type   = MHM_Model::get_hub_type( $hub_id );

			if ( ! $post || null === $type ) {
				continue;
			}

			$children = null === $counts[ $type ]
				? MHM_Model::count_hub_children( $hub_id, $type )
				: (int) ( $counts[ $type ][ $hub_id ] ?? 0 );

			$parent    = MHM_Model::get_primary_hub( $hub_id, $type );
			$ancestors = MHM_Model::get_hub_ancestors( $hub_id, $type );
			$issues    = MHM_Model::get_hub_issues( $hub_id );

			$row = [
				'hub'        => $hub_id,
				'type'       => $type,
				'status'     => $post->post_status,
				'post_type'  => $post->post_type,
				'lang'       => MHM_Model::get_language( $hub_id ),
				'parent'     => $parent,
				'depth'      => count( $ancestors ),
				'children'   => $children,
				'issues'     => $issues,
				'stale'      => null,
				'unassigned' => null,
			];

			if ( $with_stale ) {
				$scan = MHM_Scanner::scan( $hub_id );

				if ( ! is_wp_error( $scan ) ) {
					$reverse           = MHM_Scanner::reverse_report( $hub_id, $type, $scan['linked_ids'] );
					$row['stale']      = count( $reverse['assigned_not_linked'] );
					$row['unassigned'] = count( $reverse['linked_not_assigned'] );

					$summary['stale']      += $row['stale'];
					$summary['unassigned'] += $row['unassigned'];
				}
			}

			$summary['hubs']++;
			$summary[ $type ]++;

			if ( ! $children ) {
				$summary['no_children']++;
			}
			if ( null === $parent ) {
				$summary['top_level']++;
			}
			if ( $issues ) {
				$summary['with_issues']++;
			}

			$titles[ $hub_id ] = (string) get_the_title( $hub_id );
			$rows[]            = $row;
		}

		// Most broken first, then emptiest, then deepest — the rows an editor
		// actually needs to look at float to the top.
		usort(
			$rows,
			static function ( $a, $b ) use ( $titles ) {
				$cmp = count( $b['issues'] ) <=> count( $a['issues'] );
				$cmp = $cmp ?: ( (int) $b['stale'] <=> (int) $a['stale'] );
				$cmp = $cmp ?: ( $a['children'] <=> $b['children'] );

				return $cmp ?: strcasecmp( $titles[ $a['hub'] ] ?? '', $titles[ $b['hub'] ] ?? '' );
			}
		);

		self::flush_caches();

		return [ 'rows' => $rows, 'summary' => $summary ];
	}
}
