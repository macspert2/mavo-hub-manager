# Agent brief — `mavo-hub-manager`

## Purpose

Create a small WordPress admin plugin named **`mavo-hub-manager`** for the Maman Voyage site.

The plugin provides an explicit editorial model for identifying hub pages/posts and assigning **one primary geographic hub** and **one primary thematic hub** to posts/pages.

The core design constraint is important:

> **Store each hub relationship only once, on the child post/page as post meta. Do not store child lists or relationship copies on the hub.**

Hub hierarchy must be inferred by following those same child → primary-hub relationships when the child itself is also a hub.

This plugin is intended to become infrastructure for:
- internal linking / hub navigation,
- future recommendation scoring in `mavo-for-you`,
- diagnostics of content structure,
- multilingual hub management.

Do **not** build recommendation functionality here.

## 1. Locked data model

### 1.1 Marking a post/page as a hub

Any WordPress `post` or `page` may be marked as a hub with:

```text
_mavo_hub_type
```

Allowed values:

```text
geo
theme
```

Absence of the meta key means the post/page is not a hub.

For V0, a hub is **exactly one type or neither**. Do not allow a hub to be both geographic and thematic.

Suggested helper:

```php
mavo_get_hub_type( int $post_id ): ?string
```

Return `geo`, `theme`, or `null`.

### 1.2 Primary hub relationships stored on children

Each post/page may have at most one primary geographic hub:

```text
_mavo_primary_geo_hub
```

and at most one primary thematic hub:

```text
_mavo_primary_theme_hub
```

Values are WordPress post IDs.

Example:

```text
Article: "10 activités à Paris en famille"

_mavo_primary_geo_hub   = 1234   // Paris en famille
_mavo_primary_theme_hub = 5678   // City trips en famille
```

These are the **only stored relationship records**.

Do not write a list of children into hub metadata.
Do not create a custom relationship table.
Do not mirror relationships in both directions.

To find children of a hub, query posts/pages whose corresponding meta value equals that hub ID.

## 2. Hub hierarchy

Do not store ancestor arrays or hierarchy paths.

Hierarchy is inferred dynamically.

A hub may itself have a primary hub of the same type.

Example:

```text
France               (_mavo_hub_type = geo)
↑
Paris                (_mavo_hub_type = geo)
↑
Paris en famille     (_mavo_hub_type = geo)
↑
individual article
```

Represent this only via each object's immediate primary hub.

Provide helper functions such as:

```php
mavo_get_primary_hub( int $post_id, string $type ): ?int
mavo_get_hub_ancestors( int $post_id, string $type ): array
mavo_get_hub_children( int $hub_id, string $type, array $args = [] ): array
```

`mavo_get_hub_ancestors()` returns nearest → furthest ancestors.

Implement:
- self-reference protection,
- cycle detection,
- duplicate detection,
- defensive maximum depth (e.g. 20).

Never allow a relationship assignment that introduces a cycle.

## 3. Relationship validity rules

For `_mavo_primary_geo_hub`:
- target must exist,
- target must be `post` or `page`,
- target must have `_mavo_hub_type = geo`,
- child cannot equal target,
- assignment must not introduce a geographic hierarchy cycle.

For `_mavo_primary_theme_hub`:
- same rules,
- target must have `_mavo_hub_type = theme`,
- assignment must not introduce a thematic hierarchy cycle.

A child does **not** itself need to be a hub.

A hub may also be the child of a higher-level hub of its own type.

A geographic hub may also have a thematic primary hub, and vice versa, because every post/page can independently have one geo and one theme relationship. However, hierarchy calculations must stay type-specific.

## 4. Multilingual / Polylang behavior

Maman Voyage uses Polylang.

The plugin must work if Polylang is active, but must not fatal if Polylang is unavailable.

When Polylang functions exist:
- show language in all relevant admin tables/search results,
- only propose or auto-assign a hub to a child when both are in the **same language**,
- linked-target scanning should ignore cross-language internal links for automatic assignment,
- highlight cross-language links as diagnostics rather than assigning them.

Useful functions:

```php
pll_get_post_language( $post_id, 'slug' )
pll_get_post( $post_id, $language )
```

Do not automatically create translated hub relationships in V0.
Do not assume translated hubs exist.

## 5. Admin page

Create one admin page:

```text
Tools → Hub Manager
```

Suggested slug:

```text
mavo-hub-manager
```

Use `add_management_page()`.

Recommended capability for V0:

```text
manage_options
```

No frontend UI.

## 6. Admin page layout

