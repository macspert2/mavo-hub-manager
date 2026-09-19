# Hub structure for Maman Voyage — analysis and discussion

*Recorded 2026-09-19. A design discussion, not a specification. Nothing here has been
implemented; the "what I'd build" list at the end is a proposal.*

**Decision taken during this discussion:** the theme hub is mainly **a page to rank and
link from**; its usefulness as a signal for `mavo-for-you` is a welcome by-product, not
the purpose. Everything in part 2 follows from that answer.

---

## Part 1 — The structure as it stands

Two independent single-parent trees (`geo`, `theme`), each edge stored once on the child,
hubs being ordinary posts/pages, reverse lists and ancestry derived at read time.
Discovery is separated from truth: internal links and tags propose, meta decides.

### What's genuinely right about it

**One-way storage.** The thing that kills relationship models in WordPress is
bidirectional drift. You can't have drift here because there's nothing to drift against.
Everything else in the plugin — audit, graph, traffic roll-up — is a pure function of
three meta keys. That's why the audit could be added without touching the model.

**Hubs are pages, not terms.** Right call for a travel blog: a hub has to rank, carry
1500 words of editorial text, get a Polylang translation, and be linkable. A taxonomy term
gives you a thin archive page nobody links to. The cost is that the tree can't exist
before the content does — which is actually a feature, it prevents an aspirational
200-node geo tree with 11 real articles under it.

**Not using `post_parent`.** Correct, and worth remembering why: geo hierarchy would then
dictate URL structure, and you'd be unable to put a hub tree over `post` at all.

**Discovery ≠ truth.** The link scanner, the tag sweep, and *assigned but no longer
linked* all being read-only signals is the part I'd defend hardest. It's what makes it
safe to run sweeps repeatedly.

### Where it strains

**1. The theme axis isn't really a tree — it's facets.** Geo is genuinely hierarchical:
Paris ⊂ Île-de-France ⊂ France, no argument. But "en famille", "petit budget", "roadtrip",
"hiver", "plage" are not levels of anything; an article is several of them at once.
Forcing one *primary* theme hub per post throws away the other two, and the article's tags
already express the full set better. So you have two systems saying overlapping things,
and the weaker one is the one `mavo-for-you` will read.

