<?php
/**
 * Tools → Hub Manager.
 *
 * Every mutation goes through admin-post.php: capability check, nonce, absint,
 * re-query and re-validate through MHM_Model, then wp_safe_redirect() back to
 * the page so a reload never repeats the action.
 *
 * Opening this page never writes a relationship. Assignment is always a click.
 */

defined( 'ABSPATH' ) || exit;

class MHM_Admin {

	public const CAPABILITY = 'manage_options';
	public const PAGE_SLUG  = 'mavo-hub-manager';
	public const ACTION     = 'mavo_hub_manager_action';

	/** Scan result for the selected hub, computed once per request on demand. */
	private static ?array $scan = null;

	/** Row-action forms buffered while rendering inside another form. */
	private static array $deferred_forms = [];

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_post' ] );
	}

	public static function register_page(): void {
		add_management_page(
			__( 'Hub Manager', 'mavo-hub-manager' ),
			__( 'Hub Manager', 'mavo-hub-manager' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue( string $hook ): void {
		$hooks = [ 'tools_page_' . self::PAGE_SLUG, 'tools_page_' . MHM_Audit_Admin::PAGE_SLUG ];

		if ( ! in_array( $hook, $hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'mavo-hub-manager', MHM_PLUGIN_URL . 'assets/admin.css', [], MHM_VERSION );
		wp_enqueue_script( 'mavo-hub-manager', MHM_PLUGIN_URL . 'assets/admin.js', [], MHM_VERSION, true );

		wp_localize_script(
			'mavo-hub-manager',
			'mavoHubManager',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => MHM_Ajax::ACTION,
				'nonce'   => wp_create_nonce( MHM_Ajax::NONCE ),
				'i18n'    => [
					'searching' => __( 'Searching…', 'mavo-hub-manager' ),
					'noResults' => __( 'No matching post or page.', 'mavo-hub-manager' ),
					'error'     => __( 'The search failed. Please try again.', 'mavo-hub-manager' ),
					'select'    => __( 'Select', 'mavo-hub-manager' ),
					'assign'    => __( 'Assign as child', 'mavo-hub-manager' ),
					'conflict'  => __( 'has another primary hub', 'mavo-hub-manager' ),
					'noHub'     => __( 'not a hub', 'mavo-hub-manager' ),
				],
			]
		);
	}

	/* ------------------------------------------------------------- plumbing */

	/** URL of the Hub Manager page with extra query args. */
	public static function page_url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::PAGE_SLUG ], $args ),
			admin_url( 'tools.php' )
		);
	}

	private static function notice_key(): string {
		return 'mhm_notices_' . get_current_user_id();
	}

	private static function pending_key(): string {
		return 'mhm_pending_' . get_current_user_id();
	}

	/** Public so the audit page can queue notices for the same rendering. */
	public static function add_notice( string $type, string $message ): void {
		$notices   = self::stored_notices();
		$notices[] = [ 'type' => $type, 'message' => $message ];
		set_transient( self::notice_key(), $notices, 5 * MINUTE_IN_SECONDS );
	}

	/** Queued notices, always an array — a missing transient returns false. */
	private static function stored_notices(): array {
		$notices = get_transient( self::notice_key() );

		return is_array( $notices ) ? $notices : [];
	}

	private static function take_notices(): array {
		$notices = self::stored_notices();
		delete_transient( self::notice_key() );

		return $notices;
	}

	/** Park a destructive action until the admin confirms it on the next load. */
	private static function set_pending( string $message, array $fields, string $button ): void {
		set_transient(
			self::pending_key(),
			[ 'message' => $message, 'fields' => $fields, 'button' => $button ],
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function take_pending(): ?array {
		$pending = get_transient( self::pending_key() );
		delete_transient( self::pending_key() );

		return is_array( $pending ) ? $pending : null;
	}

	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[mavo-hub-manager] ' . $message );
		}
	}

	/* --------------------------------------------------------- POST routing */

	public static function handle_post(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage hubs.', 'mavo-hub-manager' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$task    = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$hub_id  = isset( $_POST['hub'] ) ? absint( wp_unslash( $_POST['hub'] ) ) : 0;
		$confirm = ! empty( $_POST['mhm_confirm'] );

		$redirect = [];
		if ( $hub_id ) {
			$redirect['hub'] = $hub_id;
		}
		foreach ( [ 'htype', 'hlang' ] as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$redirect[ $key ] = sanitize_key( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( ! empty( $_POST['hs'] ) ) {
			$redirect['hs'] = sanitize_text_field( wp_unslash( $_POST['hs'] ) );
		}

		switch ( $task ) {
			case 'mark_hub':
				$redirect = self::task_mark_hub( $confirm, $redirect );
				break;

			case 'unmark_hub':
				$redirect = self::task_unmark_hub( $confirm, $redirect );
				break;

			case 'assign_children':
				$redirect = self::task_assign_children( $hub_id, $redirect );
				break;

			case 'add_child':
				$redirect = self::task_add_child( $hub_id, $confirm, $redirect );
				break;

			case 'move_child':
				$redirect = self::task_move_child( $hub_id, $confirm, $redirect );
				break;

			case 'remove_child':
				$redirect = self::task_remove_child( $confirm, $redirect );
				break;

			default:
				self::add_notice( 'error', __( 'Unknown action.', 'mavo-hub-manager' ) );
		}

		wp_safe_redirect( self::page_url( $redirect ) );
		exit;
	}

	/* ----------------------------------------------------------- POST tasks */

	private static function task_mark_hub( bool $confirm, array $redirect ): array {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$type    = isset( $_POST['hub_type'] ) ? sanitize_key( wp_unslash( $_POST['hub_type'] ) ) : '';

		$result = MHM_Model::set_hub_type( $post_id, $type, $confirm );

		if ( is_wp_error( $result ) ) {
			if ( 'mhm_confirm_required' === $result->get_error_code() ) {
				self::set_pending(
					$result->get_error_message() . ' ' . __( 'The affected children will lose their primary hub.', 'mavo-hub-manager' ),
					[ 'task' => 'mark_hub', 'post_id' => $post_id, 'hub_type' => $type ],
					__( 'Yes, change the hub type and remove those relationships', 'mavo-hub-manager' )
				);
			} else {
				self::add_notice( 'error', $result->get_error_message() );
			}

			return $redirect;
		}

		$title = get_the_title( $post_id );

		if ( null === $result['old_type'] ) {
			self::add_notice(
				'success',
				sprintf(
					/* translators: 1: post title, 2: hub type label */
					__( '"%1$s" is now a %2$s hub.', 'mavo-hub-manager' ),
					$title,
					MHM_Model::type_label( $result['new_type'] )
				)
			);
		} else {
			self::add_notice(
				'success',
				sprintf(
					/* translators: 1: post title, 2: old type, 3: new type */
					__( '"%1$s" changed from %2$s to %3$s.', 'mavo-hub-manager' ),
					$title,
					MHM_Model::type_label( $result['old_type'] ),
					MHM_Model::type_label( $result['new_type'] )
				)
			);

			if ( $result['removed'] ) {
				self::add_notice(
					'warning',
					sprintf(
						/* translators: 1: count, 2: old type label */
						__( '%1$d invalid %2$s child relationship(s) were removed.', 'mavo-hub-manager' ),
						$result['removed'],
						MHM_Model::type_label( $result['old_type'] )
					)
				);
			}
		}

		self::log( sprintf( 'hub type %d: %s → %s (%d relationships removed)', $post_id, (string) $result['old_type'], $result['new_type'], $result['removed'] ) );

		$redirect['hub'] = $post_id;

		return $redirect;
	}

	private static function task_unmark_hub( bool $confirm, array $redirect ): array {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$result  = MHM_Model::remove_hub_type( $post_id, $confirm );

		if ( is_wp_error( $result ) ) {
			if ( 'mhm_confirm_required' === $result->get_error_code() ) {
				self::set_pending(
					$result->get_error_message(),
					[ 'task' => 'unmark_hub', 'post_id' => $post_id ],
					__( 'Yes, unmark this hub and remove those relationships', 'mavo-hub-manager' )
				);
			} else {
				self::add_notice( 'error', $result->get_error_message() );
			}

			return $redirect;
		}

		self::add_notice(
			'success',
			sprintf(
				/* translators: 1: post title, 2: removed relationship count */
				__( '"%1$s" is no longer a hub. %2$d child relationship(s) were removed.', 'mavo-hub-manager' ),
				get_the_title( $post_id ),
				$result['removed']
			)
		);

		unset( $redirect['hub'] );

		return $redirect;
	}

	/**
	 * Batch assign from the scanner. Every checked target is re-classified here
	 * before anything is written, so a stale form can never overwrite a primary
	 * hub that appeared in the meantime.
	 */
	private static function task_assign_children( int $hub_id, array $redirect ): array {
		$type = MHM_Model::get_hub_type( $hub_id );

		if ( null === $type ) {
			self::add_notice( 'error', __( 'The selected hub is no longer a hub.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		$targets = isset( $_POST['targets'] ) ? (array) wp_unslash( $_POST['targets'] ) : [];
		$targets = array_filter( array_map( 'absint', $targets ) );

		if ( ! $targets ) {
			self::add_notice( 'warning', __( 'No linked posts were selected.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		$assigned = 0;
		$conflict = 0;
		$cross    = 0;
		$skipped  = 0;

		foreach ( $targets as $target_id ) {
			$row = MHM_Scanner::classify( $target_id, $hub_id, $type );

			if ( MHM_Scanner::CONFLICT === $row['state'] ) {
				$conflict++;
				continue;
			}
			if ( MHM_Scanner::CROSS_LANGUAGE === $row['state'] ) {
				$cross++;
				continue;
			}
			if ( MHM_Scanner::UNASSIGNED !== $row['state'] ) {
				$skipped++;
				continue;
			}

			$result = MHM_Model::set_primary_hub( $target_id, $hub_id, $type );

			if ( is_wp_error( $result ) ) {
				$skipped++;
				self::log( sprintf( 'assign %d → %d (%s) refused: %s', $target_id, $hub_id, $type, $result->get_error_message() ) );
				continue;
			}

			$assigned++;
		}

		$hub_title = get_the_title( $hub_id );

		self::add_notice(
			'success',
			sprintf(
				/* translators: 1: count, 2: hub title */
				_n( '%1$d post assigned to "%2$s".', '%1$d posts assigned to "%2$s".', $assigned, 'mavo-hub-manager' ),
				$assigned,
				$hub_title
			)
		);

		if ( $conflict ) {
			self::add_notice(
				'warning',
				sprintf(
					/* translators: %d: count */
					_n( '%d conflict was left unchanged.', '%d conflicts were left unchanged.', $conflict, 'mavo-hub-manager' ),
					$conflict
				)
			);
		}
		if ( $cross ) {
			self::add_notice(
				'warning',
				sprintf(
					/* translators: %d: count */
					_n( '%d cross-language link was ignored.', '%d cross-language links were ignored.', $cross, 'mavo-hub-manager' ),
					$cross
				)
			);
		}
		if ( $skipped ) {
			self::add_notice(
				'warning',
				sprintf(
					/* translators: %d: count */
					_n( '%d target was skipped as invalid.', '%d targets were skipped as invalid.', $skipped, 'mavo-hub-manager' ),
					$skipped
				)
			);
		}

		$redirect['scan'] = 1;

		return $redirect;
	}

	/** Manual child assignment, independent of any internal link. */
	private static function task_add_child( int $hub_id, bool $confirm, array $redirect ): array {
		$child_id = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$type     = MHM_Model::get_hub_type( $hub_id );

		if ( null === $type ) {
			self::add_notice( 'error', __( 'The selected hub is no longer a hub.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		$valid = MHM_Model::validate_relationship( $child_id, $hub_id, $type );
		if ( is_wp_error( $valid ) ) {
			self::add_notice( 'error', $valid->get_error_message() );

			return $redirect;
		}

		$current = MHM_Model::get_primary_hub( $child_id, $type );

		if ( null !== $current && $current !== $hub_id && ! $confirm ) {
			self::set_pending(
				sprintf(
					/* translators: 1: child title, 2: current hub title, 3: selected hub title */
					__( '"%1$s" already has "%2$s" as its primary hub for this type. Move it to "%3$s"?', 'mavo-hub-manager' ),
					get_the_title( $child_id ),
					get_the_title( $current ),
					get_the_title( $hub_id )
				),
				[ 'task' => 'add_child', 'hub' => $hub_id, 'child' => $child_id ],
				__( 'Yes, move the primary hub', 'mavo-hub-manager' )
			);

			return $redirect;
		}

		if ( ! MHM_Model::is_same_language( $child_id, $hub_id ) && ! $confirm ) {
			self::set_pending(
				sprintf(
					/* translators: 1: child title, 2: hub title */
					__( '"%1$s" is in a different Polylang language from "%2$s". Assign it anyway?', 'mavo-hub-manager' ),
					get_the_title( $child_id ),
					get_the_title( $hub_id )
				),
				[ 'task' => 'add_child', 'hub' => $hub_id, 'child' => $child_id ],
				__( 'Yes, assign across languages', 'mavo-hub-manager' )
			);

			return $redirect;
		}

		$result = MHM_Model::set_primary_hub( $child_id, $hub_id, $type );

		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', $result->get_error_message() );

			return $redirect;
		}

		self::add_notice(
			'success',
			sprintf(
				/* translators: 1: child title, 2: hub title */
				__( '"%1$s" now has "%2$s" as its primary hub.', 'mavo-hub-manager' ),
				get_the_title( $child_id ),
				get_the_title( $hub_id )
			)
		);

		return $redirect;
	}

	/** Explicit, confirmed overwrite of a conflicting primary hub. */
	private static function task_move_child( int $hub_id, bool $confirm, array $redirect ): array {
		$child_id = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$type     = MHM_Model::get_hub_type( $hub_id );

		if ( null === $type ) {
			self::add_notice( 'error', __( 'The selected hub is no longer a hub.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		if ( ! $confirm ) {
			self::add_notice( 'error', __( 'Moving a primary hub requires confirmation.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		$old    = MHM_Model::get_primary_hub( $child_id, $type );
		$result = MHM_Model::set_primary_hub( $child_id, $hub_id, $type );

		if ( is_wp_error( $result ) ) {
			self::add_notice( 'error', $result->get_error_message() );

			return $redirect;
		}

		self::add_notice(
			'success',
			sprintf(
				/* translators: 1: child title, 2: old hub title, 3: new hub title */
				__( '"%1$s" moved from "%2$s" to "%3$s".', 'mavo-hub-manager' ),
				get_the_title( $child_id ),
				$old ? get_the_title( $old ) : __( '(none)', 'mavo-hub-manager' ),
				get_the_title( $hub_id )
			)
		);

		$redirect['scan'] = 1;

		return $redirect;
	}

	/** Manual removal of one child's primary hub — never automatic. */
	private static function task_remove_child( bool $confirm, array $redirect ): array {
		$child_id = isset( $_POST['child'] ) ? absint( wp_unslash( $_POST['child'] ) ) : 0;
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		if ( ! MHM_Model::is_valid_type( $type ) ) {
			self::add_notice( 'error', __( 'Unknown hub type.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		if ( ! $confirm ) {
			self::add_notice( 'error', __( 'Removing an assignment requires confirmation.', 'mavo-hub-manager' ) );

			return $redirect;
		}

		if ( MHM_Model::remove_primary_hub( $child_id, $type ) ) {
			self::add_notice(
				'success',
				sprintf(
					/* translators: 1: child title, 2: hub type label */
					__( 'Removed the %2$s primary hub of "%1$s".', 'mavo-hub-manager' ),
					get_the_title( $child_id ),
					MHM_Model::type_label( $type )
				)
			);
		} else {
			self::add_notice( 'warning', __( 'There was no such assignment to remove.', 'mavo-hub-manager' ) );
		}

		if ( ! empty( $redirect['hub'] ) && ! empty( $_POST['rescan'] ) ) {
			$redirect['scan'] = 1;
		}

		return $redirect;
	}

	/* -------------------------------------------------------------- display */

	/* The small display helpers below are shared with Tools → Hub Audit. */

	public static function badge( string $text, string $class ): string {
		return '<span class="mhm-badge mhm-badge-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function type_badge( ?string $type ): string {
		if ( null === $type ) {
			return '<span class="mhm-muted">—</span>';
		}

		return self::badge(
			'geo' === $type ? __( 'Geo', 'mavo-hub-manager' ) : __( 'Theme', 'mavo-hub-manager' ),
			'geo' === $type ? 'geo' : 'theme'
		);
	}

	public static function lang_cell( int $post_id ): string {
		if ( ! MHM_Model::has_polylang() ) {
			return '<span class="mhm-muted">—</span>';
		}

		$lang = MHM_Model::get_language( $post_id );

		return $lang ? self::badge( $lang, 'lang' ) : '<span class="mhm-muted">' . esc_html__( 'none', 'mavo-hub-manager' ) . '</span>';
	}

	/** "Title (#12)" with an edit link when the post exists. */
	public static function post_link( ?int $post_id ): string {
		$post_id = absint( (int) $post_id );
		if ( ! $post_id ) {
			return '<span class="mhm-muted">—</span>';
		}

		$post = MHM_Model::get_eligible_post( $post_id );
		if ( ! $post ) {
			return '<span class="mhm-error-text">' . sprintf(
				/* translators: %d: post ID */
				esc_html__( 'missing #%d', 'mavo-hub-manager' ),
				$post_id
			) . '</span>';
		}

		$edit = get_edit_post_link( $post_id );
		$name = get_the_title( $post ) ?: sprintf( __( '(no title) #%d', 'mavo-hub-manager' ), $post_id );

		return $edit
			? '<a href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a> <span class="mhm-muted">#' . (int) $post_id . '</span>'
			: esc_html( $name ) . ' <span class="mhm-muted">#' . (int) $post_id . '</span>';
	}

	private static function state_badge( string $state ): string {
		$map = [
			MHM_Scanner::UNASSIGNED            => [ __( 'Unassigned', 'mavo-hub-manager' ), 'unassigned', '○' ],
			MHM_Scanner::ALREADY_ASSIGNED_HERE => [ __( 'Assigned', 'mavo-hub-manager' ), 'assigned', '✓' ],
			MHM_Scanner::CONFLICT              => [ __( 'Conflict', 'mavo-hub-manager' ), 'conflict', '!' ],
			MHM_Scanner::CROSS_LANGUAGE        => [ __( 'Cross-language', 'mavo-hub-manager' ), 'crosslang', '⇄' ],
			MHM_Scanner::INVALID_TARGET        => [ __( 'Invalid', 'mavo-hub-manager' ), 'invalid', '×' ],
			MHM_Scanner::SELF                  => [ __( 'Self', 'mavo-hub-manager' ), 'invalid', '↺' ],
		];

		[ $label, $class, $icon ] = $map[ $state ] ?? [ $state, 'invalid', '?' ];

		return '<span class="mhm-state mhm-state-' . esc_attr( $class ) . '"><span aria-hidden="true">' . esc_html( $icon ) . '</span> ' . esc_html( $label ) . '</span>';
	}

	/** Hidden fields that carry the current view through a POST + redirect. */
	private static function context_fields( array $context ): void {
		foreach ( [ 'htype', 'hlang', 'hs' ] as $key ) {
			if ( ! empty( $context[ $key ] ) ) {
				printf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( $key ),
					esc_attr( (string) $context[ $key ] )
				);
			}
		}
	}

	/* ----------------------------------------------------------------- page */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage hubs.', 'mavo-hub-manager' ), 403 );
		}

		$context = [
			'htype' => isset( $_GET['htype'] ) ? sanitize_key( wp_unslash( $_GET['htype'] ) ) : '',
			'hlang' => isset( $_GET['hlang'] ) ? sanitize_key( wp_unslash( $_GET['hlang'] ) ) : '',
			'hs'    => isset( $_GET['hs'] ) ? sanitize_text_field( wp_unslash( $_GET['hs'] ) ) : '',
		];

		$selected_id = isset( $_GET['hub'] ) ? absint( wp_unslash( $_GET['hub'] ) ) : 0;
		$selected    = $selected_id && MHM_Model::is_hub( $selected_id ) ? $selected_id : 0;
		$do_scan     = $selected && ! empty( $_GET['scan'] );

		self::$scan = null;
		if ( $do_scan ) {
			$scan = MHM_Scanner::scan( $selected );
			if ( ! is_wp_error( $scan ) ) {
				self::$scan = $scan;
			}
		}

		echo '<div class="wrap mhm-wrap">';
		echo '<h1>' . esc_html__( 'Hub Manager', 'mavo-hub-manager' ) . '</h1>';

		self::render_notices();
		self::render_pending();
		self::render_intro();
		self::render_mark_panel( $context );
		self::render_registry( $selected, $context );

		if ( $selected ) {
			self::render_detail( $selected, $context );
			self::render_scanner( $selected, $context, $do_scan );
			self::render_children( $selected, $context );
			self::render_manual_add( $selected, $context );
		} else {
			echo '<div class="mhm-panel"><p>' . esc_html__( 'Select a hub above to manage its children, scan its internal links and see its hierarchy.', 'mavo-hub-manager' ) . '</p></div>';
		}

		self::render_audit_link();
		self::flush_deferred_forms();

		echo '</div>';
	}

	public static function render_notices(): void {
		foreach ( self::take_notices() as $notice ) {
			$class = 'error' === $notice['type'] ? 'notice-error' : ( 'warning' === $notice['type'] ? 'notice-warning' : 'notice-success' );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( $class ),
				esc_html( $notice['message'] )
			);
		}
	}

	/** The two-step confirmation for destructive actions. */
	private static function render_pending(): void {
		$pending = self::take_pending();
		if ( ! $pending ) {
			return;
		}

		echo '<div class="notice notice-warning mhm-confirm"><p><strong>' . esc_html__( 'Confirmation required', 'mavo-hub-manager' ) . '</strong></p>';
		echo '<p>' . esc_html( $pending['message'] ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="mhm_confirm" value="1" />';

		foreach ( (array) $pending['fields'] as $name => $value ) {
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( (string) $name ),
				esc_attr( (string) $value )
			);
		}

		echo '<p><button type="submit" class="button button-primary">' . esc_html( $pending['button'] ) . '</button> ';
		echo '<a class="button" href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Cancel', 'mavo-hub-manager' ) . '</a></p>';
		echo '</form></div>';
	}

	private static function render_intro(): void {
		echo '<div class="mhm-panel mhm-help">';
		echo '<p><strong>' . esc_html__( 'Primary geographic hub', 'mavo-hub-manager' ) . '</strong> — ' . esc_html__( 'the most immediate geographic editorial hub that owns this content.', 'mavo-hub-manager' ) . '<br />';
		echo '<strong>' . esc_html__( 'Primary thematic hub', 'mavo-hub-manager' ) . '</strong> — ' . esc_html__( 'the most immediate thematic editorial hub that owns this content.', 'mavo-hub-manager' ) . '</p>';
		echo '<p>' . esc_html__( '"Primary" is not "every hub that links to this article" and not "every relevant place or theme". Broader relationships are inferred by walking the hub hierarchy. Each relationship is stored once, on the child; hubs never store child lists.', 'mavo-hub-manager' ) . '</p>';
		echo '<p>' . esc_html__( 'The link scanner reads stored post content only. Links produced by shortcodes are not rendered and therefore not discovered here — add those children manually. (The audit\'s link-back report does understand [mavo_hub_strip], which it reads as text.)', 'mavo-hub-manager' ) . '</p>';
		echo '</div>';
	}

	/* -------------------------------------------------------- mark as a hub */

	private static function render_mark_panel( array $context ): void {
		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Find a post or page to mark as a hub', 'mavo-hub-manager' ) . '</h2>';

		echo '<div class="mhm-search" data-mhm-search="mark">';
		echo '<input type="search" class="regular-text" data-mhm-input placeholder="' . esc_attr__( 'Title or exact ID…', 'mavo-hub-manager' ) . '" />';
		echo '<div class="mhm-search-results" data-mhm-results aria-live="polite"></div>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-mark-form" data-mhm-mark-form hidden>';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="mark_hub" />';
		echo '<input type="hidden" name="post_id" value="" data-mhm-mark-id />';
		self::context_fields( $context );

		echo '<p class="mhm-mark-selected">' . esc_html__( 'Selected:', 'mavo-hub-manager' ) . ' <strong data-mhm-mark-label></strong></p>';
		echo '<p>';
		echo '<button type="submit" class="button button-primary" name="hub_type" value="geo">' . esc_html__( 'Mark as geographic hub', 'mavo-hub-manager' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="hub_type" value="theme">' . esc_html__( 'Mark as thematic hub', 'mavo-hub-manager' ) . '</button> ';
		echo '<button type="button" class="button" data-mhm-mark-cancel>' . esc_html__( 'Cancel', 'mavo-hub-manager' ) . '</button>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Changing the type of a hub that already has children removes those relationships, and asks you to confirm first.', 'mavo-hub-manager' ) . '</p>';
		echo '</form>';

		echo '</div>';
	}

	/* ---------------------------------------------------------- hub registry */

	private static function render_registry( int $selected, array $context ): void {
		$hubs = MHM_Model::get_hubs(
			[
				'type'   => $context['htype'],
				'lang'   => $context['hlang'],
				'search' => $context['hs'],
			]
		);

		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Hub registry', 'mavo-hub-manager' ) . '</h2>';

		// Filters.
		echo '<form method="get" class="mhm-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		if ( $selected ) {
			echo '<input type="hidden" name="hub" value="' . esc_attr( (string) $selected ) . '" />';
		}

		echo '<select name="htype">';
		printf( '<option value="" %s>%s</option>', selected( $context['htype'], '', false ), esc_html__( 'All hub types', 'mavo-hub-manager' ) );
		foreach ( MHM_Model::types() as $type ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $type ),
				selected( $context['htype'], $type, false ),
				esc_html( MHM_Model::type_label( $type ) )
			);
		}
		echo '</select> ';

		$languages = MHM_Model::get_hub_languages();
		if ( $languages ) {
			echo '<select name="hlang">';
			printf( '<option value="" %s>%s</option>', selected( $context['hlang'], '', false ), esc_html__( 'All languages', 'mavo-hub-manager' ) );
			foreach ( $languages as $lang ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $lang ),
					selected( $context['hlang'], $lang, false ),
					esc_html( $lang )
				);
			}
			echo '</select> ';
		}

		echo '<input type="search" name="hs" value="' . esc_attr( $context['hs'] ) . '" placeholder="' . esc_attr__( 'Filter hubs…', 'mavo-hub-manager' ) . '" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'mavo-hub-manager' ) . '</button> ';
		echo '<a class="button-link" href="' . esc_url( self::page_url( $selected ? [ 'hub' => $selected ] : [] ) ) . '">' . esc_html__( 'Reset', 'mavo-hub-manager' ) . '</a>';
		echo '</form>';

		if ( ! $hubs ) {
			echo '<p>' . esc_html__( 'No hubs yet. Use the search above to mark a post or page as a hub.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped mhm-table">';
		echo '<thead><tr>';
		echo '<th class="mhm-col-select">' . esc_html__( 'Select', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'ID', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Post type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Hub type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Primary geographic hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Primary thematic hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Direct children', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Edit', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'View', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $hubs as $hub_id ) {
			$hub_id = (int) $hub_id;
			$post   = MHM_Model::get_eligible_post( $hub_id );
			if ( ! $post ) {
				continue;
			}

			$type       = MHM_Model::get_hub_type( $hub_id );
			$select_url = self::page_url( array_filter( array_merge( $context, [ 'hub' => $hub_id ] ) ) );

			echo '<tr' . ( $hub_id === $selected ? ' class="mhm-row-selected"' : '' ) . '>';
			echo '<td><a class="button button-small" href="' . esc_url( $select_url ) . '">' . ( $hub_id === $selected ? esc_html__( 'Selected', 'mavo-hub-manager' ) : esc_html__( 'Select', 'mavo-hub-manager' ) ) . '</a></td>';
			echo '<td>' . (int) $hub_id . '</td>';
			echo '<td>' . self::post_link( $hub_id ) . '</td>';
			echo '<td>' . esc_html( $post->post_type ) . '</td>';
			echo '<td>' . esc_html( $post->post_status ) . '</td>';
			echo '<td>' . self::lang_cell( $hub_id ) . '</td>';
			echo '<td>' . self::type_badge( $type ) . '</td>';
			echo '<td>' . self::post_link( MHM_Model::get_primary_hub( $hub_id, 'geo' ) ) . '</td>';
			echo '<td>' . self::post_link( MHM_Model::get_primary_hub( $hub_id, 'theme' ) ) . '</td>';
			echo '<td>' . (int) MHM_Model::count_hub_children( $hub_id, (string) $type ) . '</td>';
			echo '<td><a href="' . esc_url( (string) get_edit_post_link( $hub_id ) ) . '">' . esc_html__( 'Edit', 'mavo-hub-manager' ) . '</a></td>';
			echo '<td><a href="' . esc_url( (string) get_permalink( $hub_id ) ) . '">' . esc_html__( 'View', 'mavo-hub-manager' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/* ------------------------------------------------------ selected hub */

	private static function render_detail( int $hub_id, array $context ): void {
		$post = MHM_Model::get_eligible_post( $hub_id );
		$type = MHM_Model::get_hub_type( $hub_id );

		if ( ! $post || null === $type ) {
			return;
		}

		echo '<div class="mhm-panel mhm-detail">';
		echo '<h2>' . esc_html__( 'Selected hub', 'mavo-hub-manager' ) . ': ' . esc_html( get_the_title( $post ) ) . ' ' . wp_kses_post( self::type_badge( $type ) ) . '</h2>';

		$issues = MHM_Model::get_hub_issues( $hub_id );
		foreach ( $issues as $issue ) {
			printf(
				'<div class="notice %s inline"><p>%s</p></div>',
				'error' === $issue['level'] ? 'notice-error' : 'notice-warning',
				esc_html( $issue['message'] )
			);
		}

		echo '<table class="mhm-props"><tbody>';
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'ID', 'mavo-hub-manager' ), (int) $hub_id );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Hub type', 'mavo-hub-manager' ), wp_kses_post( self::type_badge( $type ) ) );
		printf( '<tr><th>%s</th><td>%s / %s</td></tr>', esc_html__( 'Post type / status', 'mavo-hub-manager' ), esc_html( $post->post_type ), esc_html( $post->post_status ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Language', 'mavo-hub-manager' ), wp_kses_post( self::lang_cell( $hub_id ) ) );
		printf(
			'<tr><th>%s</th><td><a href="%s">%s</a></td></tr>',
			esc_html__( 'Permalink', 'mavo-hub-manager' ),
			esc_url( (string) get_permalink( $hub_id ) ),
			esc_html( (string) get_permalink( $hub_id ) )
		);
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Its primary geographic hub', 'mavo-hub-manager' ), self::post_link( MHM_Model::get_primary_hub( $hub_id, 'geo' ) ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Its primary thematic hub', 'mavo-hub-manager' ), self::post_link( MHM_Model::get_primary_hub( $hub_id, 'theme' ) ) );

		$ancestors = MHM_Model::get_hub_ancestors( $hub_id, $type );
		$chain     = esc_html( get_the_title( $post ) );
		foreach ( $ancestors as $ancestor ) {
			$chain .= ' <span class="mhm-muted">→</span> ' . self::post_link( (int) $ancestor );
		}
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Inferred hierarchy', 'mavo-hub-manager' ),
			$ancestors ? $chain : '<span class="mhm-muted">' . esc_html__( 'top-level hub', 'mavo-hub-manager' ) . '</span>'
		);
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Direct children', 'mavo-hub-manager' ), (int) MHM_Model::count_hub_children( $hub_id, $type ) );
		echo '</tbody></table>';

		echo '<p class="mhm-actions">';
		echo '<a class="button" href="' . esc_url( (string) get_edit_post_link( $hub_id ) ) . '">' . esc_html__( 'Edit', 'mavo-hub-manager' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( (string) get_permalink( $hub_id ) ) . '">' . esc_html__( 'View', 'mavo-hub-manager' ) . '</a> ';

		// Change type / unmark.
		echo '</p>';

		$other = 'geo' === $type ? 'theme' : 'geo';

		echo '<div class="mhm-actions">';
		self::render_simple_form(
			[ 'task' => 'mark_hub', 'post_id' => $hub_id, 'hub' => $hub_id, 'hub_type' => $other ],
			$context,
			sprintf(
				/* translators: %s: hub type label */
				__( 'Change to %s hub', 'mavo-hub-manager' ),
				MHM_Model::type_label( $other )
			),
			'button',
			__( 'Changing the hub type removes every child relationship that used the old type. Continue?', 'mavo-hub-manager' )
		);
		self::render_simple_form(
			[ 'task' => 'unmark_hub', 'post_id' => $hub_id, 'hub' => $hub_id ],
			$context,
			__( 'Unmark as hub', 'mavo-hub-manager' ),
			'button button-link-delete',
			__( 'Unmarking removes every child relationship pointing at this hub. Continue?', 'mavo-hub-manager' )
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * One-button POST form.
	 *
	 * @param array  $fields  Hidden fields (task, ids, …).
	 * @param string $confirm JS confirmation text, '' for none. Destructive
	 *                        server-side steps still ask again on the next load.
	 */
	private static function render_simple_form( array $fields, array $context, string $label, string $class = 'button', string $confirm = '', bool $confirmed = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-inline-form">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';

		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		if ( $confirmed ) {
			echo '<input type="hidden" name="mhm_confirm" value="1" />';
		}
		self::context_fields( $context );

		printf(
			'<button type="submit" class="%s"%s>%s</button>',
			esc_attr( $class ),
			$confirm ? ' data-mhm-confirm="' . esc_attr( $confirm ) . '"' : '',
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * A row action that lives inside another form (the batch-assign form).
	 * HTML forbids nested forms, so the button stays in the cell and its form
	 * is buffered and printed after the outer form closes, wired by id.
	 */
	private static function row_action_button( array $fields, array $context, string $label, string $class = 'button button-small', string $confirm = '', bool $confirmed = false ): void {
		static $counter = 0;

		$form_id = 'mhm-row-form-' . ( ++$counter );

		ob_start();
		echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mhm-inline-form">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		foreach ( $fields as $name => $value ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}
		if ( $confirmed ) {
			echo '<input type="hidden" name="mhm_confirm" value="1" />';
		}
		self::context_fields( $context );
		echo '</form>';
		self::$deferred_forms[] = ob_get_clean();

		printf(
			'<button type="submit" form="%s" class="%s"%s>%s</button>',
			esc_attr( $form_id ),
			esc_attr( $class ),
			$confirm ? ' data-mhm-confirm="' . esc_attr( $confirm ) . '"' : '',
			esc_html( $label )
		);
	}

	/** Print the row-action forms buffered while a table was rendered. */
	private static function flush_deferred_forms(): void {
		foreach ( self::$deferred_forms as $form ) {
			echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with escaping.
		}

		self::$deferred_forms = [];
	}

	/* ---------------------------------------------------------- link scanner */

	private static function render_scanner( int $hub_id, array $context, bool $did_scan ): void {
		$type = (string) MHM_Model::get_hub_type( $hub_id );

		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Internal-link scanner', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Reads the stored content of this hub and classifies the posts and pages it links to. Nothing is written until you click Assign.', 'mavo-hub-manager' ) . '</p>';

		echo '<p><a class="button button-secondary" href="' . esc_url( self::page_url( array_filter( array_merge( $context, [ 'hub' => $hub_id, 'scan' => 1 ] ) ) ) ) . '">' . esc_html__( 'Scan internal links', 'mavo-hub-manager' ) . '</a></p>';

		if ( ! $did_scan ) {
			echo '</div>';

			return;
		}

		if ( null === self::$scan ) {
			echo '<p class="mhm-error-text">' . esc_html__( 'The scan could not run for this hub.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		$scan = self::$scan;

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: anchors found, 2: resolved internal targets */
					__( '%1$d links found in the stored content, %2$d resolved to distinct posts or pages.', 'mavo-hub-manager' ),
					$scan['anchors'],
					$scan['resolved']
				)
			)
		);

		if ( ! $scan['rows'] ) {
			echo '<p>' . esc_html__( 'No internal post or page links were found.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="assign_children" />';
		echo '<input type="hidden" name="hub" value="' . esc_attr( (string) $hub_id ) . '" />';
		self::context_fields( $context );

		echo '<table class="widefat striped mhm-table">';
		echo '<thead><tr>';
		echo '<th class="mhm-col-select"><input type="checkbox" data-mhm-check-all /></th>';
		echo '<th>' . esc_html__( 'ID', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Current primary hub', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $scan['rows'] as $row ) {
			$is_selectable = MHM_Scanner::UNASSIGNED === $row['state'];

			echo '<tr>';
			echo '<td>';
			if ( $is_selectable ) {
				printf(
					'<input type="checkbox" name="targets[]" value="%d" %s />',
					(int) $row['id'],
					checked( (bool) $row['preselect'], true, false )
				);
			}
			echo '</td>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td>' . self::post_link( (int) $row['id'] );
			if ( $row['reason'] ) {
				echo '<br /><span class="mhm-muted">' . esc_html( $row['reason'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $row['post_type'] ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . self::lang_cell( (int) $row['id'] ) . '</td>';
			echo '<td>' . self::state_badge( $row['state'] ) . '</td>';
			echo '<td>';
			if ( $row['current_hub'] ) {
				echo self::post_link( (int) $row['current_hub']['id'] );
				if ( $row['current_hub']['lang'] ) {
					echo ' ' . self::badge( $row['current_hub']['lang'], 'lang' );
				}
			} else {
				echo '<span class="mhm-muted">—</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( MHM_Scanner::CONFLICT === $row['state'] ) {
				self::row_action_button(
					[ 'task' => 'move_child', 'hub' => $hub_id, 'child' => (int) $row['id'] ],
					$context,
					__( 'Move primary hub here', 'mavo-hub-manager' ),
					'button button-small',
					sprintf(
						/* translators: 1: child title, 2: current hub title */
						__( 'Move "%1$s" away from "%2$s"? Its current primary hub will be replaced.', 'mavo-hub-manager' ),
						get_the_title( (int) $row['id'] ),
						get_the_title( (int) $row['current_hub']['id'] )
					),
					true
				);
			} elseif ( MHM_Scanner::CROSS_LANGUAGE === $row['state'] ) {
				self::row_action_button(
					[ 'task' => 'add_child', 'hub' => $hub_id, 'child' => (int) $row['id'] ],
					$context,
					__( 'Assign across languages', 'mavo-hub-manager' ),
					'button button-small'
				);
			} elseif ( MHM_Scanner::ALREADY_ASSIGNED_HERE === $row['state'] ) {
				self::row_action_button(
					[ 'task' => 'remove_child', 'hub' => $hub_id, 'child' => (int) $row['id'], 'type' => $type, 'rescan' => 1 ],
					$context,
					__( 'Remove assignment', 'mavo-hub-manager' ),
					'button button-small button-link-delete',
					__( 'Remove this primary hub assignment?', 'mavo-hub-manager' ),
					true
				);
			} else {
				echo '<span class="mhm-muted">—</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Assign selected linked posts', 'mavo-hub-manager' ) . '</button> ';
		echo '<span class="description">' . esc_html(
			sprintf(
				/* translators: 1: unassigned, 2: assigned, 3: conflicts, 4: cross-language */
				__( '%1$d unassigned, %2$d already assigned here, %3$d conflicts, %4$d cross-language.', 'mavo-hub-manager' ),
				$scan['counts'][ MHM_Scanner::UNASSIGNED ],
				$scan['counts'][ MHM_Scanner::ALREADY_ASSIGNED_HERE ],
				$scan['counts'][ MHM_Scanner::CONFLICT ],
				$scan['counts'][ MHM_Scanner::CROSS_LANGUAGE ]
			)
		) . '</span></p>';
		echo '</form>';

		self::flush_deferred_forms();

		self::render_reverse( $hub_id, $type, $scan['linked_ids'], $context );

		echo '</div>';
	}

	/** Linked/assigned comparison — the link is discovery, the meta is truth. */
	private static function render_reverse( int $hub_id, string $type, array $linked_ids, array $context ): void {
		$report = MHM_Scanner::reverse_report( $hub_id, $type, $linked_ids );

		echo '<h3>' . esc_html__( 'Link vs. assignment', 'mavo-hub-manager' ) . '</h3>';
		echo '<div class="mhm-groups">';

		foreach (
			[
				'linked_assigned'     => __( 'Linked and assigned', 'mavo-hub-manager' ),
				'linked_not_assigned' => __( 'Linked but not assigned', 'mavo-hub-manager' ),
				'assigned_not_linked' => __( 'Assigned but no longer linked', 'mavo-hub-manager' ),
			] as $key => $label
		) {
			echo '<div class="mhm-group">';
			printf( '<h4>%s <span class="mhm-count">%d</span></h4>', esc_html( $label ), count( $report[ $key ] ) );

			if ( 'assigned_not_linked' === $key && $report[ $key ] ) {
				echo '<p class="mhm-warning-text">' . esc_html__( 'These children still point at this hub although the hub no longer links to them. Nothing is removed automatically.', 'mavo-hub-manager' ) . '</p>';
			}

			if ( ! $report[ $key ] ) {
				echo '<p class="mhm-muted">' . esc_html__( 'None.', 'mavo-hub-manager' ) . '</p></div>';
				continue;
			}

			echo '<ul class="mhm-list">';
			foreach ( $report[ $key ] as $child_id ) {
				echo '<li>' . self::post_link( (int) $child_id ) . ' ' . self::lang_cell( (int) $child_id );

				if ( 'assigned_not_linked' === $key ) {
					echo ' <span class="mhm-state mhm-state-crosslang"><span aria-hidden="true">!</span> ' . esc_html__( 'Stale', 'mavo-hub-manager' ) . '</span> ';
					self::render_simple_form(
						[ 'task' => 'remove_child', 'hub' => $hub_id, 'child' => (int) $child_id, 'type' => $type, 'rescan' => 1 ],
						$context,
						__( 'Remove primary hub assignment', 'mavo-hub-manager' ),
						'button button-small button-link-delete',
						__( 'Remove this primary hub assignment?', 'mavo-hub-manager' ),
						true
					);
				}
				echo '</li>';
			}
			echo '</ul></div>';
		}

		echo '</div>';
	}

	/* ------------------------------------------------------------- children */

	private static function render_children( int $hub_id, array $context ): void {
		$type     = (string) MHM_Model::get_hub_type( $hub_id );
		$children = MHM_Model::get_hub_children( $hub_id, $type );

		echo '<div class="mhm-panel">';
		printf(
			'<h2>%s <span class="mhm-count">%d</span></h2>',
			esc_html__( 'Direct children', 'mavo-hub-manager' ),
			count( $children )
		);
		echo '<p class="description">' . esc_html__( 'Derived by querying the children\'s own primary-hub meta. No child list is stored on the hub.', 'mavo-hub-manager' ) . '</p>';

		if ( ! $children ) {
			echo '<p>' . esc_html__( 'This hub has no children yet.', 'mavo-hub-manager' ) . '</p></div>';

			return;
		}

		echo '<table class="widefat striped mhm-table"><thead><tr>';
		echo '<th>' . esc_html__( 'ID', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Language', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Hub type', 'mavo-hub-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'mavo-hub-manager' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $children as $child_id ) {
			$child_id = (int) $child_id;
			$post     = MHM_Model::get_eligible_post( $child_id );

			echo '<tr>';
			echo '<td>' . (int) $child_id . '</td>';
			echo '<td>' . self::post_link( $child_id ) . '</td>';
			echo '<td>' . esc_html( $post ? $post->post_type : '' ) . '</td>';
			echo '<td>' . esc_html( $post ? $post->post_status : '' ) . '</td>';
			echo '<td>' . self::lang_cell( $child_id ) . '</td>';
			echo '<td>' . self::type_badge( MHM_Model::get_hub_type( $child_id ) ) . '</td>';
			echo '<td>';
			self::render_simple_form(
				[ 'task' => 'remove_child', 'hub' => $hub_id, 'child' => $child_id, 'type' => $type ],
				$context,
				__( 'Remove primary hub assignment', 'mavo-hub-manager' ),
				'button button-small button-link-delete',
				__( 'Remove this primary hub assignment?', 'mavo-hub-manager' ),
				true
			);
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	private static function render_manual_add( int $hub_id, array $context ): void {
		$type = (string) MHM_Model::get_hub_type( $hub_id );

		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Add child manually', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: hub type label */
				__( 'Searches posts and pages in the same language that can validly take this hub as their primary %s hub. Manual assignments use exactly the same child meta as the scanner.', 'mavo-hub-manager' ),
				MHM_Model::type_label( $type )
			)
		) . '</p>';

		echo '<div class="mhm-search" data-mhm-search="child" data-mhm-hub="' . esc_attr( (string) $hub_id ) . '">';
		echo '<input type="search" class="regular-text" data-mhm-input placeholder="' . esc_attr__( 'Title or exact ID…', 'mavo-hub-manager' ) . '" />';
		echo '<div class="mhm-search-results" data-mhm-results aria-live="polite"></div>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mhm-child-form hidden>';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="add_child" />';
		echo '<input type="hidden" name="hub" value="' . esc_attr( (string) $hub_id ) . '" />';
		echo '<input type="hidden" name="child" value="" data-mhm-child-id />';
		self::context_fields( $context );
		echo '<p>' . esc_html__( 'Selected:', 'mavo-hub-manager' ) . ' <strong data-mhm-child-label></strong></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Assign as child', 'mavo-hub-manager' ) . '</button> ';
		echo '<button type="button" class="button" data-mhm-child-cancel>' . esc_html__( 'Cancel', 'mavo-hub-manager' ) . '</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/* ---------------------------------------------------------------- audit */

	/**
	 * Site-wide reports live on their own screen: they query every post that
	 * carries hub meta, so they must never run as a side effect of this page.
	 */
	private static function render_audit_link(): void {
		echo '<div class="mhm-panel">';
		echo '<h2>' . esc_html__( 'Audit', 'mavo-hub-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Site-wide reports — posts with no hub, children that never link back to their hub, hub health, and broken stored relationships — run on demand on their own screen.', 'mavo-hub-manager' ) . '</p>';
		printf(
			'<p><a class="button button-secondary" href="%s">%s</a></p>',
			esc_url( MHM_Audit_Admin::page_url() ),
			esc_html__( 'Open Tools → Hub Audit', 'mavo-hub-manager' )
		);
		echo '</div>';
	}
}