Use two main vertical sections.

### 6.1 Top section — Hub registry

Show all posts/pages currently marked with `_mavo_hub_type`.

Columns:

```text
Select
ID
Title
Post type
Status
Language
Hub type
Primary geographic hub
Primary thematic hub
Direct child count
Edit
View
```

Allow filtering by:

```text
All
Geographic
Thematic
Language
```

A radio button or row action selects one hub for detailed management in the lower section.

## 7. Marking / unmarking hubs

At the top of the admin page, provide an active search box:

```text
Find a post or page to mark as a hub
```

Search `post` and `page` objects.

Results show:

```text
ID
title
post type
status
language
current hub type if any
```

After choosing one, allow:

```text
Mark as geographic hub
Mark as thematic hub
```

If already marked, allow changing type with explicit confirmation.

Changing a hub from `geo` to `theme`, or vice versa, can invalidate existing child relationships.

Therefore:
1. Query all children currently pointing to it using the old relationship meta.
2. If any exist, do **not** silently change the type.
3. Show a warning with child count.
4. Require an explicit destructive confirmation.
5. On confirmed type change, remove the now-invalid primary relationship meta from affected children before changing `_mavo_hub_type`.
6. Report how many relationships were removed.

Unmarking a hub follows the same rule:
- show number of children,
- require confirmation,
- delete affected primary relationship meta from those children,
- then delete `_mavo_hub_type`.

Do not leave invalid dangling primary-hub IDs.

## 8. Selected hub detail area

When a hub is selected, show:

```text
Hub title
ID
type
status
language
permalink
its own primary geo hub
its own primary theme hub
inferred ancestors for its own type
direct child count
```

Provide links to Edit and View.

If hierarchy has a problem, show it prominently.

Examples:

```text
Cycle detected
Invalid primary hub
Primary hub has wrong hub type
Primary hub is in another Polylang language
```

## 9. Internal-link scanner

For the selected hub, parse its `post_content` and find all internal `<a href>` links.

Resolve each internal URL to a WordPress post/page ID using WordPress functions where practical, especially `url_to_postid()`.

Support:
- absolute `https://www.mamanvoyage.com/...`,
- absolute `https://mamanvoyage.com/...`,
- same-site relative URLs,
- dated WordPress permalinks,
- page permalinks.

Ignore:
- external links,
- fragment-only links,
- mailto/tel/javascript links,
- media attachments unless they resolve to an allowed `post`/`page`,
- self-links,
- targets that are not `post` or `page`.

Deduplicate target IDs.
Do not derive relationships from anchor text.

## 10. Scanner assignment rule

The selected hub's type determines which child meta field it can populate.

For a geographic hub:

```text
_mavo_primary_geo_hub
```

For a thematic hub:

```text
_mavo_primary_theme_hub
```

### Critical safety behavior

A scan must **never silently overwrite an existing primary hub**.

Classify each linked target into one of these states:

```text
UNASSIGNED
ALREADY_ASSIGNED_HERE
CONFLICT
CROSS_LANGUAGE
INVALID_TARGET
SELF
```

On initial scan:
- `UNASSIGNED` rows may be preselected for assignment.
- `ALREADY_ASSIGNED_HERE` are informational.
- `CONFLICT` must not be overwritten automatically.
- `CROSS_LANGUAGE` must not be automatically assigned.
- invalid/self rows should be excluded or shown only in diagnostics.

The admin explicitly clicks:

```text
Assign selected linked posts
```

Do not write relationships merely by opening the Hub Manager page.

## 11. Conflict handling

For a linked target already assigned to another primary hub of the same type, show:

```text
Target title
Current primary hub
Current primary hub language
Selected hub
```

Provide an explicit action:

```text
Move primary hub to selected hub
```

This action must require confirmation.

Do not batch-overwrite conflicts by default.

## 12. Link discovery vs source of truth

The internal link is a **discovery signal**, not the source of truth.

Source of truth is always the child's primary hub post meta.

Therefore a post can legitimately:
- be linked from a hub but have a different primary hub,
- have the selected hub as primary even if the hub currently does not link to it.

The admin UI should expose these differences.

## 13. Stale / reverse diagnostics

Because relationships are stored only on children, derive the reverse set with a meta query.

For selected hub compare:

```text
stored_children = all posts/pages whose relevant primary meta == selected hub ID
linked_targets  = all eligible internal post/page links currently found in hub content
```

Show three useful groups:

```text
Linked and assigned
Linked but not assigned
Assigned but no longer linked
```