The honest question is: what is the theme axis *for*? If it's for building theme hub
**pages** with one canonical child list, single-primary is correct and tags stay separate.
If it's an input to recommendation scoring, single-primary is lossy and you'd want
weighted facets. It can't be both without admitting secondary theme hubs.
*(Answered: it's the page. See part 2.)*

**2. The intersection problem — and it's the central one for this site.** Your best pages
are intersections: *Paris en famille*, *Italie avec un bébé*, *City trips en famille*. The
model says a hub is exactly one type. The escape hatch already works — mark *Paris en
famille* as `geo`, give it `_mavo_primary_theme_hub = City trips en famille` — but it only
works for the hub itself. An article under *Paris en famille* inherits nothing thematic,
because ancestor walks are strictly type-scoped. So the article is thematically invisible
unless someone assigns its theme hub by hand, which is the assignment most likely to be
skipped.

**This is the cheapest high-value change available:** an *effective* theme hub computed at
read time — if a post has no theme hub, walk its geo ancestors and take the first theme
hub found. Zero new storage, one helper (`mavo_get_effective_hub( $post_id, $type )`),
fully consistent with the "infer, don't store" rule, and it makes intersection hubs do
real work. Same trick in reverse (theme→geo) is much less useful; I'd only do the one
direction.

**3. Single parent, overlapping regions.** Geo is a tree until it isn't: *Côte d'Azur* vs
*Provence*, *Alpes* across FR/IT/CH, *Sud de la France* as a marketing region rather than
an administrative one. You get one parent. Two ways out: model the overlapping one as a
*theme* hub (works surprisingly well — "roadtrip dans les Alpes" is a theme, not a place),
or accept the tree and keep the alternate framings as tags. I'd avoid secondary geo hubs —
the moment ancestry is a DAG, every walk, the traffic roll-up and the cycle check get more
expensive and the breadcrumb stops being well-defined.

**4. Fan-out is unmanaged.** Nothing stops *France* from having 180 direct children, which
is the state where a hub stops being navigable and its child list stops being useful for
internal linking. Hub health already reports depth and child count; what's missing is the
editorial signal — *hubs with more than ~25 direct children are candidates for splitting*
— and the reverse, *hubs with fewer than 3 children shouldn't be hubs yet*. Both are
report-only, both are a few lines.

**5. No curated child order.** Derived child lists come back by title/date/views. For a hub
page you usually want a hand-picked top five. Note the "never store on the hub" rule
doesn't block this: `_mavo_hub_order` on the *child* is entirely legal within the model.
Worth knowing the door is open even if you don't walk through it in V0.

**6. Polylang hierarchy drifts silently.** Each language has its own independent tree,
built by hand. Nothing detects "the EN translation of *Paris en famille* has a different
parent than the translation of *Paris*". Two cheap additions, in order of value: a
diagnostic for that mismatch, then a one-click "mirror this hub's parent into language X"
that still requires confirmation. Auto-creating translated relationships stays correctly
out of scope.

**7. No provenance on an edge.** You can't tell whether a relationship came from the
scanner, a tag sweep, or a human. It makes re-running a tag sweep over a hub less safe than
it feels, because a human override and an old automatic guess look identical. One extra
meta key on the child would fix it; it's real but it's a V1 concern.

---

## Part 2 — Consequences of "the theme hub is a page to rank and link from"

That settles the theme axis: single-primary stays, and the design questions shift from
"is this lossy?" to "does every theme hub deserve to be a page?". Several consequences
follow, and a couple of them cut against part 1.

**Single-primary is correct — but ownership and listing are now two different things.**
A ranking page wants to *show* every relevant article. Ownership says an article feeds
exactly one theme hub. Those conflict the moment "10 activités à Paris en famille" is
owned by *City trips en famille* but ought to also appear on *Voyager avec un bébé*.

Don't fix that by loosening ownership. Fix it by being explicit that
`_mavo_primary_theme_hub` answers **"which theme page is this article's canonical home"** —
it drives the breadcrumb, the hub strip, the link back — while what a theme page
*displays* is a curated editorial list that can include anything. The tag machinery you
already have is the right feeder for that listing. Two roles, two mechanisms, no second
relationship store.

**The link-back report becomes the most important thing in the plugin.** If a theme hub is
a page you want to rank, the asset is the internal links pointing *at* it. The stored
relationship is only a bookkeeping entry; `[mavo_hub_strip]` on 60 children is the actual
SEO work. So completeness of *link back* matters more than completeness of *assignment* —
which is the reverse of how the admin currently reads, where assignment is the prominent
action and link-back is an audit tab.

**Cannibalisation is now a real failure mode, and nothing in the model detects it.**
*City trips en famille*, *Week-end en famille*, *Escapade de 3 jours avec enfants* are one
query cluster and three thin pages fighting each other. The rule that follows: **one theme
hub per query cluster, not per concept.** A theme hub earns existence only if you can name
the French query it targets and you'd write 1200+ original words above the list.
Everything else is a tag. That's an editorial rule, but the plugin can support it — a hub
health signal for *theme hubs with fewer than N children* is a decent proxy for "this page
shouldn't exist yet".

**Theme tree depth: two levels, hard stop.** Each level must target a distinct query. Three
levels of theme means level 2 and level 3 are competing.

**And the honest one: your winning pages are intersections, so the pure theme axis will
stay small.** *Voyage en famille* is a query you will not rank for against Routard and the
OTAs. *Paris en famille*, *Italie avec un bébé*, *roadtrip Écosse en famille* — those you
can win. Since intersection hubs are marked `geo` in this model, expect maybe 8–12 pure
theme hubs total and a much larger geo tree. That's fine, but it means the theme axis is a
*linking and grouping* device more than a traffic source, and you shouldn't over-invest in
growing it.

### Revisiting effective-theme inheritance

Still worth adding, but narrower than part 1 suggested. Inherited theme hub is good for
**breadcrumbs and `mavo-for-you`** — it fills the blanks for free. It should *not* count as
"assigned" for the link-back audit, because an article inheriting *City trips* through
*Paris en famille* has no reason to link to *City trips*, and treating it as owed a link
would flood the report with false gaps.

So: `mavo_get_effective_hub()` as a distinct read-time helper, used for display and
downstream consumers; the audit keeps reading the stored value. Two functions, clearly
named, no ambiguity about which one the report ran on.

---

## Part 3 — Recommended shape for mamanvoyage

Keep the two-axis model, keep single-primary, keep hubs-as-pages. The structure is sound;
the gaps are all read-time and additive.

- **Geo carries the site.** Depth 3–4: *France → Île-de-France or Paris → Paris en famille
  → article*. Every article gets a geo hub — make that the hard editorial rule, since it's
  the one an editor can always answer.
- **Theme stays shallow and small.** Six to ten hubs, one level, maybe two. Resist growing
  it; every new theme hub that's really a facet should have been a tag.
- **Intersection hubs are geo**, with a theme parent. Then add effective-theme inheritance
  so the articles beneath them stop being thematically blank.
- **Add the fan-out signals** before adding features — they're what tells you the tree is
  going wrong while it's still cheap to fix.

## Part 4 — Proposed build order

1. **`mavo_get_effective_hub( $post_id, $type )`** — read-time inheritance through
   same-axis ancestors of the *other* type's hubs. Small, additive, unblocks intersection
   hubs. Display and `mavo-for-you` only; the audit keeps reading stored values.
2. **Surface link-back on the hub screen**, not only in the audit — "42 of 60 children link
   back" with the missing ones one click away. That's the number that moves rankings.
3. **Fan-out health signals** — geo hubs over ~25 direct children (split it), theme hubs
   under ~8 (it's a tag, not a page).
4. **Curated child order** via `_mavo_hub_order` on the child, if and when you want
   hand-picked lists on hub pages. Legal under the model, no hub-side storage.
5. **Polylang parent-mismatch diagnostic** — whenever the second language becomes real.

None of that requires changing the data model: the three meta keys survive the decision
that the theme hub is a page.
