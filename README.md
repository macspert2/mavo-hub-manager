# Mavo Hub Manager — V0

An editorial hub model for Maman Voyage: mark posts and pages as **geographic** or
**thematic** hubs, and give every post/page at most **one primary geographic hub** and
**one primary thematic hub**.

## The whole data model

Three post meta keys. Nothing else.

```
_mavo_hub_type            on the hub     'geo' | 'theme'
_mavo_primary_geo_hub     on the child   hub post ID
_mavo_primary_theme_hub   on the child   hub post ID
```

**Each relationship is stored once, on the child.** Hubs never store child lists. There
is no relationship table, no taxonomy, no ancestor array, no cached child count. Reverse
lists and hierarchy are derived at read time:

```
children of a hub   → query posts whose own primary meta == hub ID
ancestors of a post → follow its immediate primary hub of the same type, hop by hop
```

```
France             (_mavo_hub_type = geo)
  ↑ Paris          (_mavo_primary_geo_hub = France)
    ↑ Paris en famille
      ↑ 10 activités à Paris en famille   ← also _mavo_primary_theme_hub = City trips
```

A hub is exactly one type or neither. A hub may itself be the child of a higher hub of
its own type. A post may have one geo and one theme relationship at the same time, and
the two hierarchies are walked independently.

## What "primary" means

> **Primary geographic hub** = the most immediate geographic editorial hub that owns this
> content.
> **Primary thematic hub** = the most immediate thematic editorial hub that owns this
> content.

Not "every hub that links to this article", and not "every relevant place or theme".
Broader relationships come from the hierarchy.

## Admin

**Tools → Hub Manager** (`manage_options`). No frontend output, no frontend assets.

1. **Search** a post/page and mark it as a geographic or thematic hub.
2. **Hub registry** — every hub, filterable by type and language, with derived direct
   child counts. Pick one to manage.
3. **Selected hub** — status, language, permalink, its own primary hubs, its inferred
   ancestor chain, and prominent warnings (cycle, invalid primary hub, wrong hub type,
   cross-language parent).
4. **Internal-link scanner** — parses the hub's stored content, resolves internal links
   to post/page IDs and classifies each one.
5. **Direct children**, **manual child assignment**, and on-demand **relationship
   diagnostics**.

### Scanner states

| State | Meaning | Batch-assignable |
|---|---|---|
| `UNASSIGNED` | no primary hub of this type yet | yes — preselected when published |
| `ALREADY_ASSIGNED_HERE` | already points at this hub | informational |
| `CONFLICT` | points at a different hub of this type | only via *Move primary hub here*, confirmed |
| `CROSS_LANGUAGE` | link crosses Polylang languages | only via an explicit, confirmed action |
| `INVALID_TARGET` | missing, wrong post type, or would create a cycle | no |
| `SELF` | the hub links to itself | no |

A scan **never** writes anything, and the batch assign re-classifies every checked row
server-side before writing — a stale form cannot overwrite a primary hub that appeared in
the meantime. Nothing is written by merely opening the page or saving a post.

### Link vs. assignment

The link is a discovery signal; the child's meta is the source of truth. The scanner
therefore also shows *Linked and assigned*, *Linked but not assigned*, and *Assigned but
no longer linked*. The last group is a warning with a manual removal button — a
relationship is never deleted because a link disappeared.

### Confirmations

Changing a hub's type or unmarking it while children exist, overwriting a child's primary
hub, and removing an assignment all require an explicit confirmation, and report how many
relationships were affected. Invalid relationships are removed *before* the hub type
changes, so no dangling primary hub IDs are left behind.

## Polylang

Optional — the plugin works without it and never fatals. When it is active, language is
shown everywhere, only same-language targets are proposed or auto-assigned, and
cross-language links are surfaced as diagnostics instead. No translated relationship is
ever created automatically.

## Helper API

Other `mavo-*` plugins should read hubs through these, never through raw meta:

```php
mavo_is_hub( int $post_id ): bool
mavo_get_hub_type( int $post_id ): ?string
mavo_get_primary_hub( int $post_id, string $type ): ?int
mavo_set_primary_hub( int $post_id, int $hub_id, string $type ): true|WP_Error
mavo_remove_primary_hub( int $post_id, string $type ): bool
mavo_get_hub_children( int $hub_id, string $type, array $args = [] ): array
mavo_get_hub_ancestors( int $post_id, string $type ): array
mavo_would_create_hub_cycle( int $child_id, int $proposed_hub_id, string $type ): bool
mavo_scan_hub_internal_links( int $hub_id ): array
```

Hooks fired after successful changes:

```php
do_action( 'mavo_hub_relationship_changed', $child_id, $type, $old_hub_id, $new_hub_id );
do_action( 'mavo_hub_type_changed', $hub_id, $old_type, $new_type );
```

Filter for extra internal hostnames (staging, legacy domains):

```php
add_filter( 'mavo_hub_manager_internal_hosts', fn( $hosts ) => [ ...$hosts, 'staging.example' ] );
```

## Known limitation

The scanner reads **stored** `post_content` and does not render shortcodes: rendering in
admin can have side effects and pollute global `$post`, and the scan must stay
deterministic. Links that only exist in shortcode output are not discovered — assign
those children manually.

## Tests

No WordPress required; the harness stubs what the model and scanner call.

```sh
./tests/run.sh
```

| File | Covers |
|---|---|
| `test-model.php` | marking, relationship validity, hierarchy, cycles, confirmed type changes |
| `test-scanner.php` | URL resolution, classification, reverse grouping |
| `test-polylang.php` | language reporting, same vs cross language |
| `test-no-polylang.php` | everything still works with Polylang absent |
| `test-diagnostics.php` | orphan, wrong-type, self-reference, cycle, cross-language reports |
| `test-admin.php` | admin-post routing, confirmations, page rendering |

## Out of scope for V0

Secondary hubs, multiple primary hubs, hub taxonomies, child lists on hubs, frontend
breadcrumbs or link strips, automatic rescan on save, automatic relationship deletion,
graph visualisation, and all recommendation scoring (that stays in `mavo-for-you`).