For `Assigned but no longer linked`, do **not** automatically remove the relationship.

Show it as a warning and provide a manual:

```text
Remove primary hub assignment
```

## 14. Direct-child queries

Do not store child counts.
Calculate direct child counts from post meta.

Example for geographic hubs:

```php
new WP_Query([
    'post_type'      => ['post', 'page'],
    'post_status'    => 'any',
    'meta_key'       => '_mavo_primary_geo_hub',
    'meta_value'     => $hub_id,
    'fields'         => 'ids',
]);
```

Equivalent for thematic hubs.

## 15. Manual relationship editor

The Hub Manager should also allow manually assigning/removing a relationship even if no internal link exists.

For the selected hub, provide:

```text
Add child manually
```

with active search of posts/pages in the same language.

Only show candidates that can validly use the selected hub type.

Manual assignments use exactly the same child post meta as scanner assignments.

No separate "manual membership" storage.

## 16. Hub hierarchy inferred only

For a selected hub, infer and display its parent chain for its own type.

Example:

```text
Paris en famille
→ Paris
→ France
```

Do not store this string or ancestor list.

For children, optionally show:

```text
Immediate hub: Paris en famille
Ancestors: Paris → France
```

but derive it dynamically.

## 17. Cycle prevention

Before writing a primary-hub relationship, validate that the target hub is not:
- the child itself,
- a descendant of the child through same-type primary relationships.

Example invalid assignment:

```text
France → Paris
Paris → Paris en famille
Paris en famille → France
```

Reject this and show a clear admin error.

Suggested helper:

```php
mavo_would_create_hub_cycle(
    int $child_id,
    int $proposed_hub_id,
    string $type
): bool
```

## 18. Orphan / invalid relationship diagnostics

Add diagnostics, ideally on demand rather than recalculated on every page load.

Report:

```text
posts/pages whose _mavo_primary_geo_hub points to a missing object
posts/pages whose _mavo_primary_geo_hub points to something not marked geo
posts/pages whose _mavo_primary_theme_hub points to a missing object
posts/pages whose _mavo_primary_theme_hub points to something not marked theme
cross-language primary relationships
self-references
cycles
```

Do not automatically repair these without confirmation.

## 19. Published status

V0 behavior:
- allow any `post` or `page` to be marked as a hub regardless of status so hubs can be prepared before publishing,
- clearly show hub status in admin,
- allow relationships to drafts/private/future posts only via explicit manual assignment,
- internal-link scan should show linked unpublished targets but **do not preselect them for automatic assignment**,
- published hub → published child is the standard case.

## 20. Search implementation

For active search boxes use WordPress admin AJAX or REST.

There are two searches:

```text
1. Find post/page to mark as hub
2. Find post/page to add manually as child
```

Use an administrator-only endpoint.
Return a maximum of around 20 results.
Search by title, plus exact numeric ID if entered.

Results should include:
- ID,
- title,
- type,
- status,
- language,
- hub type,
- current primary hub of the selected type where relevant.

All endpoints require:
- logged-in user,
- `manage_options`,
- nonce validation.

## 21. Plugin structure

Suggested:

```text
wp-content/plugins/mavo-hub-manager/
├── mavo-hub-manager.php
├── includes/
│   ├── class-mavo-hub-manager-admin.php
│   ├── class-mavo-hub-manager-model.php
│   ├── class-mavo-hub-manager-scanner.php
│   └── class-mavo-hub-manager-ajax.php
└── assets/
    ├── admin.css
    └── admin.js
```

Keep it small and readable.
No build process required.
No external JS framework.

## 22. Core helper API

Expose a small stable procedural API so other `mavo-*` plugins can consume hub information later.

Suggested functions:

```php
mavo_is_hub( int $post_id ): bool
mavo_get_hub_type( int $post_id ): ?string
mavo_get_primary_hub( int $post_id, string $type ): ?int
mavo_set_primary_hub( int $post_id, int $hub_id, string $type ): true|WP_Error
mavo_remove_primary_hub( int $post_id, string $type ): bool
mavo_get_hub_children( int $hub_id, string $type, array $args = [] ): array
mavo_get_hub_ancestors( int $post_id, string $type ): array
mavo_scan_hub_internal_links( int $hub_id ): array
```

Do not make future plugins read raw meta values everywhere if a helper can centralize validation.

## 23. Meta updates and hooks

Use:

```php
update_post_meta()
delete_post_meta()
```

Do not write raw SQL for normal updates.

After successful relationship changes, fire custom hooks:

