<?php
/**
 * Tools → Hub Audit.
 *
 * Site-wide, read-only reports over the hub model. Every report runs only when
 * asked for: opening a tab runs that tab's query and nothing else, and no audit
 * ever writes a relationship. The single mutating action here — removing one
 * broken stored relationship — goes through admin-post.php with a capability
 * check, a nonce and MHM_Model validation, exactly like the Hub Manager page.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Audit_Admin {

	public const CAPABILITY = MHM_Admin::CAPABILITY;
	public const PAGE_SLUG  = 'mavo-hub-audit';
	public const ACTION     = 'mavo_hub_audit_action';

	/** Tab => label. */
	public static function tabs(): array {
		return [
			'missing'    => __( 'Posts without a hub', 'mavo-hub-manager' ),
			'candidates' => __( 'Hub candidates', 'mavo-hub-manager' ),
			'linkback'   => __( 'No link back to hub', 'mavo-hub-manager' ),
			'health'     => __( 'Hub health', 'mavo-hub-manager' ),
			'errors'     => __( 'Relationship errors', 'mavo-hub-manager' ),
		];
	}

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_post' ] );
	}

	public static function register_page(): void {
		add_management_page(
			__( 'Hub Audit', 'mavo-hub-manager' ),
			__( 'Hub Audit', 'mavo-hub-manager' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function page_url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::PAGE_SLUG ], array_filter( $args, static fn( $v ) => '' !== $v && null !== $v ) ),
			admin_url( 'tools.php' )
		);
	}

	/* ---------------------------------------------------------- POST action */

	/**
	 * The page's own POST tasks.
	 *
	 * Only one of them touches a relationship (removing a broken one). The
	 * candidate scan writes nothing but its own cached tally, and it is a POST
	 * so that reloading the page never re-runs it.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage hubs.', 'mavo-hub-manager' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : 'remove_relationship';

		if ( 'scan_candidates' === $task || 'reset_candidates' === $task ) {
			self::task_candidates( 'reset_candidates' === $task );
			self::redirect_back();
		}

		$child = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! $child || ! MHM_Model::is_valid_type( $type ) ) {
			MHM_Admin::add_notice( 'error', __( 'Nothing to remove: unknown post or hub type.', 'mavo-hub-manager' ) );
		} else {
			$old = MHM_Model::get_primary_hub( $child, $type );

			if ( MHM_Model::remove_primary_hub( $child, $type ) ) {
				MHM_Admin::add_notice(
					'success',
					sprintf(
						/* translators: 1: hub type label, 2: post title, 3: previous hub ID */
						__( 'Removed the %1$s primary hub of "%2$s" (was #%3$d).', 'mavo-hub-manager' ),
						MHM_Model::type_label( $type ),
						get_the_title( $child ),
						(int) $old
					)
				);
			} else {
				MHM_Admin::add_notice( 'warning', __( 'There was no such assignment to remove.', 'mavo-hub-manager' ) );
			}
		}

		self::redirect_back();
	}

	/** Back to the tab and filters the form was submitted from. */
	private static function redirect_back(): void {
		$redirect = [];

		foreach ( [ 'tab', 'mode', 'ltype', 'htype', 'lang', 'ptype', 'status', 'stale', 'paged', 'offset', 's', 'sort', 'run' ] as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) {
				$redirect[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		wp_safe_redirect( self::page_url( $redirect ) );
		exit;
	}

	/**
	 * Scan one more batch of candidates, or throw the tally away.
	 *
	 * The tally is a cache: a transient of this user's, an hour long, holding
	 * post IDs and link counts. No post meta is involved.
	 */
	private static function task_candidates( bool $reset ): void {
		if ( $reset ) {
			delete_transient( self::candidates_key() );
			MHM_Admin::add_notice( 'success', __( 'The candidate scan was cleared.', 'mavo-hub-manager' ) );

			return;
		}

		$args = [
			'lang'      => isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '',
			'post_type' => isset( $_POST['ptype'] ) ? sanitize_key( wp_unslash( $_POST['ptype'] ) ) : 'any',
			'status'    => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish',
		];

		$state = MHM_Audit::scan_candidates( $args, self::stored_candidates() );

		set_transient( self::candidates_key(), $state, HOUR_IN_SECONDS );

		MHM_Admin::add_notice(
			'success',
			sprintf(
				/* translators: 1: posts scanned so far, 2: posts to scan, 3: candidates found */
				__( 'Scanned %1$d of %2$d posts and pages; %3$d link somewhere internally.', 'mavo-hub-manager' ),
				(int) $state['scanned'],
				(int) $state['total'],
				count( (array) $state['counts'] )
			)
		);
	}

	private static function candidates_key(): string {
		return 'mhm_candidates_' . get_current_user_id();
	}

	/** The cached tally, or an empty state. */
	private static function stored_candidates(): array {
		$state = get_transient( self::candidates_key() );

		return is_array( $state ) ? $state : [];
	}

	/* -------------------------------------------------------------- context */

	/** Current filters, sanitised once and carried through every link and form. */
	private static function context(): array {
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'missing';

		return [
			'tab'    => isset( $tabs[ $tab ] ) ? $tab : 'missing',
			'mode'   => isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'geo',
			'ltype'  => isset( $_GET['ltype'] ) && 'theme' === $_GET['ltype'] ? 'theme' : 'geo',
			'htype'  => isset( $_GET['htype'] ) ? sanitize_key( wp_unslash( $_GET['htype'] ) ) : '',
			'lang'   => isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '',
			'ptype'  => isset( $_GET['ptype'] ) ? sanitize_key( wp_unslash( $_GET['ptype'] ) ) : 'any',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'publish',
			'stale'  => ! empty( $_GET['stale'] ) ? '1' : '',
			'paged'  => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
			'offset' => isset( $_GET['offset'] ) ? max( 0, absint( wp_unslash( $_GET['offset'] ) ) ) : 0,
			's'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'sort'   => isset( $_GET['sort'] ) && 'date' === $_GET['sort'] ? 'date' : 'views',
			'run'    => ! empty( $_GET['run'] ) ? '1' : '',
		];
	}

	/** Every Polylang language on the site, or [] without Polylang. */
	private static function languages(): array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return [];
		}

		$langs = pll_languages_list( [ 'fields' => 'slug' ] );

		return is_array( $langs ) ? $langs : [];
	}

	private static function statuses(): array {
		return [
			'publish' => __( 'Published', 'mavo-hub-manager' ),
			'draft'   => __( 'Draft', 'mavo-hub-manager' ),
			'pending' => __( 'Pending', 'mavo-hub-manager' ),
			'future'  => __( 'Scheduled', 'mavo-hub-manager' ),
			'private' => __( 'Private', 'mavo-hub-manager' ),
			'any'     => __( 'Any status', 'mavo-hub-manager' ),
		];
	}

	/* ----------------------------------------------------------------- page */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage hubs.', 'mavo-hub-manager' ), 403 );
		}

		$context = self::context();

		echo '<div class="wrap mhm-wrap">';
		echo '<h1>' . esc_html__( 'Hub Audit', 'mavo-hub-manager' ) . '</h1>';

		MHM_Admin::render_notices();

		echo '<p class="description">' . esc_html__( 'Site-wide reports over the hub relationships. Read-only: nothing here assigns, repairs or deletes a relationship unless you click a button that says so.', 'mavo-hub-manager' ) . ' ';
		printf(
			'<a href="%s">%s</a></p>',
			esc_url( MHM_Admin::page_url() ),
			esc_html__( 'Go to Hub Manager', 'mavo-hub-manager' )
		);

		echo '<h2 class="nav-tab-wrapper mhm-tabs">';
		foreach ( self::tabs() as $slug => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$slug === $context['tab'] ? ' nav-tab-active' : '',
				esc_url( self::page_url( [ 'tab' => $slug ] ) ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $context['tab'] ) {
			case 'candidates':
				self::render_candidates( $context );
				break;

			case 'linkback':
				self::render_linkback( $context );
				break;

			case 'health':
				self::render_health( $context );
				break;

			case 'errors':
				self::render_errors( $context );
				break;

			default:
				self::render_missing( $context );
		}

		echo '</div>';
	}

	/* -------------------------------------------------------------- filters */

	/** Open a GET filter form that keeps the current tab. */
	private static function open_filters( array $context ): void {
		echo '<form method="get" class="mhm-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $context['tab'] ) . '" />';
	}

	private static function select( string $name, array $options, string $current, string $label ): void {
		printf( '<label class="mhm-field"><span class="screen-reader-text">%s</span><select name="%s">', esc_html( $label ), esc_attr( $name ) );
		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( (string) $option_label )
			);
		}
		echo '</select></label> ';
	}

	private static function language_select( array $context ): void {
		$languages = self::languages();
		if ( ! $languages ) {
			return;
		}

		$options = [ '' => __( 'All languages', 'mavo-hub-manager' ) ];
		foreach ( $languages as $lang ) {
			$options[ $lang ] = $lang;
		}

		self::select( 'lang', $options, $context['lang'], __( 'Language', 'mavo-hub-manager' ) );
	}

	/**
	 * Ordering. "Most viewed" joins the view counter, so it can only list posts
	 * that have one — the reason the date order exists.
	 */
	private static function sort_select( array $context ): void {
		self::select(
			'sort',
			[
				'views' => __( 'Most viewed first', 'mavo-hub-manager' ),
				'date'  => __( 'Newest first (includes posts with no view counter)', 'mavo-hub-manager' ),
			],
			$context['sort'],
			__( 'Sort', 'mavo-hub-manager' )
		);
	}

	/** What the report on screen actually cost, so a slow one is visible. */
	private static function render_cost( float $started, int $queries_before ): void {
		$ms = ( microtime( true ) - $started ) * 1000;

		$queries = function_exists( 'get_num_queries' ) ? get_num_queries() - $queries_before : 0;

		printf(
			'<p class="mhm-cost mhm-muted">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: milliseconds, 2: number of database queries */
					__( 'Report built in %1$d ms and %2$d database queries.', 'mavo-hub-manager' ),
					(int) round( $ms ),
					(int) $queries
				)
			)
		);
	}

	private static function post_type_select( array $context ): void {
		self::select(
			'ptype',
			[
				'any'  => __( 'Posts and pages', 'mavo-hub-manager' ),
				'post' => __( 'Posts only', 'mavo-hub-manager' ),
				'page' => __( 'Pages only', 'mavo-hub-manager' ),
			],
			$context['ptype'],
			__( 'Post type', 'mavo-hub-manager' )
		);
	}

	private static function close_filters( array $context ): void {
		echo '<button type="submit" class="button">' . esc_html__( 'Apply', 'mavo-hub-manager' ) . '</button> ';
		echo '<a class="button-link" href="' . esc_url( self::page_url( [ 'tab' => $context['tab'] ] ) ) . '">' . esc_html__( 'Reset', 'mavo-hub-manager' ) . '</a>';
		echo '</form>';
	}

	/* ------------------------------------------------- 1. posts without a hub */

	private static function render_missing( array $context ): void {
		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Posts without a hub', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: views meta key */
				__( 'Posts and pages with no primary hub of the chosen kind. "Most viewed first" orders by the "%s" meta key and therefore only lists posts that have one — switch to "Newest first" to see the rest. Hubs are hidden, because a top-level hub legitimately has no parent.', 'mavo-hub-manager' ),
				MHM_Audit::views_meta_key()
			)
		) . '</p>';

		self::open_filters( $context );
		self::select(
			'mode',
			[
				'geo'   => __( 'Missing geographic hub', 'mavo-hub-manager' ),
				'theme' => __( 'Missing thematic hub', 'mavo-hub-manager' ),
				'both'  => __( 'Missing both hubs', 'mavo-hub-manager' ),
			],
			$context['mode'],
			__( 'What is missing', 'mavo-hub-manager' )
		);
		self::language_select( $context );
		self::post_type_select( $context );
		self::select( 'status', self::statuses(), $context['status'], __( 'Status', 'mavo-hub-manager' ) );
		self::sort_select( $context );
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s" /> ',
			esc_attr( $context['s'] ),
			esc_attr__( 'Search titles…', 'mavo-hub-manager' )
		);
		self::close_filters( $context );

		$started = microtime( true );
		$queries = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;

		$report = MHM_Audit::missing_hub(
			[
				'mode'      => $context['mode'],
				'sort'      => $context['sort'],
				'lang'      => $context['lang'],
				'post_type' => $context['ptype'],
				'status'    => $context['status'],
				'paged'     => $context['paged'],
				'search'    => $context['s'],
			]
		);

		// No total: counting every matching row across the site is the part
		// that does not scale, so the report pages with prev/next instead.
		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: first row number, 2: last row number */
					__( 'Showing posts %1$d–%2$d with no hub.', 'mavo-hub-manager' ),
					$report['rows'] ? ( ( $report['paged'] - 1 ) * $report['per_page'] ) + 1 : 0,
					( ( $report['paged'] - 1 ) * $report['per_page'] ) + count( $report['rows'] )
				)
			)
		);

		if ( ! $report['rows'] ) {
			echo '<p class="mhm-muted">' . esc_html__( 'Nothing to show. Either everything is assigned, or the filters are too narrow.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped mhm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Post type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th class="mhm-col-num">' . esc_html__( 'Views', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Primary geographic hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Primary thematic hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report['rows'] as $post_id ) {
			$post = MHM_Model::get_eligible_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$views = MHM_Audit::get_views( (int) $post_id );

			echo '<tr>';
			echo '<td>' . (int) $post_id . '</td>';
			echo '<td>' . MHM_Admin::post_link( (int) $post_id ) . ' ' . MHM_Admin::type_badge( MHM_Model::get_hub_type( (int) $post_id ) ) . '</td>';
			echo '<td>' . esc_html( $post->post_type ) . '</td>';
			echo '<td>' . esc_html( $post->post_status ) . '</td>';
			echo '<td>' . MHM_Admin::lang_cell( (int) $post_id ) . '</td>';
			echo '<td class="mhm-col-num">' . ( null === $views ? '<span class="mhm-muted">—</span>' : esc_html( number_format_i18n( $views ) ) ) . '</td>';
			echo '<td>' . MHM_Admin::post_link( MHM_Model::get_primary_hub( (int) $post_id, 'geo' ) ) . '</td>';
			echo '<td>' . MHM_Admin::post_link( MHM_Model::get_primary_hub( (int) $post_id, 'theme' ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( (string) get_edit_post_link( (int) $post_id ) ) . '">' . esc_html__( 'Edit', 'mavo-hub-manager' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( (string) get_permalink( (int) $post_id ) ) . '">' . esc_html__( 'View', 'mavo-hub-manager' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_pagination( $context, (int) $report['paged'], (bool) $report['has_more'] );
		self::render_cost( $started, $queries );

		echo '<p class="description">' . esc_html__( 'To assign hubs, open the hub in Tools → Hub Manager and use its link scanner or its manual "Add child" search. Assignment is never done from this report.', 'mavo-hub-manager' ) . '</p>';
		echo '</div>';
	}

	private static function render_pagination( array $context, int $paged, bool $has_more ): void {
		if ( 1 === $paged && ! $has_more ) {
			return;
		}

		$link = static function ( int $page ) use ( $context ): string {
			return self::page_url( array_merge( $context, [ 'paged' => $page ] ) );
		};

		echo '<p class="mhm-pagination">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( $link( $paged - 1 ) ) . '">' . esc_html__( '‹ Previous', 'mavo-hub-manager' ) . '</a> ';
		}
		printf(
			'<span class="mhm-muted">%s</span> ',
			esc_html(
				sprintf(
					/* translators: %d: current page */
					__( 'Page %d', 'mavo-hub-manager' ),
					$paged
				)
			)
		);
		if ( $has_more ) {
			echo '<a class="button" href="' . esc_url( $link( $paged + 1 ) ) . '">' . esc_html__( 'Next ›', 'mavo-hub-manager' ) . '</a>';
		}
		echo '</p>';
	}

	/* ------------------------------------------------------ 2. hub candidates */

	private static function render_candidates( array $context ): void {
		$state = self::stored_candidates();

		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Hub candidates', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Posts and pages that are not marked as a hub, ranked by how many distinct internal links their own content contains. A page that points at thirty others is doing a hub\'s job already.', 'mavo-hub-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The count is taken from the stored content without resolving links, so a link to a category, a tag or an attachment counts too, and links produced by shortcodes do not. It ranks candidates; the hub scanner is what decides which links become children once the page is marked.', 'mavo-hub-manager' ) . '</p>';

		self::open_filters( $context );
		self::language_select( $context );
		self::post_type_select( $context );
		self::select( 'status', self::statuses(), $context['status'], __( 'Status', 'mavo-hub-manager' ) );
		self::close_filters( $context );

		$signature = MHM_Audit::candidates_signature(
			[
				'lang'      => $context['lang'],
				'post_type' => $context['ptype'],
				'status'    => $context['status'],
			]
		);

		// Changing a filter invalidates the tally rather than mixing two scans.
		$stale_filters = $state && ( $state['signature'] ?? '' ) !== $signature;
		if ( $stale_filters ) {
			$state = [];
		}

		self::render_candidates_controls( $context, $state );

		if ( ! $state ) {
			echo '<p>' . esc_html(
				$stale_filters
					? __( 'These filters have not been scanned yet. Reading post content is the expensive part, so the scan runs one batch at a time, on your click.', 'mavo-hub-manager' )
					: __( 'Nothing scanned yet. Reading post content is the expensive part, so the scan runs one batch at a time, on your click.', 'mavo-hub-manager' )
			) . '</p></div>';

			return;
		}

		$report = MHM_Audit::candidates_rows( $state, (int) $context['paged'] );

		if ( ! $report['rows'] ) {
			echo '<p class="mhm-muted">' . esc_html__( 'No post scanned so far links anywhere internally.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped mhm-table"><thead><tr>';
		echo '<th class="mhm-col-num">' . esc_html__( '#', 'mavo-hub-manager' ) . '</th>';
		echo '<th class="mhm-col-num">' . esc_html__( 'Internal links', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Post type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th class="mhm-col-num">' . esc_html__( 'Views', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Mark as hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report['rows'] as $row ) {
			$post_id = (int) $row['post'];

			echo '<tr>';
			echo '<td class="mhm-col-num">' . (int) $row['rank'] . '</td>';
			echo '<td class="mhm-col-num"><strong>' . (int) $row['links'] . '</strong></td>';
			echo '<td>' . MHM_Admin::post_link( $post_id ) . '</td>';
			echo '<td>' . esc_html( (string) $row['post_type'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . MHM_Admin::lang_cell( $post_id ) . '</td>';
			echo '<td class="mhm-col-num">' . ( null === $row['views'] ? '<span class="mhm-muted">—</span>' : esc_html( number_format_i18n( (int) $row['views'] ) ) ) . '</td>';
			echo '<td class="mhm-actions">';
			self::render_mark_form( $post_id, 'geo' );
			self::render_mark_form( $post_id, 'theme' );
			echo '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '">' . esc_html__( 'Edit', 'mavo-hub-manager' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">' . esc_html__( 'View', 'mavo-hub-manager' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_pagination( $context, (int) $report['paged'], (bool) $report['has_more'] );

		echo '<p class="description">' . esc_html__( 'Marking a page as a hub takes you to Tools → Hub Manager with it selected, where its links can be scanned and its children assigned.', 'mavo-hub-manager' ) . '</p>';
		echo '</div>';
	}

	/** Scan progress and the two buttons that drive it. */
	private static function render_candidates_controls( array $context, array $state ): void {
		$scanned = (int) ( $state['scanned'] ?? 0 );
		$total   = (int) ( $state['total'] ?? 0 );
		$found   = isset( $state['counts'] ) ? count( (array) $state['counts'] ) : 0;
		$done    = ! empty( $state['done'] );

		if ( $state ) {
			printf(
				'<p><strong>%s</strong> %s</p>',
				esc_html(
					sprintf(
						/* translators: 1: posts scanned, 2: posts to scan */
						__( 'Scanned %1$d of %2$d posts and pages.', 'mavo-hub-manager' ),
						$scanned,
						$total
					)
				),
				esc_html(
					$done
						? sprintf(
							/* translators: %d: number of candidates */
							__( 'The scan is complete, so this ranking now covers the whole site: %d candidates link somewhere internally.', 'mavo-hub-manager' ),
							$found
						)
						: sprintf(
							/* translators: %d: number of candidates */
							__( '%d candidates so far — the ranking covers what has been scanned, not yet the whole site.', 'mavo-hub-manager' ),
							$found
						)
				)
			);
		}

		echo '<p class="mhm-actions">';

		if ( ! $done ) {
			self::render_task_form(
				'scan_candidates',
				$context,
				$state
					? sprintf(
						/* translators: %d: batch size */
						__( 'Scan next %d ›', 'mavo-hub-manager' ),
						MHM_Audit::DEFAULT_CANDIDATE_BATCH
					)
					: sprintf(
						/* translators: %d: batch size */
						__( 'Scan the first %d', 'mavo-hub-manager' ),
						MHM_Audit::DEFAULT_CANDIDATE_BATCH
					),
				'button button-primary'
			);
		}

		if ( $state ) {
			self::render_task_form( 'reset_candidates', $context, __( 'Start over', 'mavo-hub-manager' ), 'button' );
		}

		echo '</p>';

		if ( $state && ! $done ) {
			echo '<p class="description">' . esc_html__( 'The tally is kept as a cache of yours for an hour — post IDs and link counts, nothing in post meta. Changing a filter starts a new scan.', 'mavo-hub-manager' ) . '</p>';
		}
	}

	/** A one-button form for one of this page's own tasks. */
	private static function render_task_form( string $task, array $context, string $label, string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-inline-form">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="' . esc_attr( $task ) . '" />';

		foreach ( $context as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $key ), esc_attr( (string) $value ) );
		}

		printf( '<button type="submit" class="%s">%s</button>', esc_attr( $class ), esc_html( $label ) );
		echo '</form>';
	}

	/**
	 * Mark a candidate as a hub.
	 *
	 * Posts to the Hub Manager's own handler, so marking goes through exactly
	 * one code path — including its confirmation when the page already has
	 * children — and lands on the manager page with the hub selected.
	 */
	private static function render_mark_form( int $post_id, string $type ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-inline-form">';
		wp_nonce_field( MHM_Admin::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( MHM_Admin::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="mark_hub" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
		echo '<input type="hidden" name="hub" value="' . esc_attr( (string) $post_id ) . '" />';
		echo '<input type="hidden" name="hub_type" value="' . esc_attr( $type ) . '" />';

		printf(
			'<button type="submit" class="button button-small">%s</button>',
			esc_html( 'geo' === $type ? __( 'Geographic', 'mavo-hub-manager' ) : __( 'Thematic', 'mavo-hub-manager' ) )
		);
		echo '</form>';
	}

	/* --------------------------------------------- 3. no link back to the hub */

	private static function render_linkback( array $context ): void {
		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Children with no link back to their hub', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A child whose primary hub is set but whose own content never points at that hub. Both an <a href> and a link-back shortcode count as a link: a post carrying [mavo_hub_strip] — with a slug, with a {geo:…} or {theme:…} marker, or bare, in which case it follows the post\'s own primary hubs — does not appear here.', 'mavo-hub-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( '[geo_related] counts too: its block leads with the post\'s own hub cards, so a post carrying it is treated as linking back to both of its primary hubs. It is a recommendation block rather than a fixed link, so that is an editorial decision, not a guarantee the hub card is on the page.', 'mavo-hub-manager' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'This report reads post content, so it works through the assigned children one batch at a time. Links are matched against the hub\'s own permalink, so the check itself costs no database queries.', 'mavo-hub-manager' ) . '</p>';

		self::open_filters( $context );
		self::select(
			'ltype',
			[
				'geo'   => __( 'Geographic relationships', 'mavo-hub-manager' ),
				'theme' => __( 'Thematic relationships', 'mavo-hub-manager' ),
			],
			$context['ltype'],
			__( 'Relationship type', 'mavo-hub-manager' )
		);
		self::language_select( $context );
		self::post_type_select( $context );
		self::select( 'status', self::statuses(), $context['status'], __( 'Status', 'mavo-hub-manager' ) );
		self::sort_select( $context );
		self::close_filters( $context );

		$started = microtime( true );
		$queries = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;

		$report = MHM_Audit::no_link_back(
			[
				'type'      => $context['ltype'],
				'sort'      => $context['sort'],
				'lang'      => $context['lang'],
				'post_type' => $context['ptype'],
				'status'    => $context['status'],
				'offset'    => $context['offset'],
			]
		);

		$first = $report['scanned'] ? $report['offset'] + 1 : $report['offset'];

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of relationships with no link back */
					_n( '%d relationship with no link back in this batch.', '%d relationships with no link back in this batch.', count( $report['rows'] ), 'mavo-hub-manager' ),
					count( $report['rows'] )
				)
			),
			esc_html(
				sprintf(
					/* translators: 1: first child, 2: last child, 3: number that already link back */
					__( 'Checked children %1$d–%2$d; %3$d of them already link back.', 'mavo-hub-manager' ),
					$first,
					$report['offset'] + $report['scanned'],
					$report['linked']
				)
			)
		);

		if ( $report['rows'] ) {
			echo '<table class="widefat striped mhm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Child', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
			echo '<th class="mhm-col-num">' . esc_html__( 'Views', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Relationship', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Primary hub', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'State', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Suggested shortcode', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'mavo-hub-manager' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $report['rows'] as $row ) {
				$child = (int) $row['child'];
				$hub   = (int) $row['hub'];

				echo '<tr>';
				echo '<td>' . MHM_Admin::post_link( $child ) . '</td>';
				echo '<td>' . MHM_Admin::lang_cell( $child ) . '</td>';
				echo '<td class="mhm-col-num">' . ( null === $row['views'] ? '<span class="mhm-muted">—</span>' : esc_html( number_format_i18n( (int) $row['views'] ) ) ) . '</td>';
				echo '<td>' . MHM_Admin::type_badge( (string) $row['type'] ) . '</td>';
				echo '<td>' . MHM_Admin::post_link( $hub ) . ' ' . MHM_Admin::lang_cell( $hub ) . '</td>';
				echo '<td>' . self::linkback_state( $row ) . '</td>';
				echo '<td>' . self::shortcode_hint( $hub, (string) $row['type'], (bool) $row['missing'] ) . '</td>';
				echo '<td><a class="button button-small" href="' . esc_url( (string) get_edit_post_link( $child ) ) . '">' . esc_html__( 'Edit child', 'mavo-hub-manager' ) . '</a></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p class="mhm-muted">' . esc_html__( 'Every child in this batch links back to its hub.', 'mavo-hub-manager' ) . '</p>';
		}

		self::render_batch_nav( $context, $report );
		self::render_cost( $started, $queries );

		echo '<p class="description">' . esc_html__( 'Nothing is changed by this report. A missing link back is an editorial gap, not a broken relationship: the stored primary hub stays the source of truth.', 'mavo-hub-manager' ) . '</p>';
		echo '</div>';
	}

	private static function linkback_state( array $row ): string {
		if ( ! empty( $row['missing'] ) ) {
			return '<span class="mhm-state mhm-state-conflict"><span aria-hidden="true">×</span> ' . esc_html__( 'Hub missing', 'mavo-hub-manager' ) . '</span>';
		}

		if ( MHM_Audit::LINK_ANCESTOR === $row['via'] ) {
			return '<span class="mhm-state mhm-state-crosslang"><span aria-hidden="true">↗</span> ' . esc_html__( 'Ancestor only', 'mavo-hub-manager' ) . '</span> '
				. MHM_Admin::post_link( (int) $row['ancestor'] );
		}

		return '<span class="mhm-state mhm-state-conflict"><span aria-hidden="true">○</span> ' . esc_html__( 'No link back', 'mavo-hub-manager' ) . '</span>';
	}

	/**
	 * A ready-to-paste [mavo_hub_strip] for this hub.
	 *
	 * The relationship is already stored, so the snippet names the hub type
	 * rather than a slug: the strip follows the child's own primary hub, and
	 * keeps following it if the hub is later reassigned. The hub title is only
	 * a starting anchor — the editor is expected to reword it.
	 */
	private static function shortcode_hint( int $hub_id, string $type, bool $missing ): string {
		if ( $missing || ! MHM_Model::is_valid_type( $type ) ) {
			return '<span class="mhm-muted">—</span>';
		}

		$title = trim( (string) get_the_title( $hub_id ) );
		if ( '' === $title ) {
			return '<span class="mhm-muted">—</span>';
		}

		$snippet = sprintf( '[mavo_hub_strip text="{%s:%s}"]', $type, $title );

		return '<code class="mhm-snippet">' . esc_html( $snippet ) . '</code>';
	}

	private static function render_batch_nav( array $context, array $report ): void {
		$batch = (int) $report['batch'];
		$next  = (int) $report['offset'] + (int) $report['scanned'];

		echo '<p class="mhm-pagination">';

		if ( $report['offset'] > 0 ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( self::page_url( array_merge( $context, [ 'offset' => max( 0, (int) $report['offset'] - $batch ) ] ) ) ),
				esc_html__( '‹ Previous batch', 'mavo-hub-manager' )
			);
		}

		if ( ! empty( $report['has_more'] ) ) {
			printf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url( self::page_url( array_merge( $context, [ 'offset' => $next ] ) ) ),
				esc_html(
					sprintf(
						/* translators: %d: batch size */
						__( 'Check next %d ›', 'mavo-hub-manager' ),
						$batch
					)
				)
			);
		} else {
			echo '<span class="mhm-muted">' . esc_html__( 'End of the list.', 'mavo-hub-manager' ) . '</span>';
		}

		echo '</p>';
	}

	/* ------------------------------------------------------- 4. hub health */

	private static function render_health( array $context ): void {
		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Hub health', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Every hub with its derived child count, its own parent hub and any hierarchy problem. Hubs with problems, then hubs with the fewest children, come first.', 'mavo-hub-manager' ) . '</p>';

		self::open_filters( $context );
		self::select(
			'htype',
			[
				''      => __( 'All hub types', 'mavo-hub-manager' ),
				'geo'   => __( 'Geographic', 'mavo-hub-manager' ),
				'theme' => __( 'Thematic', 'mavo-hub-manager' ),
			],
			$context['htype'],
			__( 'Hub type', 'mavo-hub-manager' )
		);
		self::language_select( $context );
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s" /> ',
			esc_attr( $context['s'] ),
			esc_attr__( 'Filter hubs…', 'mavo-hub-manager' )
		);
		printf(
			'<label class="mhm-field"><input type="checkbox" name="stale" value="1" %s /> %s</label> ',
			checked( $context['stale'], '1', false ),
			esc_html__( 'Include link analysis — reads and resolves the links in every hub, so it is slow on many hubs', 'mavo-hub-manager' )
		);
		self::close_filters( $context );

		$started = microtime( true );
		$queries = function_exists( 'get_num_queries' ) ? get_num_queries() : 0;

		$report  = MHM_Audit::hub_health(
			[
				'type'   => $context['htype'],
				'lang'   => $context['lang'],
				'search' => $context['s'],
				'stale'  => '1' === $context['stale'],
			]
		);
		$summary = $report['summary'];

		echo '<ul class="mhm-summary">';
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Hubs', 'mavo-hub-manager' ), (int) $summary['hubs'] );
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Geographic', 'mavo-hub-manager' ), (int) $summary['geo'] );
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Thematic', 'mavo-hub-manager' ), (int) $summary['theme'] );
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Without children', 'mavo-hub-manager' ), (int) $summary['no_children'] );
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Top-level', 'mavo-hub-manager' ), (int) $summary['top_level'] );
		printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'With problems', 'mavo-hub-manager' ), (int) $summary['with_issues'] );
		if ( $summary['stale_scanned'] ) {
			printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Stale assignments', 'mavo-hub-manager' ), (int) $summary['stale'] );
			printf( '<li>%s <span class="mhm-count">%d</span></li>', esc_html__( 'Linked but unassigned', 'mavo-hub-manager' ), (int) $summary['unassigned'] );
		}
		echo '</ul>';

		if ( ! $report['rows'] ) {
			echo '<p class="mhm-muted">' . esc_html__( 'No hubs match these filters.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped mhm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Hub type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Parent hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th class="mhm-col-num">' . esc_html__( 'Depth', 'mavo-hub-manager' ) . '</th>';
		echo '<th class="mhm-col-num">' . esc_html__( 'Children', 'mavo-hub-manager' ) . '</th>';
		if ( $summary['stale_scanned'] ) {
			echo '<th class="mhm-col-num">' . esc_html__( 'Stale', 'mavo-hub-manager' ) . '</th>';
			echo '<th class="mhm-col-num">' . esc_html__( 'Linked, unassigned', 'mavo-hub-manager' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Problems', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $report['rows'] as $row ) {
			$hub_id = (int) $row['hub'];

			echo '<tr>';
			echo '<td>' . MHM_Admin::post_link( $hub_id ) . '</td>';
			echo '<td>' . MHM_Admin::type_badge( (string) $row['type'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . MHM_Admin::lang_cell( $hub_id ) . '</td>';
			echo '<td>' . ( $row['parent'] ? MHM_Admin::post_link( (int) $row['parent'] ) : '<span class="mhm-muted">' . esc_html__( 'top-level', 'mavo-hub-manager' ) . '</span>' ) . '</td>';
			echo '<td class="mhm-col-num">' . (int) $row['depth'] . '</td>';
			echo '<td class="mhm-col-num">' . ( $row['children'] ? (int) $row['children'] : '<span class="mhm-warning-text">0</span>' ) . '</td>';

			if ( $summary['stale_scanned'] ) {
				echo '<td class="mhm-col-num">' . ( null === $row['stale'] ? '<span class="mhm-muted">—</span>' : (int) $row['stale'] ) . '</td>';
				echo '<td class="mhm-col-num">' . ( null === $row['unassigned'] ? '<span class="mhm-muted">—</span>' : (int) $row['unassigned'] ) . '</td>';
			}

			echo '<td>';
			if ( $row['issues'] ) {
				echo '<ul class="mhm-issues">';
				foreach ( $row['issues'] as $issue ) {
					printf(
						'<li class="%s">%s</li>',
						'error' === $issue['level'] ? 'mhm-error-text' : 'mhm-warning-text',
						esc_html( $issue['message'] )
					);
				}
				echo '</ul>';
			} else {
				echo '<span class="mhm-muted">' . esc_html__( 'None', 'mavo-hub-manager' ) . '</span>';
			}
			echo '</td>';

			echo '<td><a class="button button-small" href="' . esc_url( MHM_Admin::page_url( [ 'hub' => $hub_id ] ) ) . '">' . esc_html__( 'Manage', 'mavo-hub-manager' ) . '</a> ';
			echo '<a class="button button-small" href="' . esc_url( MHM_Admin::page_url( [ 'hub' => $hub_id, 'scan' => 1 ] ) ) . '">' . esc_html__( 'Scan', 'mavo-hub-manager' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_cost( $started, $queries );
		echo '</div>';
	}

	/* ---------------------------------------------- 5. relationship errors */

	private static function render_errors( array $context ): void {
		$run = '1' === $context['run'];

		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Relationship errors', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'On demand only: inspects every post and page carrying a primary-hub value. Nothing is repaired automatically.', 'mavo-hub-manager' ) . '</p>';

		printf(
			'<p><a class="button button-secondary" href="%s">%s</a></p>',
			esc_url( self::page_url( array_merge( $context, [ 'run' => 1 ] ) ) ),
			esc_html__( 'Run relationship diagnostics', 'mavo-hub-manager' )
		);

		if ( ! $run ) {
			echo '</div>';

			return;
		}

		$report = MHM_Model::run_diagnostics();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of relationships inspected */
					__( '%d stored relationship(s) inspected.', 'mavo-hub-manager' ),
					$report['scanned']
				)
			)
		);

		$groups = [
			'missing_target' => __( 'Primary hub points to a missing object', 'mavo-hub-manager' ),
			'wrong_hub_type' => __( 'Primary hub has the wrong hub type', 'mavo-hub-manager' ),
			'self_reference' => __( 'Self-references', 'mavo-hub-manager' ),
			'cycle'          => __( 'Cycles', 'mavo-hub-manager' ),
			'cross_language' => __( 'Cross-language relationships', 'mavo-hub-manager' ),
		];

		foreach ( $groups as $key => $label ) {
			$rows = $report[ $key ];

			printf( '<h3>%s <span class="mhm-count">%d</span></h3>', esc_html( $label ), count( $rows ) );

			if ( ! $rows ) {
				echo '<p class="mhm-muted">' . esc_html__( 'None.', 'mavo-hub-manager' ) . '</p>';
				continue;
			}

			echo '<table class="widefat striped mhm-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Child', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Relationship', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Stored hub', 'mavo-hub-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'mavo-hub-manager' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				echo '<tr>';
				echo '<td>' . MHM_Admin::post_link( (int) $row['child'] ) . ' ' . MHM_Admin::lang_cell( (int) $row['child'] ) . '</td>';
				echo '<td>' . MHM_Admin::type_badge( (string) $row['type'] ) . '</td>';
				echo '<td>' . MHM_Admin::post_link( (int) $row['hub'] ) . ' ' . MHM_Admin::lang_cell( (int) $row['hub'] ) . '</td>';
				echo '<td>';
				self::render_remove_form( (int) $row['child'], (string) $row['type'], $context );
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/** One-button POST form removing a single stored relationship. */
	private static function render_remove_form( int $child_id, string $type, array $context ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-inline-form">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="child" value="' . esc_attr( (string) $child_id ) . '" />';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '" />';

		foreach ( $context as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $key ), esc_attr( (string) $value ) );
		}

		printf(
			'<button type="submit" class="button button-small button-link-delete" data-mhm-confirm="%s">%s</button>',
			esc_attr__( 'Remove this stored relationship?', 'mavo-hub-manager' ),
			esc_html__( 'Remove relationship', 'mavo-hub-manager' )
		);
		echo '</form>';
	}
}
