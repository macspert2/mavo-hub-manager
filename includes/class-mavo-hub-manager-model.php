<?php
/**
 * Canonical hub model: the only place that reads or writes hub meta.
 *
 * Storage contract (locked for V0):
 *   _mavo_hub_type           on the hub          'geo' | 'theme'
 *   _mavo_primary_geo_hub    on the child        hub post ID
 *   _mavo_primary_theme_hub  on the child        hub post ID
 *
 * These three keys are the whole data model. Nothing is mirrored onto the hub:
 * no child lists, no ancestor arrays, no relationship table, no taxonomy.
 * Reverse lists and hierarchy are always derived at read time.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Model {

	public const META_HUB_TYPE   = '_mavo_hub_type';
	public const META_GEO_HUB    = '_mavo_primary_geo_hub';
	public const META_THEME_HUB  = '_mavo_primary_theme_hub';

	/** Post types allowed to be hubs or children. */
	public const POST_TYPES = [ 'post', 'page' ];

	/** Defensive ceiling for hierarchy walks. */
	public const MAX_DEPTH = 20;

	/** The two hub types. A hub is exactly one of them, or is not a hub. */
	public static function types(): array {
		return [ 'geo', 'theme' ];
	}

	public static function is_valid_type( string $type ): bool {
		return in_array( $type, self::types(), true );
	}

	/** Human label for a hub type. */
	public static function type_label( string $type ): string {
		return 'geo' === $type
			? __( 'Geographic', 'mavo-hub-manager' )
			: ( 'theme' === $type ? __( 'Thematic', 'mavo-hub-manager' ) : $type );
	}

	/** Child-side meta key that stores the primary hub of the given type. */
	public static function meta_key_for_type( string $type ): ?string {
		if ( 'geo' === $type ) {
			return self::META_GEO_HUB;
		}
		if ( 'theme' === $type ) {
			return self::META_THEME_HUB;
		}

		return null;
	}

	/* ---------------------------------------------------------------- posts */

	/** A post/page that exists and may take part in the hub model. */
	public static function get_eligible_post( int $post_id ): ?WP_Post {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		return in_array( $post->post_type, self::POST_TYPES, true ) ? $post : null;
	}

	/* ----------------------------------------------------------- hub marking */

	public static function is_hub( int $post_id ): bool {
		return null !== self::get_hub_type( $post_id );
	}

	/** 'geo', 'theme', or null. Any other stored value is treated as absent. */
	public static function get_hub_type( int $post_id ): ?string {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return null;
		}

		$type = get_post_meta( $post_id, self::META_HUB_TYPE, true );
		$type = is_string( $type ) ? $type : '';

		return self::is_valid_type( $type ) ? $type : null;
	}

	/**
	 * Mark or re-mark a post/page as a hub.
	 *
	 * Changing the type invalidates every child relationship that used the old
	 * type, so the caller must pass $confirm = true once it has seen the count.
	 *
	 * @return array|WP_Error [ 'old_type', 'new_type', 'removed' ] on success.
	 */
	public static function set_hub_type( int $post_id, string $type, bool $confirm = false ) {
		$post = self::get_eligible_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'mhm_invalid_post', __( 'That post or page does not exist, or is not a post/page.', 'mavo-hub-manager' ) );
		}
		if ( ! self::is_valid_type( $type ) ) {
			return new WP_Error( 'mhm_invalid_type', __( 'Hub type must be geographic or thematic.', 'mavo-hub-manager' ) );
		}

		$post_id  = (int) $post->ID;
		$old_type = self::get_hub_type( $post_id );

		if ( $old_type === $type ) {
			return [ 'old_type' => $old_type, 'new_type' => $type, 'removed' => 0 ];
		}

		$removed = 0;

		if ( null !== $old_type ) {
			// The old type's children can no longer point here: their meta key
			// would claim a hub type this post no longer has.
			$children = self::get_hub_children( $post_id, $old_type );

			if ( $children && ! $confirm ) {
				return new WP_Error(
					'mhm_confirm_required',
					sprintf(
						/* translators: 1: child count, 2: old hub type, 3: new hub type */
						__( 'This hub has %1$d child relationship(s) as a %2$s hub. Changing it to %3$s will remove them.', 'mavo-hub-manager' ),
						count( $children ),
						self::type_label( $old_type ),
						self::type_label( $type )
					),
					[ 'children' => count( $children ) ]
				);
			}

			foreach ( $children as $child_id ) {
				if ( self::remove_primary_hub( (int) $child_id, $old_type ) ) {
					$removed++;
				}
			}
		}

		update_post_meta( $post_id, self::META_HUB_TYPE, $type );

		/**
		 * Fires after a post/page gains or changes its hub type.
		 *
		 * @param int         $hub_id
		 * @param string|null $old_type
		 * @param string|null $new_type
		 */
		do_action( 'mavo_hub_type_changed', $post_id, $old_type, $type );

		return [ 'old_type' => $old_type, 'new_type' => $type, 'removed' => $removed ];
	}

	/**
	 * Unmark a hub, first clearing the child relationships that pointed at it.
	 *
	 * @return array|WP_Error [ 'old_type', 'removed' ] on success.
	 */
	public static function remove_hub_type( int $post_id, bool $confirm = false ) {
		$post_id  = absint( $post_id );
		$old_type = self::get_hub_type( $post_id );

		if ( null === $old_type ) {
			return new WP_Error( 'mhm_not_a_hub', __( 'That post or page is not a hub.', 'mavo-hub-manager' ) );
		}

		$children = self::get_hub_children( $post_id, $old_type );

		if ( $children && ! $confirm ) {
			return new WP_Error(
				'mhm_confirm_required',
				sprintf(
					/* translators: %d: child count */
					__( 'This hub has %d child relationship(s). Unmarking it will remove them.', 'mavo-hub-manager' ),
					count( $children )
				),
				[ 'children' => count( $children ) ]
			);
		}

		$removed = 0;
		foreach ( $children as $child_id ) {
			if ( self::remove_primary_hub( (int) $child_id, $old_type ) ) {
				$removed++;
			}
		}

		delete_post_meta( $post_id, self::META_HUB_TYPE );

		do_action( 'mavo_hub_type_changed', $post_id, $old_type, null );

		return [ 'old_type' => $old_type, 'removed' => $removed ];
	}

	/* -------------------------------------------------------- relationships */

	/** The raw stored primary hub ID for a type — may be stale; validate before use. */
	public static function get_primary_hub( int $post_id, string $type ): ?int {
		$post_id = absint( $post_id );
		$key     = self::meta_key_for_type( $type );

		if ( ! $post_id || ! $key ) {
			return null;
		}

		$hub_id = absint( get_post_meta( $post_id, $key, true ) );

		return $hub_id ?: null;
	}

	/**
	 * Canonical validation for a relationship write. Every mutation path —
	 * scanner, manual editor, conflict move, external API caller — goes
	 * through this before touching meta.
	 *
	 * Language is deliberately NOT enforced here: cross-language links are a
	 * diagnostic, and a deliberate manual assignment stays possible. Automatic
	 * paths must consult self::is_same_language() themselves.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_relationship( int $child_id, int $hub_id, string $type ) {
		if ( ! self::is_valid_type( $type ) ) {
			return new WP_Error( 'mhm_invalid_type', __( 'Unknown hub type.', 'mavo-hub-manager' ) );
		}

		$child = self::get_eligible_post( $child_id );
		if ( ! $child ) {
			return new WP_Error( 'mhm_invalid_child', __( 'The child must be an existing post or page.', 'mavo-hub-manager' ) );
		}

		$hub = self::get_eligible_post( $hub_id );
		if ( ! $hub ) {
			return new WP_Error( 'mhm_invalid_hub', __( 'The target hub must be an existing post or page.', 'mavo-hub-manager' ) );
		}

		if ( (int) $child->ID === (int) $hub->ID ) {
			return new WP_Error( 'mhm_self_reference', __( 'A post cannot be its own primary hub.', 'mavo-hub-manager' ) );
		}

		$hub_type = self::get_hub_type( (int) $hub->ID );
		if ( $hub_type !== $type ) {
			return new WP_Error(
				'mhm_wrong_hub_type',
				sprintf(
					/* translators: %s: hub type label */
					__( 'The target is not a %s hub.', 'mavo-hub-manager' ),
					self::type_label( $type )
				)
			);
		}

		if ( self::would_create_hub_cycle( (int) $child->ID, (int) $hub->ID, $type ) ) {
			return new WP_Error( 'mhm_cycle', __( 'That assignment would create a loop in the hub hierarchy.', 'mavo-hub-manager' ) );
		}

		return true;
	}

	/**
	 * Write a primary hub relationship onto the child.
	 *
	 * @return true|WP_Error
	 */
	public static function set_primary_hub( int $post_id, int $hub_id, string $type ) {
		// Re-query and re-validate at write time: IDs may have come from a form.
		$valid = self::validate_relationship( $post_id, $hub_id, $type );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$post_id = absint( $post_id );
		$hub_id  = absint( $hub_id );
		$key     = self::meta_key_for_type( $type );
		$old     = self::get_primary_hub( $post_id, $type );

		if ( $old === $hub_id ) {
			return true;
		}

		update_post_meta( $post_id, $key, $hub_id );

		/**
		 * Fires after a child's primary hub of one type changes.
		 *
		 * @param int      $child_id
		 * @param string   $type
		 * @param int|null $old_hub_id
		 * @param int|null $new_hub_id
		 */
		do_action( 'mavo_hub_relationship_changed', $post_id, $type, $old, $hub_id );

		return true;
	}

	/** Delete a child's primary hub of one type. True when one was removed. */
	public static function remove_primary_hub( int $post_id, string $type ): bool {
		$post_id = absint( $post_id );
		$key     = self::meta_key_for_type( $type );

		if ( ! $post_id || ! $key ) {
			return false;
		}

		$old = self::get_primary_hub( $post_id, $type );
		if ( null === $old && '' === (string) get_post_meta( $post_id, $key, true ) ) {
			return false;
		}

		delete_post_meta( $post_id, $key );

		do_action( 'mavo_hub_relationship_changed', $post_id, $type, $old, null );

		return true;
	}

	/* ------------------------------------------------------------ hierarchy */

	/**
	 * Ancestors for one type, nearest first, derived by following each object's
	 * immediate primary hub. Stops on self-reference, an already seen ID, a
	 * missing/ineligible target, a target with the wrong hub type, or MAX_DEPTH.
	 */
	public static function get_hub_ancestors( int $post_id, string $type ): array {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! self::is_valid_type( $type ) ) {
			return [];
		}

		$ancestors = [];
		$seen      = [ $post_id => true ];
		$current   = $post_id;

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			$parent = self::get_primary_hub( $current, $type );

			if ( null === $parent || isset( $seen[ $parent ] ) ) {
				break; // End of chain, self-reference, or cycle.
			}
			if ( ! self::get_eligible_post( $parent ) || self::get_hub_type( $parent ) !== $type ) {
				break; // Dangling or wrong-type link: not a usable ancestor.
			}

			$ancestors[]     = $parent;
			$seen[ $parent ] = true;
			$current         = $parent;
		}

		return $ancestors;
	}

	/**
	 * Does the same-type chain starting at $post_id close on itself?
	 * Used by diagnostics; assignments are guarded by would_create_hub_cycle().
	 */
	public static function has_cycle( int $post_id, string $type ): bool {
		$post_id = absint( $post_id );
		if ( ! $post_id || ! self::is_valid_type( $type ) ) {
			return false;
		}

		$seen    = [ $post_id => true ];
		$current = $post_id;

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			$parent = self::get_primary_hub( $current, $type );

			if ( null === $parent ) {
				return false;
			}
			if ( isset( $seen[ $parent ] ) ) {
				return true;
			}

			$seen[ $parent ] = true;
			$current         = $parent;
		}

		return true; // Depth ceiling hit: treat as a loop rather than walk forever.
	}

	/**
	 * Would child → hub close a loop? True when the hub is the child itself or
	 * reaches the child again by following its own same-type primary hubs.
	 */
	public static function would_create_hub_cycle( int $child_id, int $proposed_hub_id, string $type ): bool {
		$child_id = absint( $child_id );
		$hub_id   = absint( $proposed_hub_id );

		if ( ! $child_id || ! $hub_id || ! self::is_valid_type( $type ) ) {
			return false;
		}
		if ( $child_id === $hub_id ) {
			return true;
		}

		$seen    = [ $hub_id => true ];
		$current = $hub_id;

		for ( $depth = 0; $depth < self::MAX_DEPTH; $depth++ ) {
			$parent = self::get_primary_hub( $current, $type );

			if ( null === $parent ) {
				return false;
			}
			if ( $parent === $child_id ) {
				return true;
			}
			if ( isset( $seen[ $parent ] ) ) {
				return true; // The proposed branch is already broken; refuse to add to it.
			}

			$seen[ $parent ] = true;
			$current         = $parent;
		}

		return true;
	}

	/* -------------------------------------------------------------- queries */

	/**
	 * Direct children of a hub: posts/pages whose own primary meta for this
	 * type equals the hub ID. Derived every time — never stored, never cached
	 * onto the hub.
	 *
	 * @return int[] Child post IDs.
	 */
	public static function get_hub_children( int $hub_id, string $type, array $args = [] ): array {
		$hub_id = absint( $hub_id );
		$key    = self::meta_key_for_type( $type );

		if ( ! $hub_id || ! $key ) {
			return [];
		}

		$query_args = array_merge(
			[
				'post_type'              => self::POST_TYPES,
				'post_status'            => 'any',
				'meta_key'               => $key,
				'meta_value'             => (string) $hub_id,
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'orderby'                => 'title',
				'order'                  => 'ASC',
			],
			$args
		);

		$query = new WP_Query( $query_args );

		return array_map( 'absint', (array) $query->posts );
	}

	public static function count_hub_children( int $hub_id, string $type ): int {
		return count( self::get_hub_children( $hub_id, $type ) );
	}

	/**
	 * The hub registry: every post/page carrying a valid _mavo_hub_type.
	 *
	 * @param array $filters [ 'type' => 'geo'|'theme'|'', 'lang' => slug|'', 'search' => string ]
	 * @return int[] Hub post IDs.
	 */
	public static function get_hubs( array $filters = [] ): array {
		$type   = isset( $filters['type'] ) && self::is_valid_type( (string) $filters['type'] ) ? (string) $filters['type'] : '';
		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';

		$args = [
			'post_type'              => self::POST_TYPES,
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'orderby'                => 'title',
			'order'                  => 'ASC',
		];

		if ( $type ) {
			$args['meta_key']   = self::META_HUB_TYPE;
			$args['meta_value'] = $type;
		} else {
			$args['meta_query'] = [
				[
					'key'     => self::META_HUB_TYPE,
					'value'   => self::types(),
					'compare' => 'IN',
				],
			];
		}

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		// Polylang filters queries by the admin language by default; the hub
		// registry must show every language, and filter in PHP instead.
		$args['lang'] = '';

		$hubs = array_map( 'absint', (array) ( new WP_Query( $args ) )->posts );

		$lang = isset( $filters['lang'] ) ? (string) $filters['lang'] : '';
		if ( '' !== $lang ) {
			$hubs = array_values(
				array_filter(
					$hubs,
					static fn( $id ) => self::get_language( (int) $id ) === $lang
				)
			);
		}

		return $hubs;
	}

	/* ---------------------------------------------------------- multilingual */

	/** Is Polylang available in this install? */
	public static function has_polylang(): bool {
		return function_exists( 'pll_get_post_language' );
	}

	/** Language slug of a post/page, or '' when Polylang is absent/unknown. */
	public static function get_language( int $post_id ): string {
		if ( ! self::has_polylang() ) {
			return '';
		}

		$lang = pll_get_post_language( absint( $post_id ), 'slug' );

		return is_string( $lang ) ? $lang : '';
	}

	/** Languages present across the hub registry, for the admin filter. */
	public static function get_hub_languages(): array {
		if ( ! self::has_polylang() ) {
			return [];
		}

		$langs = [];
		foreach ( self::get_hubs() as $hub_id ) {
			$lang = self::get_language( (int) $hub_id );
			if ( '' !== $lang ) {
				$langs[ $lang ] = true;
			}
		}

		$langs = array_keys( $langs );
		sort( $langs );

		return $langs;
	}

	/**
	 * Same-language check used by every automatic path. With Polylang absent,
	 * or a language missing on either side, everything counts as same-language
	 * so a monolingual install behaves normally.
	 */
	public static function is_same_language( int $a, int $b ): bool {
		if ( ! self::has_polylang() ) {
			return true;
		}

		$lang_a = self::get_language( $a );
		$lang_b = self::get_language( $b );

		if ( '' === $lang_a || '' === $lang_b ) {
			return true;
		}

		return $lang_a === $lang_b;
	}

	/* ---------------------------------------------------------- diagnostics */

	/**
	 * Health of one hub's own position in the hierarchy, for the detail panel.
	 *
	 * @return array<int, array{level:string,message:string}>
	 */
	public static function get_hub_issues( int $hub_id ): array {
		$issues = [];
		$type   = self::get_hub_type( $hub_id );

		if ( null === $type ) {
			return $issues;
		}

		foreach ( self::types() as $rel_type ) {
			$parent = self::get_primary_hub( $hub_id, $rel_type );
			if ( null === $parent ) {
				continue;
			}

			$label = self::type_label( $rel_type );

			if ( $parent === absint( $hub_id ) ) {
				$issues[] = [
					'level'   => 'error',
					'message' => sprintf( __( 'Self-reference: this hub is its own %s primary hub.', 'mavo-hub-manager' ), $label ),
				];
				continue;
			}

			if ( ! self::get_eligible_post( $parent ) ) {
				$issues[] = [
					'level'   => 'error',
					/* translators: 1: hub type label, 2: post ID */
					'message' => sprintf( __( 'Invalid primary hub: the %1$s primary hub (#%2$d) no longer exists.', 'mavo-hub-manager' ), $label, $parent ),
				];
				continue;
			}

			if ( self::get_hub_type( $parent ) !== $rel_type ) {
				$issues[] = [
					'level'   => 'error',
					/* translators: 1: post ID, 2: hub type label */
					'message' => sprintf( __( 'Primary hub has wrong hub type: #%1$d is not a %2$s hub.', 'mavo-hub-manager' ), $parent, $label ),
				];
			}

			if ( ! self::is_same_language( $hub_id, $parent ) ) {
				$issues[] = [
					'level'   => 'warning',
					/* translators: 1: hub type label, 2: language slug */
					'message' => sprintf( __( 'Primary hub is in another Polylang language: the %1$s primary hub is %2$s.', 'mavo-hub-manager' ), $label, self::get_language( $parent ) ),
				];
			}

			if ( self::has_cycle( $hub_id, $rel_type ) ) {
				$issues[] = [
					'level'   => 'error',
					/* translators: %s: hub type label */
					'message' => sprintf( __( 'Cycle detected in the %s hierarchy above this hub.', 'mavo-hub-manager' ), $label ),
				];
			}
		}

		return $issues;
	}

	/**
	 * Site-wide relationship diagnostics. Deliberately on demand only: it
	 * touches every post carrying a primary-hub meta value.
	 *
	 * @return array<string, array<int, array{child:int,type:string,hub:int,message:string}>>
	 */
	public static function run_diagnostics(): array {
		$report = [
			'missing_target'   => [],
			'wrong_hub_type'   => [],
			'cross_language'   => [],
			'self_reference'   => [],
			'cycle'            => [],
			'scanned'          => 0,
		];

		foreach ( self::types() as $type ) {
			$key = self::meta_key_for_type( $type );

			$children = ( new WP_Query( [
				'post_type'              => self::POST_TYPES,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'lang'                   => '',
				'meta_query'             => [
					[
						'key'     => $key,
						'compare' => 'EXISTS',
					],
				],
			] ) )->posts;

			foreach ( $children as $child_id ) {
				$child_id = absint( $child_id );
				$hub_id   = self::get_primary_hub( $child_id, $type );

				$report['scanned']++;

				if ( null === $hub_id ) {
					continue;
				}

				$row = [ 'child' => $child_id, 'type' => $type, 'hub' => $hub_id ];

				if ( $child_id === $hub_id ) {
					$report['self_reference'][] = $row;
					continue;
				}

				if ( ! self::get_eligible_post( $hub_id ) ) {
					$report['missing_target'][] = $row;
					continue;
				}

				if ( self::get_hub_type( $hub_id ) !== $type ) {
					$report['wrong_hub_type'][] = $row;
				}

				if ( ! self::is_same_language( $child_id, $hub_id ) ) {
					$report['cross_language'][] = $row;
				}

				if ( self::has_cycle( $child_id, $type ) ) {
					$report['cycle'][] = $row;
				}
			}
		}

		return $report;
	}
}