```php
do_action(
    'mavo_hub_relationship_changed',
    $child_id,
    $type,
    $old_hub_id,
    $new_hub_id
);
```

When hub type changes:

```php
do_action(
    'mavo_hub_type_changed',
    $hub_id,
    $old_type,
    $new_type
);
```

## 24. No duplicate storage

Strict acceptance rule: do not create any of these:

```text
_mavo_hub_children
_mavo_geo_hub_children
_mavo_theme_hub_children
serialized child ID arrays
custom relationship table
taxonomy terms duplicating the same relationship
```

All reverse relations are derived from:

```text
_mavo_primary_geo_hub
_mavo_primary_theme_hub
```

on child posts/pages.

## 25. Link scanner implementation detail

Prefer robust HTML parsing over broad regex.

Possible approaches:
- `WP_HTML_Tag_Processor` if available and suitable,
- otherwise `DOMDocument`,
- limited regex only as fallback.

The scanner must not modify hub content.
It only reads anchors.

Normalize URLs before resolving:
- HTML entity decode,
- strip fragments for resolution,
- convert relative URLs to site absolute URLs.

Ensure `mamanvoyage.com` and `www.mamanvoyage.com` are both treated as internal.

## 26. Shortcodes / generated links caveat

`post_content` may contain shortcodes whose final frontend output includes links.

V0 should scan **stored post content**, not render arbitrary shortcodes.

Reason:
- rendering shortcodes in admin can have side effects,
- global `$post` pollution is possible,
- scanner should remain deterministic.

If a link exists only after shortcode rendering, it may not be discovered automatically.
Manual child assignment covers this case.

Document this limitation in admin help text.

## 27. Interaction with `mavo-for-you`

Do not modify `mavo-for-you` in this task.

But design the helper API so it can later ask:

```php
$geo = mavo_get_primary_hub( $post_id, 'geo' );
$theme = mavo_get_primary_hub( $post_id, 'theme' );
$geo_ancestors = mavo_get_hub_ancestors( $post_id, 'geo' );
$theme_ancestors = mavo_get_hub_ancestors( $post_id, 'theme' );
```

No scoring code belongs in `mavo-hub-manager`.

## 28. Relationship semantics

Document clearly in code comments/admin help:

```text
Primary geographic hub
= the most immediate geographic editorial hub that owns this content.

Primary thematic hub
= the most immediate thematic editorial hub that owns this content.
```

"Primary" is not equivalent to every hub that links to the article or every relevant geographic/theme concept.

Higher-level relationships are inferred through hub hierarchy.

## 29. Suggested admin workflow

### Creating a geographic hub

1. Open Tools → Hub Manager.
2. Search for "Paris en famille".
3. Mark it as Geographic.
4. If appropriate, assign its own primary geographic hub to "Paris".
5. Select "Paris en famille" in the hub list.
6. Scan internal links.
7. Review linked posts.
8. Assign unassigned eligible posts.
9. Review conflicts manually.

### Creating a thematic hub

1. Search for "City trips en famille".
2. Mark it as Thematic.
3. Optionally assign its own higher thematic hub.
4. Scan links.
5. Assign eligible children to `_mavo_primary_theme_hub`.

A single article can simultaneously have:

```text
primary geo hub   = Paris en famille
primary theme hub = City trips en famille
```

## 30. Admin confirmations

Require explicit confirmation for:
- changing a hub type when children exist,
- unmarking a hub when children exist,
- overwriting a child's current primary hub,
- removing a stale primary assignment.

Simple assignment into an empty primary slot does not need an extra per-row confirmation if the user explicitly selected rows and clicked the batch assign button.

## 31. Security

All admin actions must include:
- capability check,
- nonce validation,
- integer validation with `absint()`,
- re-query and re-validation before mutations,
- output escaping,
- safe redirects using `wp_safe_redirect()`.

Never trust IDs or hub types received from POST/AJAX.
Before every relationship write, run canonical model validation.

## 32. Admin notices / result summaries

After mutation, redirect to avoid accidental form resubmission.

Show notices such as:

```text
12 posts assigned to "Paris en famille".
3 conflicts were left unchanged.
1 cross-language link was ignored.
```

For destructive actions:

```text
"Paris en famille" changed from geographic to thematic.
18 invalid geographic child relationships were removed.
```

## 33. Logging

A permanent custom log table is not required in V0.

Use clear admin notices and WordPress action hooks.
Optionally use `error_log()` only when `WP_DEBUG` is true.

Do not create extra persistent relationship history unless specifically requested later.

## 34. Performance

Avoid loading full `post_content` for every post on every admin page request.
Only scan the currently selected hub when requested.

Do not run global diagnostics automatically on every page load if expensive.
Provide a button:

```text
Run relationship diagnostics
```

and calculate diagnostics on demand.

## 35. CSS/UI

Admin-only CSS.
Keep close to standard WordPress admin styling.

Use:
- normal WP tables,
- small hub-type badges,
- clear warning states,
- text/icons in addition to color.

Scanner statuses should include:

```text
Assigned
Unassigned
Conflict
Cross-language
Stale
Invalid
```

## 36. Important defaults / assumptions locked for V0

1. Hub types are exactly `geo` or `theme`.
2. A hub can have only one hub type.
3. Every child may have at most one primary geo and one primary theme hub.
4. Relationships are stored only on the child.
5. Hierarchy is inferred from the same primary relationship metadata.
6. Scanner finds candidate children from internal links in stored `post_content`.
7. Scanner does not automatically overwrite existing primary relationships.
8. Scanner assignments are explicit admin actions, not side effects of page load/save.
9. Cross-language relationships are not auto-created.
10. Same-language Polylang relationships are preferred/required.
11. A hub can itself be a child of another hub.
12. No secondary hubs in V0.
13. No taxonomy-based hub membership in V0.
14. No duplicated hub-child list in V0.
15. No recommendation logic in V0.
16. No frontend output in V0.
17. No automatic rescan on `save_post` in V0.
18. No full hierarchy copied onto child posts.

## 37. Tests

### Hub type
- mark post as geo,
- mark page as theme,
- reject invalid type,
- change type with no children,
- block/confirm type change with children,
- unmark safely.

### Primary relationship
- assign geo child → geo hub,
- reject geo child → theme hub,
- assign theme child → theme hub,
- reject self-parent,
- reject missing target,
- reject wrong post type,
- overwrite only with explicit action.

### Hierarchy

Build:

```text
France → null
Paris → France
Paris en famille → Paris
Article → Paris en famille
```

Expected article geo ancestors:

```text
Paris en famille
Paris
France
```

Then attempt:

```text
France → Paris en famille
```

Expected: reject cycle.

### Scanner

Hub content containing:
- internal absolute link,
- internal relative link,
- duplicate link,
- external link,
- fragment link,
- self-link,
- cross-language link,
- link to already-assigned target,
- link to conflicting target.

Verify classifications.

### Reverse diagnostics

Child points to hub but no corresponding link exists in hub content.

Expected:

```text
Assigned but no longer linked
```

No automatic deletion.

### Polylang
- same-language assignment allowed,
- cross-language automatic assignment rejected,
- language displayed,
- plugin still functions if Polylang functions are absent.

## 38. Acceptance criteria

The plugin is complete when all of the following are true:

- Tools → Hub Manager exists.
- Any post/page can be marked as geographic or thematic hub.
- Hub identity is stored only via `_mavo_hub_type`.
- Child primary relationships are stored only in `_mavo_primary_geo_hub` and `_mavo_primary_theme_hub`.
- No child lists are stored on hubs.
- A selected hub can scan its internal links.
- Eligible unassigned linked posts can be batch-assigned.
- Existing conflicting primary assignments are never silently overwritten.
- A child can have one geo and one theme primary hub simultaneously.
- Hubs can themselves point to higher-level hubs.
- Ancestors are inferred dynamically.
- Cycles cannot be created.
- Reverse child lists are derived by querying child post meta.
- Stale "assigned but no longer linked" relationships can be identified.
- Manual child assignment is possible.
- Polylang language is respected when active.
- Cross-language relationships are not auto-created.
- No frontend assets or output are added.
- All mutations are permission-checked and nonce-protected.
- No recommendation logic is included.

## 39. Explicit non-goals for V0

Do not implement:
- multiple geographic primary hubs,
- multiple thematic primary hubs,
- secondary hubs,
- hub taxonomy,
- child arrays stored on hubs,
- long-term recommendation profiles,
- `mavo-for-you` scoring,
- frontend breadcrumbs,
- frontend hub-link strips,
- automatic internal-link insertion,
- automatic rescan on post save,
- automatic relationship deletion because a hub link disappears,
- hub analytics,
- graph visualization,
- drag-and-drop hierarchy UI,
- translated-hub auto-linking,
- AI inference of hub relationships.

Keep the first version narrowly focused on creating and maintaining a trustworthy hub relationship model.

