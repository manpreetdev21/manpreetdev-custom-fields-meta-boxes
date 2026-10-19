# Manpreetdev Custom Fields and Meta Boxes

Field groups, meta boxes and a developer-friendly field API for WordPress.

- **Version:** 1.16.0
- **Author:** Manpreet Singh
- **Requires PHP:** 8.1
- **Requires WordPress:** 6.8
- **License:** GPL-2.0-or-later
- **Text Domain:** `manpreetdev-custom-fields-meta-boxes`
- **Namespace:** `WPCMB`


## Build status

| Phase | Scope | Status |
|-------|-------------------|--------|
| 1 | Core architecture | ✅ Done |
| 2 | Admin UI | ✅ Done |
| 3 | Database | ✅ Done |
| 4 | Field API | ✅ Done |
| 5 | Field types | ✅ Done |
| 6 | Repeater | ✅ Done |
| 7 | Flexible content | ✅ Done |
| 8 | Frontend forms | ✅ Done |
| 9 | REST API | ✅ Done |
| 10 | Blocks | ✅ Done |
| 11 | Integrations | ✅ Done |
| 12 | Testing | ✅ Done |

## Architecture

Four moving parts, nothing more:

```
manpreetdev-custom-fields-meta-boxes.php   Bootstrap. The only procedural file.
  └─ Container          Lazy service container, shared instances, auto-wiring.
  └─ Plugin             Singleton. Owns the container, collects and boots modules.
      └─ Module         Abstract base. One per feature. boot() registers hooks.
          └─ Bootable   The contract every module implements.
  └─ Installer          Activation / deactivation. Static — runs outside the lifecycle.
```

**Request flow**

1. WordPress loads `manpreetdev-custom-fields-meta-boxes.php`, which defines constants and registers an autoloader (Composer's if `vendor/` exists, a PSR-4 fallback otherwise).
2. `wpcmb()->init()` hooks `Plugin::boot()` to `plugins_loaded` at priority 5 — early enough that other plugins can act on `wpcmb/booted`.
3. `boot()` runs the module list through the `wpcmb/modules` filter, resolves each class from the container, skips any whose `is_enabled()` returns false, and calls `boot()` on the rest.
4. `wpcmb/booted` fires. Add-ons build on top from here.

**Why a container.** Modules never construct their collaborators, they pull them from the container, so a field type in phase 5 can be swapped in tests or replaced by a site without editing plugin code. Auto-wiring keeps registration to zero for the common case: any `Module` subclass is constructed with the container automatically.

**Why one singleton.** `Plugin` only. WordPress gives one plugin lifecycle per request and hook callbacks need a stable target. Everything else is a plain object with an explicit dependency.

**Why `is_enabled()`.** Admin-only modules should not cost anything on front-end requests. The gate runs before `boot()`, so a skipped module registers no hooks and loads no assets.

## Installation (development)

```bash
composer install          # dev tooling + autoloader
composer dump-autoload -o # autoloader only
```

The plugin runs without Composer via the fallback autoloader in the bootstrap.

## Where field groups live

Field groups are a private post type (`wpcmb_field_group`), not a custom table. WordPress already supplies the list table, search, pagination, sorting, revisions, autosave, trash, capabilities and WXR export for a post type; a bespoke schema would reimplement all of that and add a migration surface for no gain at field-group scale.

The whole group configuration — fields, location rules, display settings — is one JSON value in `_wpcmb_config` post meta. One meta value means one read, and it is registered through `wp_post_revision_meta_keys` so revisions cover the group as a unit.

Two derived rules follow from this:

- **Active = published.** A draft group is inactive. There is no second "active" flag to fall out of sync with the post status.
- **Every capability maps to `manage_options`** (filter: `wpcmb/capability`). No role is modified, so nothing needs granting on activation or cleaning up on uninstall.

## Where field values live

Also in native storage: post, term, user and comment meta, or prefixed options for options pages and widgets. There is no value table. A value is therefore already visible to `WP_Query`'s `meta_query`, the core REST meta endpoints, WP-CLI, migration tools and a plain database export — with or without this plugin installed.

A value is stored under the field's own name as **one row**. Structured values (repeater rows, groups, flexible content) are stored whole rather than flattened into a row per sub value: one row means one read for a repeater of any size, and deleting it cannot orphan sub values. The trade-off is that sub values are not individually queryable by `meta_query`; if that is ever needed, mirror those sub fields into flat companion keys rather than flattening canonical storage.

Two details that are easy to get wrong and are covered by `tests/values-check.php`:

- **Slashing.** `update_metadata()` runs `wp_unslash()` on whatever it is given, so `Values` slashes on the way in. Written raw, a value like `C:\Users\test` loses its backslashes. Options are not unslashed and so are written as-is.
- **Unstored vs. empty.** Reads distinguish "never saved" from "saved as an empty string", so a field's default applies only until it is first saved — not every time it is cleared.

Field names resolve to field definitions through an index over the cached groups, so no companion `_key` meta row is written and values stay readable when a group's location no longer matches.

### Object references

Every entry point accepts loose identifiers and resolves them once through `ObjectRef`:

| Identifier | Resolves to |
|---|---|
| *(nothing)* | the current post |
| `42`, `'42'`, `'post_42'`, `WP_Post` | post 42 |
| `'term_5'`, `WP_Term` | term 5 |
| `'user_2'`, `WP_User` | user 2 |
| `'comment_9'`, `WP_Comment` | comment 9 |
| `'options'`, `'options_theme_settings'` | an options page |

Anything unrecognised resolves to an invalid reference that reads and writes nothing, rather than silently addressing post 0. The `wpcmb/object_ref` filter lets a custom location claim its own scheme.

## Template functions

```php
wpcmb_get_field( 'byline' );                    // current post
wpcmb_get_field( 'byline', $post_id );
wpcmb_get_field( 'colour', 'term_5' );
wpcmb_get_field( 'api_key', 'options' );
wpcmb_the_field( 'byline' );                    // echoed, escaped
wpcmb_get_fields( $post_id );                   // every applicable value
wpcmb_has_field( 'byline', $post_id );          // stored? ignores defaults
wpcmb_update_field( 'byline', 'Manpreet', $post_id );
wpcmb_delete_field( 'byline', $post_id );
wpcmb_get_field_object( 'byline' );             // the definition
wpcmb_get_field_groups( $post_id );             // groups on this object
wpcmb_validate_values( $values, $post_id );     // errors keyed by field name
```

These are the only global functions the plugin defines — thin wrappers over container services, so themes get a short procedural API and the implementation stays swappable.

### The row loop

```php
wpcmb_have_rows( 'slides' );                    // another row coming?
wpcmb_the_row();                                // move onto it
wpcmb_get_row();                                // the current row, unmoved
wpcmb_get_sub_field( 'heading' );               // a value from it
wpcmb_the_sub_field( 'heading' );               // echoed, escaped
wpcmb_get_sub_field_object( 'heading' );        // the sub field's definition
wpcmb_get_row_layout();                         // flexible content's layout
wpcmb_get_row_index();                          // position, counting from one
wpcmb_count_rows();                             // rows in this loop
wpcmb_reset_rows();                             // after a break
wpcmb_add_row( 'slides', $row, $post_id );
wpcmb_update_row( 'slides', 1, $row, $post_id );
wpcmb_delete_row( 'slides', 1, $post_id );
```

```php
if ( wpcmb_have_rows( 'slides' ) ) {
	while ( wpcmb_have_rows( 'slides' ) ) {
		wpcmb_the_row();

		echo '<h2>' . esc_html( wpcmb_get_sub_field( 'heading' ) ) . '</h2>';

		while ( wpcmb_have_rows( 'bullets' ) ) {   // nested, no identifier
			wpcmb_the_row();
			wpcmb_the_sub_field( 'text' );
		}
	}
}
```

**A repeater value is already a list of rows**, so `foreach ( wpcmb_get_field( 'slides' ) as $row )` works and is shorter for a flat repeater. The loop exists for the two things a foreach cannot do:

1. **Reach a sub field's definition.** A sub field is not in the top-level name index, so `wpcmb_get_field_object()` cannot find one — `wpcmb_get_sub_field_object()` is the only way to a sub field's label, choices or default. It is also why a sub field *added to a repeater after its rows were saved* reads as its default rather than as nothing.
2. **Walk a nested repeater without nesting the loops by hand.** A loop opened while another is open belongs to the row it was opened inside, so the identifier is only passed at the top.

`wpcmb_have_rows()` looks without moving, which is what makes the `if` around the `while` safe — it does not eat the first row. Moving is `wpcmb_the_row()`'s job, so a `while` body that never calls it never ends. That is ACF's contract, deliberately, because a site moving across has this spelling in every template it owns.

After breaking out of a loop part way through, call `wpcmb_reset_rows()`. The pointer is still mid-list otherwise. A loop left open on one object will not answer for another, so an archive template cannot render the previous post's rows, but it will resume mid-list for the same one.

The writers count rows from one, as the loop reports them, and go through the same sanitizing an edit screen does — so `wpcmb_add_row()` cannot store what the admin would refuse, and it rewrites the rows already there as they were *stored* rather than as they were formatted.

### Registering groups in code

```php
add_action( 'wpcmb/register_field_groups', function () {
	wpcmb_register_field_group( array(
		'key'      => 'group_page_details',
		'title'    => 'Page Details',
		'fields'   => array(
			array( 'name' => 'byline', 'label' => 'Byline', 'type' => 'text', 'default' => 'Staff' ),
		),
		'location' => array(
			array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ),
		),
	) );
} );
```

Registered groups are never written to the database, so they can live in version control. A **stored** group with the same key wins — which is what makes "export to PHP, keep editing in the admin" work. The hook fires lazily on the first group read, not on `init`, so a field read before `init` still sees them and a request that reads nothing pays nothing.

## Field group settings

| Setting | What it does |
|---|---|
| Position | Which meta box context the group renders in: after the content, in the side column, or in the advanced area. |
| Style | A standard meta box, or seamless — no box, just the fields. |
| Label placement | Labels above the fields, or beside them. |
| Instruction placement | A field's instructions below its control, or above it. Below is the default, because that is where they have always been. |
| Order | Sorts the group against others on the same screen, and names the page when two groups share one options page. |
| Description | A sentence above the fields. |
| Display title | The heading on the edit screen, when it should differ from the group's own title. A group can be *Product — pricing* where it is administered and *Pricing* where it is filled in. |
| Show in REST API | Off keeps the group's fields out of the REST API, and out of the block editor with it — `register_meta()` is what puts them in both. On for every group saved before this setting existed, so nothing disappeared from an API somebody was already calling. |
| Hide on screen | Removes core elements from the edit screen: permalink, content editor, excerpt, custom fields, discussion, comments, revisions, slug, author, format, page attributes, featured image, categories, tags, trackbacks. |
| Options page | Menu location, capability and icon for the page an `options_page` rule creates. See [Options pages](#options-pages). |

**Hide on screen is two mechanisms.** Most of those elements are meta boxes,
and are removed with `remove_meta_box()`. The permalink row and the content
editor are part of the edit form itself, so they are hidden by a class this
plugin puts on the body — which is why those two work in the classic editor
and not in the block editor, where the equivalents are core's own panels.
Those two options had been in the list since the beginning and had never
hidden anything: `remove_meta_box()` was being called with an id that does not
exist, and failing silently.

## Location rules

Rules are an OR list of AND groups: the group shows when every rule in at least one rule group matches. Three deliberate behaviours:

- **A group with no rules matches nothing.** An unconfigured group stays out of the way instead of appearing site-wide.
- **List-valued parameters mean "is one of".** A user's roles, the taxonomies a post has terms in. `!=` then means "is none of".
- **An inapplicable parameter never matches, negated or not.** Otherwise "post type is not page" would start matching users, terms and comments.

`Context` computes parameter values on demand and memoises them. A typical rule set tests one or two parameters, and several of the others cost a term or template query — building a full description of the screen up front would run those queries to answer questions nobody asked.

### Two rules that could never match

**A rule saved with no value.** A value control with a fixed set of choices is
a `<select>`, and a select always shows something: given a value that is not
one of its options — a rule just added, or one whose parameter has just
changed — the browser showed the first option while the stored value stayed
empty. The screen read *Post Type is Posts*, the summary agreed, and the group
saved as `post_type == ""`, matched nothing and appeared on no screen at all.

The values are now reconciled before the state is written, which is the part
that matters: doing it while the controls are drawn corrects the screen and
leaves the saved value behind, because the state is synced to the form before
the controls are built.

A group saved before this repairs itself the next time its editor is opened
and saved.

**`comment` matched the wrong screen and never the right one.** The rule
offers post types, so "Comment is post" means the comments on posts of that
type — but it read the post type of the *referenced* object, which is only
set when the reference is a post. So it matched post edit screens, where it
was never meant to apply, and never matched a comment screen, where it was.

Both are covered by `tests/integration.php`, which now walks every location
rule parameter against a real object of its own kind and checks three things
for each: that it resolves, that a rule naming that value matches, and that a
rule naming something else does not.


## Field types

**50 types, 9 classes.** Types are grouped by behaviour, not by name. Thirty single-line inputs differ by an HTML `type` attribute and a sanitizer — thirty classes would be thirty places to fix the same bug.

| Class | Types |
|---|---|
| `Input` | text, number, email, url, password, hidden, range, color, date, time, datetime, phone, slug, uuid, currency |
| `Textarea` | textarea |
| `Choice` | select, checkbox, radio, toggle, button_group, rating, country, state |
| `Media` | file, image, gallery, video, audio |
| `Relationship` | post_object, page_link, relationship, taxonomy, user |
| `Editor` | wysiwyg, code, json, html |
| `Link` | link |
| `Enhanced` | icon, map, signature, qr, barcode, embed, address |
| `Structure` | message, separator, tab, accordion, group |

Add one with `wpcmb/register_field_types`; a custom type gets the wrapper, conditional logic, validation display, layout and a settings UI for free, because field types own only their control.

### What is deliberately not bundled

Date, time, colour and number pickers are the browser's. Rich text is `wp_editor()`; code editing is `wp_enqueue_code_editor()`. Media is the WordPress media library, which already does upload, drag-drop, multi-select, alt text and image editing — a second one would not share the user's uploads. Country and state lists are short stubs that defer to WooCommerce or to `wpcmb/field/choices`, rather than carrying a second ISO table. Map tiles, QR and barcode encoders need a provider, so those fields render their stored text until a site supplies one through `wpcmb/field/enhanced_config` — better than a key-less map that silently shows nothing.

### Rules that hold across every type

- **Only offered values are stored.** Choice and relationship fields intersect the submission with the options actually rendered, so a hand-built request cannot store a value the form never contained.
- **Sanitizers are idempotent.** A value is cleaned on the edit screen to validate it and again in the value pipeline on write. Non-idempotent sanitizers would progressively strip stored values.
- **Validation runs before sanitizing.** A sanitizer discards what it cannot make safe; validating afterwards would see a malformed email as an empty optional field and report nothing, so the user would lose the value with no explanation.
- **Structural fields store nothing.** Tabs, accordions and messages never create an empty meta row and are never validated.
- **Only native controls' formats are accepted.** A date field stores an ISO date or nothing — never free text from a crafted request. A signature stores a PNG data URI or nothing, so it cannot smuggle a scriptable SVG into a `src`.
- **Escaping differs by type, on purpose.** `wysiwyg` is `wp_kses_post()`; `html` is verbatim only for users with `unfiltered_html`; `code` and `json` are stored raw because this plugin never renders them as markup.

### Per-type settings

Each type declares its own settings and the field editor builds the UI from
that declaration, so adding a setting is a PHP change and nothing else. Which
tab a setting appears under is part of the declaration.

| Type | Settings |
|---|---|
| text, password, phone, slug | maxlength, minlength, pattern, prepend, append |
| number, range, currency | min, max, step, prepend, append |
| date, time, datetime | return format |
| textarea | rows, maxlength, new lines |
| select, country, state | choices, multiple, min, max, empty choice on/off, empty label, return format |
| checkbox | choices, multiple, min, max, layout, select all, other values, return format |
| radio, button_group | choices, layout, return format |
| rating | maximum rating |
| toggle, true_false | toggle label, on label, off label |
| file, video, audio | library, **upload type**, file types, min size, max size, preview size, return format |
| image, gallery | the above, plus min and max width and height |
| gallery | the above, plus min and max items |
| post_object, page_link, relationship | post types, multiple, min, max, empty choice, return format |
| taxonomy | taxonomy, assign the terms, load from the post's terms, and the above |
| user | roles, multiple, min, max, empty choice, return format |
| wysiwyg | rows, toolbar, tabs, media buttons, return format |
| code, json, html | rows, return format, decoded array |
| link | return format |
| embed | return format, width, height |
| icon | icon set |
| repeater | min, max, layout, button label, row label, collapse after, CSV |
| flexible | min, max, button label, collapse after, per-layout min and max |
| message | message |
| accordion | open by default |

**File and image limits are rules, not hints.** The media picker is filtered by
them as a convenience, and every one is checked again on save — because a value
can also arrive from a public form, from the REST API, or from code, none of
which open a picker. A size written bare is kilobytes, which is how the media
library writes its own limits; `2MB` and `500 KB` work too.

**Upload type** is picked from a list the site keeps in Settings, so "what this
site accepts" is answered once rather than retyped into every field. *Allowed
file types* is still there as the escape hatch for anything the list does not
cover, and overrides it. Either way a field accepts its own kind of file: an
image field takes images whether or not anybody filled the setting in.

**"Other values"** is offered on a checkbox list only. What is typed joins the
list of values, so a stored value reads the same whether it was ticked or
typed, and it comes back as a normal ticked choice next time. On a radio or a
dropdown the box and the control would fight over one value, which needs
script to arbitrate and leaves the field unusable if that script does not load.

**A taxonomy field can own the post's terms.** With *assign the terms* on, a
save calls `wp_set_object_terms()` as well as storing the value, replacing
whatever that taxonomy held. With *load from the post's terms* on, the field
shows what the post actually has rather than what the field last stored. Both
are off by default, because a taxonomy field is often a second, editorial list
that has nothing to do with the post's real terms.

### Settings this plugin does not offer, and why

- **A date display format, and a first day of the week.** The date, time and
  colour pickers are the browser's own, and a browser draws them in the
  viewer's locale. There is no format to set without bundling a picker.
- **A relationship filter bar, and featured-image thumbnails in it.** Both
  belong to a two-column relationship UI this plugin does not have; its
  relationship field is a select, and the searchable multiple select already
  covers finding one item among many.
- **A map centre, zoom and height.** The map field is a coordinate pair, an
  address and a link out — deliberately not a tile canvas — so there is
  nothing to centre, zoom or resize.

### Extending a type's rules

Two hooks on `FieldType` carry what a sanitizer cannot see:

```php
// Reject a value this type cannot accept. Runs after the generic checks —
// required, length, range, pattern — and for every value on its way to
// storage, whatever it arrived from.
public function validate( mixed $value, array $field ): string;

// Act on the object a value was just written to, or read one from it.
public function on_save( mixed $value, array $field, mixed $ref ): void;
public function on_load( mixed $value, array $field, mixed $ref ): mixed;
```

The media field's file limits use the first; the taxonomy field's term
assignment uses the other two. A registered third-party type gets all three,
because they are reached through `wpcmb/validate` and the value pipeline
rather than from any one caller.

## Repeater

Rows are one indexed array under the repeater's own name — the same whole-value storage everything structured uses. A repeater of any size is **one meta row and one read**.

**Nesting needs no code.** Sub fields render through the shared renderer, which owns input naming, so `repeater → repeater → group` produces correctly nested names without any type knowing how deep it is. Sanitizing recurses the same way.

**New rows come from the server, not from a cloned template.** A cloned `wp_editor()` is a dead editor: the markup is only half of what that control is. The `wpcmb_repeater_row` endpoint renders a fresh row exactly like the ones already on the page. Duplicating an existing row is the one case that clones, and it re-runs field initialisation afterwards.

**Row order survives gaps.** The browser posts rows keyed by their on-screen index, which has gaps once rows are removed. Keys are sorted numerically then discarded — a string sort would put row 100 before row 12.

**Every row has every declared sub field**, stored as null when absent, so a template can index into a row without checking each key first. Undeclared columns are dropped.

**Reordering is keyboard-accessible.** Move up/down buttons are the baseline; drag-to-reorder is the enhancement layered on top. Both call the same renumbering code.

### CSV

Per-repeater import and export, opt-in via a field setting.

- Export takes what is **on screen**, including unsaved edits, and only columns with an honest single-cell representation — a nested repeater or gallery is skipped rather than flattened into something that cannot be imported back.
- Cells beginning `=`, `+`, `-` or `@` are prefixed with a quote, so an exported `=HYPERLINK(...)` is text in a spreadsheet rather than a live formula.
- Import **appends** and matches columns **by header name**, dropping unknown ones. Nothing is written until you save the screen — an import that stored directly would be an unreviewable, unundoable change.

### The ceiling

Every row renders. Pagination is not bolted onto a single form post, because a paginated repeater must save per row — a per-row AJAX save endpoint and a concurrency story. Rows past `collapse_after` (default 10) start collapsed, which keeps the screen usable well past where the DOM alone would be the problem. Marked with a `ponytail:` comment.

## Flexible content

`Flexible extends Repeater`, because that is what it is: a repeater whose rows differ in shape. Order, gaps, limits, nesting, row chrome, reordering and the server-rendered new row are inherited, not rewritten.

Each row records its layout under `_layout`, so a layout can be renamed, have its fields reordered, or gain a sibling without touching stored rows.

```php
foreach ( wpcmb_get_field( 'sections' ) as $row ) {
	switch ( $row['_layout'] ) {
		case 'hero_banner':
			echo esc_html( $row['heading'] );
			break;
		case 'cards':
			foreach ( $row['cards'] as $card ) { /* … */ }
			break;
	}
}
```

Layouts carry an **icon**, a **category** (both used to group the picker) and an optional **max uses**, enforced on save rather than only in the picker.

**Two rules about rows whose layout has gone:**

- On screen, the row still renders, marked *Unknown layout*, with its values preserved in hidden inputs — so saving the page does not destroy data the user has not seen yet.
- On save, the row is **dropped rather than coerced** into another layout. Re-interpreting it would scramble its values into fields that were never meant to hold them.

The layout picker is a keyboard-reachable menu grouped by category, closing on Escape and on outside click. Collapsed rows show a live preview — the first non-empty text value — because a column of rows all saying "Row 3" is unnavigable.

## The admin menu

```
Meta Boxes
├── Overview
├── Field Groups
├── Settings
├── Tools
├── Contact form            ← a form's submissions, one entry per form
│   (wpcmb_contact_f_8a21)
└── Site Options            ← an options page placed in this menu
    (site-settings)
```

**The order is fixed, and sorted once after everything has registered.** It
has to be: a post type registers on `init`, an admin page on `admin_menu`, and
a form's submissions type does not exist at all until its first submission
arrives — so left alone, this menu rearranged itself as a site was used, and
the entry somebody clicked yesterday was somewhere else today. The fixed
screens come first and the things a site makes come last, so the menu grows at
the bottom rather than in the middle.

Core has no filter for the order of items *inside* a menu — `custom_menu_order`
and `menu_order` reorder top-level menus only — so `Menu::order_submenu()`
sorts `$submenu['wpcmb']` directly. It only ever reorders entries already
there.

**Forms and options pages name themselves twice**: the title, and underneath
it the identifier — a form's post type, an options page's slug. Two forms can
be called the same thing, and the identifier is both what tells them apart and
what a query or a `wpcmb_get_field()` call would use.

## Options pages

Set a group's location to **Options Page is `site-settings`** and the screen
appears by itself. No `add_menu_page`, no render callback, nothing in the
theme:

```
Location: Options Page is site-settings
  ↓
Admin menu → Site Settings (site-settings)     ← created, rendered and saved
```

The rule is the only place the page is configured, so there is no second list
to keep in step: delete the rule and the page goes with it. Two groups naming
the same slug share one screen, each as its own panel.

**Where it appears.** By default a top-level menu entry directly below
Settings, labelled with the group's title and the slug underneath it — a site
can have several of these, built from whatever somebody typed into a rule, and
"Site Options" alone says neither which plugin made it nor which slug it
stores against. The page title carries the plugin's name for the same reason.

**The group's Options Page settings change all three.** Menu location moves the
page under the plugin's own Meta Boxes menu, or under Settings, Tools,
Appearance, Posts, Media or Users, instead of giving it a menu of its own; Capability decides who may open and save it; Menu icon
takes a Dashicon name or an image URL, for a top-level entry.

The capability is one setting, not two. Lowering it would otherwise put the
menu item in front of an editor and then refuse them at the door, because the
default rule for an options object is `manage_options` — so the permission
check consults the page's own capability for the pages this plugin created,
and leaves every other options object on the default rule. A page set to
`edit_pages` is a page an editor can actually use.

The group that names the page decides its menu. A second group sharing the
same slug contributes its fields, not its menu — one page cannot sit in two
places.

**Reading the values** needs no new API. An options page is an object like any
other, addressed by `options_` plus the slug:

```php
wpcmb_get_field( 'company_name', 'options_site-settings' );
wpcmb_get_fields( 'options_site-settings' );
```

Values are stored as options named `wpcmb_{slug}_{field}`, autoloaded off, so a
field called `siteurl` cannot collide with anything of WordPress's. They are
also available over REST at `/wpcmb/v1/values/option/{slug}`, behind the
page's own capability.

**Everything else the plugin does still applies**, because the page renders
through the same renderer as a meta box: conditional logic, repeaters,
flexible content, validation and the save gate all work here without knowing
this screen exists. A required field on an options page blocks the save the
same way it blocks a publish.

The two filters are still there, for whatever the settings do not cover:

```php
// Move one page under Settings, or anywhere else.
add_filter( 'wpcmb/options_page/args', function ( $args, $slug ) {
	if ( 'site-settings' === $slug ) {
		$args['parent'] = 'options-general.php';
	}

	return $args;
}, 10, 2 );

// Create a page no rule points at, and let the plugin render and save it.
add_filter( 'wpcmb/options_pages', function ( $pages ) {
	$pages['imported'] = array( 'title' => 'Imported Settings', 'order' => 0 );

	return $pages;
} );
```

`wpcmb/options_page/saved` fires after each save, with the slug, the submitted
values and any validation errors.


## Front-end forms

```
[wpcmb_form group="group_contact0001"]
[wpcmb_form group="group_contact0001" action="post" post_type="post" guests="1" redirect="/thanks/"]
```

```php
wpcmb_form( array( 'group' => 'group_contact0001', 'action' => 'post' ) );
wpcmb_form_shortcode( 'group_contact0001' );   // the shortcode string
```

The editor's Display Settings box shows both shortcodes, ready to copy.

### What a bare shortcode does

```
[wpcmb_form group="group_abc123"]
```

That creates a **draft post** carrying the submitted values, from a
**signed-in** visitor. Both halves of that used to be wrong:

- `action` defaulted to `values`, which needs an object to store against,
  while `object` defaulted to `new`, which is not an object. The pair
  described a form with nowhere to put anything: it rendered correctly and
  then answered *"That could not be saved. Please try again."* on submit, for
  everyone including administrators. `action` now defaults to `post`, which is
  the only thing a form can do without being told what it is editing. The
  combination that still cannot work says so when the form renders, rather
  than when somebody fills it in.
- The editor offered that snippet under the words *"render this group as a
  public form"*, but a form takes submissions from signed-in visitors only
  unless told otherwise — so the first thing a visitor saw was *"You need to
  sign in to use this form."* The editor now shows two snippets and says which
  is which.

For a form anybody may submit:

```
[wpcmb_form group="group_abc123" guests="1"]
```

`guests` stays off by default on purpose. A group key is not a secret, and
turning every pasted shortcode into an anonymous write endpoint is not a
default anyone should inherit. Uploads need an account regardless — see
`wpcmb/form/allow_uploads`.

### Where submissions go

Each group used as a form gets its own post type for its submissions, named
from the plugin's prefix and the group's own name:

```
Group "Contact"  →  wpcmb_contact_1f4a  →  Meta Boxes → Contact
```

One type per group rather than one for all of them: submissions to a contact
form and to a job application have different fields, different columns and
different people reading them, and a shared type mixes them into one list that
suits neither. The four characters are the start of the group's key — a post
type may be twenty characters and no more, so the name is cut to fit and the
fragment keeps "Contact" and "Contact Us" apart. The readable name is in the
labels, which is what anybody sees.

A type is registered once it holds something. Nothing needs registering for a
submission to be *stored* — a post type is only a string in a row — so a group
that has never been submitted adds no menu entry, and the first submission
makes one appear.

Each submission records the group it came from and the page it was submitted
from, and the edit screen shows the values read-only, labelled by the group's
fields. Read-only on purpose: the group's location rules point at wherever its
form is, not at this screen, so editable controls would offer a save that no
rule would honour.

| | |
|---|---|
| Status | `publish`. The type is not viewable, so nothing is exposed — and leaving records as drafts labels every one of them "Draft" in a list where the word means nothing. A form pointed at real content still cannot publish it. |
| Author | Whoever was signed in, or nobody. The list shows *A visitor* for an anonymous submission. |
| Add New | Denied. Submissions arrive from forms; an Add New button offers an empty record with no form behind it. |

Override it per form with `post_type="something_else"`, or globally with
`wpcmb/submissions/post_type_args`.


### Repeaters on a public form

Repeater rows are rendered by the server, not cloned in the browser, because a
cloned `wp_editor()` is a dead editor. That means adding a row is a request,
and on the front end the caller is a visitor with no editing capability — so
the Add row button did nothing at all out there, and `repeater.js` was not
even loaded.

Both are fixed, and the request is authorised by the form's own signed
configuration rather than by a capability:

| The request carries | Result |
|---|---|
| A valid signature, for a field of that group | The blank row |
| Nothing | 403 |
| A tampered signature | 403 |
| A valid signature, but a field from another group | 403 |

What a visitor can obtain this way is the blank markup of a field on a form
the site chose to publish, which is already in the page they are looking at.
CSV import and export stay behind the editing capability, so those two buttons
are not drawn on a public form at all rather than being drawn and answering
403.


Fields are drawn by the **same renderer the admin uses**, so a field type behaves identically on both sides and nothing is implemented twice. The form is a real `<form>` with a real action: with JavaScript blocked it posts, redirects and reports its result normally. The script only adds submitting without a reload and showing errors in place.

### The security model

The form's configuration — which group, what it creates, what status, where it redirects, who gets notified — **travels with the submission in a signed field**. Without that, any visitor could edit the markup and post back `post_status=publish` or their own notification address.

- `wp_hash()` signs the payload against the site's own salts, so nothing needs storing and a signature is worthless on another site.
- After verification the payload is intersected with the known defaults, so a correctly signed payload still cannot introduce settings the plugin does not define.
- **A public form never publishes.** `draft` is the default and anything beyond `pending` is refused unless the submitter can `publish_posts`.
- **Guests are refused unless the form opts in** with `guests="1"`.
- **Uploads require `upload_files`.** A public upload endpoint is a different security problem from a public form and should be a deliberate decision — override with `wpcmb/form/allow_uploads`.
- Redirects go through `wp_validate_redirect()`, so a form cannot bounce a visitor off-site.

### Spam protection

No captcha, nothing for a person to prove:

- A **honeypot** field, moved off-screen with CSS rather than `hidden` — form-filling scripts skip fields the browser reports as hidden. `aria-hidden` and `tabindex="-1"` keep it away from screen readers and keyboard users.
- A **signed timestamp**. Submissions faster than `MIN_SECONDS` are refused, and the signature means the timestamp cannot simply be back-dated.

A rejected submission gets the same wording as a genuine failure. Telling a bot which check it failed is free help for whoever wrote it.

### Template override

Copy `templates/form.php` to `manpreetdev-custom-fields-meta-boxes/form.php` in your theme, or filter `wpcmb/form/template`.

## REST API

**Most of it is core's.** Fields are registered as typed meta with a JSON Schema, so values appear on the endpoints they belong to:

```
GET  /wp/v2/pages/12?context=edit     → { "meta": { "byline": "Manpreet", … } }
POST /wp/v2/pages/12                  → { "meta": { "byline": "New" } }
```

That buys authentication, per-object permissions, request validation, type casting and self-documenting schema output — all maintained by WordPress. A bespoke value endpoint would reimplement every one of those and still not appear alongside the post it describes.

Which object types a group registers against comes from **its own location rules**, so a group targeting pages does not put its fields on posts or users.

Two routes remain, for the things core cannot know about:

| Route | Method | Permission |
|---|---|---|
| `/wpcmb/v1/field-groups` | GET | `manage_options` |
| `/wpcmb/v1/field-groups/{key}` | GET | `manage_options` |
| `/wpcmb/v1/values/{type}/{id}` | GET | can read that object |
| `/wpcmb/v1/values/{type}/{id}` | POST | can edit that object |

`{type}` is `post`, `term`, `user`, `comment` or `option` — the last covering options pages, which have no core object to hang meta off.

### Permissions

`Fields\Permissions` is the single answer to "who may read or write these values", used by the edit screens, the front-end forms and REST alike. **Values inherit the permissions of the object they belong to** — a field on a post is readable by whoever may read that post and writable by whoever may edit it. The plugin never invents a looser rule.

### Schema

Schemas are **derived from field definitions**, not hand-written, so adding a sub field to a repeater updates the endpoint's contract with it. `OPTIONS` on any route returns the full description — that is the documentation, and unlike a hand-maintained route list it cannot go stale.

- Types map through: `number`→number, `toggle`→boolean, `image`→integer, `checkbox`/`gallery`→array, `link`/`address`/`group`→object.
- Formats are declared (`email`, `uri`, `date-time`, `hex-color`), so core rejects malformed values before plugin code runs.
- Choice fields declare an `enum` — including the empty string, or an optional field could never be cleared.
- Repeaters describe their rows all the way down.
- **Flexible content deliberately does not** merge every layout's properties into one shape: that would let a row of one layout claim another's fields and still validate. The item schema constrains `_layout` to the known names and leaves the rest open.
- Structural fields (message, tab, accordion) are never exposed — reads and writes see exactly the same set.

## Blocks

Tick **Register this group as a block** in the field group editor and give it a name. The group becomes a Gutenberg block in a "Custom Meta Box" category, with an icon, description, keywords, chosen supports and optional inner blocks.

**Values live in the block's own attributes, not in post meta.** That is what makes a block portable: copy it to another post, another site or a synced pattern and its content travels with it. Meta would tie it to the object it was first placed on.

**No build step.** `blocks.js` is written against `wp.element` directly rather than JSX, so what ships is what runs and a bug is debuggable in the browser without a source map.

**The editor UI is the same PHP-rendered form the admin uses**, fetched over AJAX and injected into the block. A field type works in a block the moment it works anywhere else — repeaters, flexible content and conditional logic included — rather than needing a second implementation of fifty field types in React.

### Rendering

`render_callback` only; nothing is saved into post content, so a template change takes effect on posts that already exist instead of invalidating them.

Template resolution, first match wins:

1. `manpreetdev-custom-fields-meta-boxes/blocks/{block-name}.php` in the theme
2. `manpreetdev-custom-fields-meta-boxes/block.php` in the theme
3. `templates/blocks/{block-name}.php` shipped by a plugin
4. `templates/block.php` — the generic fallback

So a new block renders something immediately rather than an empty region that looks broken. Or filter `wpcmb/block/template`.

Inside a block template, `wpcmb_get_field( 'heading' )` returns **that block's** value — the `wpcmb/value/pre_get` filter serves the block's attributes for the duration of the render, so a block template reads like a theme template.

### Two decisions worth knowing

- **All values sit under one `data` attribute**, not one attribute per field. Adding or renaming a field then does not invalidate blocks already saved in content.
- **A block name is never derived from the group title.** The name is part of saved content; deriving it would mean renaming a group silently orphans every block already placed.

Attributes arrive from the editor and are **cleaned by their own field types before anything is output** — the same sanitizers the edit screens, forms and REST use, so a block cannot become a way to store what the other three would refuse.

## Integrations

Every integration is **dormant unless its host plugin is present** — no host, no hooks, no loaded code.

### Shortcodes

```
[wpcmb_field name="byline"]
[wpcmb_field name="byline" object="post_12" fallback="—"]
[wpcmb_field name="hero_link" key="url"]
[wpcmb_field name="tags" separator=" | "]
```

Output is escaped, and the visitor's permission to read the object is checked — a shortcode in a comment cannot leak a draft's values. Structured values (repeaters, groups) print the `fallback` rather than a serialised array, because there is no sensible one-line form and printing JSON into a page reads as a bug.

### WooCommerce

Products and coupons are post types, so they already work through ordinary post-type location rules. Two things needed code:

- The `wc_product_type` and `wc_order_status` location rules the builder already offered.
- **Orders under HPOS.** With High-Performance Order Storage an order's meta lives in WooCommerce's own tables, not `wp_postmeta`. Three new filters — `wpcmb/storage/read`, `/write`, `/delete` — let the integration claim those reads and writes and route them through the order CRUD. `wc_get_order()` returning false for a product is exactly the test that keeps products on post meta.

Field groups render in a **Custom Fields** product data tab and on the order screen.

### Elementor and Bricks

Both builders can already read these values, because they are stored in native meta — the Phase 3 decision paying off. What they cannot do is apply a field type's formatting, so an image field hands them an attachment id and a link field hands them a serialised array. Both integrations expose the **formatted** value instead.

- **Bricks** — `{wpcmb_fieldname}` dynamic data, registered through plain filters. No class of theirs is extended, so an internal signature change cannot break it.
- **Elementor** — a dynamic tag in a "Custom Meta Box" group, offered for text and URL bindings. The tag class must extend an Elementor base class, so it lives in `elementor-tag.php` — a filename that deliberately does not match its class name, excluded from Composer's classmap, and loaded by hand only once Elementor has registered. On a site without Elementor the autoloader cannot reach it, so mentioning the class name is a missing feature rather than a fatal error.

### WP-CLI

```bash
wp wpcmb groups [--inactive] [--format=json]
wp wpcmb get byline 12 [--raw]
wp wpcmb update byline 12 "Manpreet Singh"
wp wpcmb update rows 12 '[{"title":"One"},{"title":"Two"}]'
wp wpcmb delete byline 12
wp wpcmb export [<key>...] [--only=<sections>] [--file=site.json]
wp wpcmb import site.json [--activate]
```

Writes are validated and sanitized exactly as from an edit screen, so a script cannot store what the admin would refuse. An import creates things **inactive** unless `--activate` is passed.

`export` and `import` are the same code the Tools screen runs — one definition of what a file holds and one of what an import accepts, rather than a second implementation that drifts. `--only` takes any of `field_groups`, `post_types`, `taxonomies`, `blocks`, `forms`, `options_pages`, and an unknown name is an error rather than a silently empty file.

The command surface is a separate class from the module that registers it: WP-CLI turns every public method of a command object into a subcommand, so registering the module itself would publish `wp wpcmb boot` and `wp wpcmb is_enabled`.

## The interface

The plugin's own screens and the fields it renders on somebody else's screen are
two different problems, and they are solved separately.

**On the plugin's screens** — overview, field groups, the group editor, tools and
settings — the surface is ours end to end: cards on the admin canvas, one focus
ring, two shadow levels, and colour spent only on state that matters. Settings is
still the WordPress Settings API underneath, so it keeps the nonce, the capability
check, the option writes and the saved notice; only its appearance is ours.

**On a post, term, user or comment screen** the plugin owns its own meta box and
nothing else. `postbox_classes_*` adds `wpcmb-metabox` to the boxes this plugin
registers, and the stylesheet dresses that class alone — a rounded card, a tinted
header, an accent hairline on the box being typed in. A core or third-party meta
box sitting beside it is untouched. In the block editor, where Gutenberg
deliberately flattens the meta box area into one panel, its rules win and the
boxes stay flat; that is the host's call to make, not ours.

### Controls the plugin draws itself

| Control | Instead of |
|---|---|
| `.wpcmb-btn` — select media, add row, clear a signature | core's `.button`, which is a different height and shape from the inputs beside it |
| Row controls — drag, collapse, reorder, duplicate, remove | `☰ ▾ ↑ ↓ ⧉ ×` glyphs, each arriving at its own size and baseline |
| Multiple select — chips, search, checkboxes | a native listbox that needs a ctrl-click and hides what is chosen once it scrolls |

The row controls are inline SVG on a 16px grid at one stroke weight, inheriting
`currentColor`, so hover, danger and disabled are one rule each rather than one
per icon.

### The multiple select

A native `select[multiple]` gives up its selection only to a ctrl-click and shows
what is chosen only while the chosen rows happen to be in view. `enhanced.js`
replaces the look of it, not the thing itself:

- the `<select>` stays in the form, keeps its `name[]` and its options, and
  remains the only source of truth;
- every tick is written back to it and announced with `input` and `change`, so
  validation, conditional logic and the save path read the control they always
  read;
- if the script never runs — an error earlier on the page, JavaScript off — the
  native select is still there and still works.

Chips above the control show what is chosen and remove on click; the panel holds
a search box and a checkbox per option.

## Admin UI isolation

The plugin must never restyle WordPress or another plugin. That is a promise
no amount of care keeps on its own, so `tests/ui-check.php` enforces it and
runs with the rest of the suite:

| Rule | Enforced |
|---|---|
| Every CSS selector starts with a `wpcmb-` class or `#wpcmb-` id | ✅ |
| No core class restyled (`.button`, `.notice`, `.postbox`, `.wrap`, `.form-table`, `.widefat`, `.submit`, `.wp-core-ui`, `.dashicons`) | ✅ |
| No bare element selectors | ✅ |
| Every data attribute prefixed `data-wpcmb-` | ✅ |
| Scripts add nothing global but `window.wpcmb` | ✅ |
| Motion respects `prefers-reduced-motion` | ✅ |
| Logical properties only, so RTL needs no second stylesheet | ✅ |

**One deliberate deviation from the brief.** It asks that every selector begin
with `.wpcmb-admin` or `#wpcmb-app`. That is right for the plugin's own screens
and wrong for the field styles, which render inside the post, term, user and
comment editors where the body carries no class of ours — requiring it there
would mean fields render unstyled. Any `wpcmb-` class in the first compound is
equally airtight, since it cannot match markup the plugin did not write, so
that is what the checker enforces.

**RTL** needs no separate stylesheet: layout uses `margin-inline-*`,
`padding-inline-*`, `border-inline-*` and `inset-inline-*` throughout, which
mirror automatically under `dir="rtl"`.

**Colour** comes from `--wp-admin-theme-color`, so the plugin adopts whichever
admin colour scheme the user picked rather than imposing its own.
## Security

### What a field hands back depends on who is reading

A stored id outlives the status of the thing it points at. A post related while
it was published can be unpublished a month later, and the template printing
that relationship has no idea — so the check is made at read time, against the
current reader, not at write time against the person who chose it.

| Field | A reader who may not see it gets |
|---|---|
| post_object, relationship | nothing for that id |
| user | the user ID, never the `WP_User` object |
| file, image, gallery, video, audio | nothing for that attachment |

`read_post` is true for anyone on a published post, so an editor's screens are
unchanged and a visitor's page holds nothing they could not already load. An
attachment with no parent reads as published, which is every file uploaded
straight to the media library.

The same rule applies to the options a relationship field *offers*. Drafts and
private posts are offered on an edit screen, because the person choosing is
about to publish one. They are not offered on a public form, where every
option is a title handed to whoever asked for the page.

The user field is the sharpest of these: a `WP_User` carries the email address,
the login name and the registration date. A theme printing `->display_name` is
the common case and is harmless; it is the rest of the object that has no
business on a public page.

### Limits are checked where the value arrives, not where it was picked

The media picker is filtered by a field's allowed types as a convenience. The
same rules are checked again on save, because a value can also arrive from a
public form, from the REST API, or from `wpcmb_update_field()` — none of which
open a picker.

A field accepts its own kind of file whether or not anybody filled the setting
in: an image field takes images, a video field takes video. A `file` field has
no family and takes anything, which is what a file field is for.


Authorisation is the part of a plugin that holds until the day somebody adds
one more handler in a hurry, so `tests/security-check.php` enforces it with the
rest of the suite:

| Rule | Enforced |
|---|---|
| Every AJAX handler checks a nonce **and** a capability | ✅ |
| No endpoint treats "signed in" as permission | ✅ |
| Every REST route has a permission callback that is not `__return_true` | ✅ |
| Term values use the taxonomy-aware capability | ✅ |
| Every write path verifies a nonce | ✅ |
| Field names cannot claim protected meta keys | ✅ |
| Option-page values are written to prefixed option names | ✅ |

The rules are worth reading for *why* they exist, because each is the trace of
a way this could go wrong:

**Signed in is not permission.** `wp_ajax_` runs for every user on the site,
subscribers included. A handler that verifies only the nonce is open to the
whole user table — and the field endpoints render a field's own labels, choices
and default values. They require `edit_posts`, filterable through
`wpcmb/ajax/capability` for a site that deliberately puts a repeater on a user
profile group its subscribers fill in themselves.

**Values inherit the permissions of the object they belong to.** One place,
`Fields/Permissions.php`, answers that for the edit screens, the front-end
forms and the REST routes, because a capability check living in three places is
one that will eventually disagree with itself. Terms use `edit_term` rather
than `manage_categories`: it is a meta capability, so WordPress maps it to
whatever capabilities the taxonomy was actually registered with.

**A field name is a meta key.** Names go straight to `update_metadata()`, and a
leading underscore is how WordPress and every other plugin mark meta as
internal. A field named `_thumbnail_id` would hand everyone who can edit the
object a writable path to a key something else owns — creating the field needs
`manage_options`, but filling it in needs only `edit_post`, and that is the
boundary being kept. Leading underscores are stripped, in the PHP sanitizer and
in the builder's JavaScript, which `sanitize-check.php` checks agree.

> **Upgrading from 1.0:** a field whose name began with an underscore is now
> stored without it (`_price` becomes `price`). Rename such a field before
> upgrading, or migrate its meta key, or its existing values will read as
> empty.

**The front-end form trusts nothing it is handed.** The configuration is signed
with `wp_hash()` and compared with `hash_equals()`, then intersected with the
known keys, so a signature captured from one form cannot smuggle settings into
another. A public form never publishes: `draft` and `pending` are the only
statuses it will use unless the submitter holds `publish_posts`. Uploads are
off unless the submitter holds `upload_files`, because a public upload endpoint
is a different security problem from a public form.

**Values are re-checked, not trusted.** Choice and relationship fields
intersect the submission with the options actually rendered, so a hand-built
request cannot store a value the form never offered. Media fields verify each
id really is an attachment. Sanitizers are idempotent, so a value cleaned on
the edit screen and again in the value pipeline comes out the same.

What the checks cannot do is prove a capability is the *right* one — they prove
a check is present and that the ones already reasoned about have not quietly
reverted. Each rule was mutation-tested when it was written: break the thing it
guards and the suite fails.


## Conditional logic and validation

Both run on the server. The browser copy exists for fast feedback, not to decide what may be stored.

- **Fields hidden by conditional logic are not validated.** A required field the user was never shown must not block a save.
- **`0` is an answer, not emptiness.** `empty()` is the wrong test and is not used for values.
- **`>` and `<` are numeric.** PHP's string comparison would make "greater than 10" true for `"9"`.
- **A malformed validation pattern is reported, not silently passed.** Silently passing is the failure mode that makes a broken rule look like it works.

### Nothing publishes past a required field

A required field stops a post or page reaching a public status, whichever
button does it:

| Route | What happens |
|---|---|
| Publish / Update in either editor | The button is held while a field is invalid, and the server refuses the transition if the browser is bypassed |
| Quick Edit from the list | Refused; the row stays as it was |
| Bulk Edit from the list | Refused, per post |
| `wp_update_post()` in code | Goes through — code is assumed to mean it |

The two editor routes send the plugin's values with them, so the submission
itself is judged. **Quick Edit and Bulk Edit send none of them** — they publish
from the list without opening the post — so there what is already stored is
judged instead, which is both safe and sufficient: no values are arriving
later in those requests. That is exactly why the same reading would be wrong
for the block editor's own save, where the values are a moment behind in the
meta box request.

Neither list route has a screen of its own to explain a refusal, so a count is
left behind and reported on the list: *2 items were not published: open them
and fill in their required fields first.* Without it the row simply goes back
to being a draft and reads as a button that did nothing.

**An already-public post is still never unpublished.** An edit that leaves a
required field empty reports the problem and keeps the post live; taking a
page off the site over a validation error is worse than the error. The gate in
the editor is what stops the Update before it happens.

### Options page validation

Options pages have their own save path, so every rule is checked against it
separately — required, type, length, range, pattern, and required sub fields
inside a repeater, which report the row they are in.

Errors belong to the save that produced them and to the screen that save
redirects to. They used to be left in place for five minutes, so the next
person to open the page met a screenful of red errors about values they had
never touched, with no notice to explain them. They are now read once and
consumed, and which notice appears is decided by the flag the redirect set
rather than by whatever is left in the transient — otherwise refreshing a
failed save congratulated you on it.


### Required fields actually stop a save

A required field that does not stop a save is worse than no required flag at
all: the screen promises something it does not do. Both editors used to let a
post be published with every required field empty — the classic form posted
whatever it had, and the block editor saves the post through the REST API and
submits the meta boxes *afterwards*, so by the time the plugin saw the values
the post was already live.

Three things close it, at the three points where each is sound:

| Where | What happens |
|---|---|
| Block editor | The save is locked while the post is heading for a public status, so Publish and Update go quiet. A draft still saves. |
| Classic forms — post, term, user, comment | The submit is held, the server is asked, and it is replayed only if the answer is clean. |
| Server, on every one of our form submissions | A post may not *enter* `publish`, `future` or `private` while a required field is empty. It stays as it was and the reason is reported. |

**Nothing is validated twice.** The browser has no copy of the rules: it asks
`wp_ajax_wpcmb_validate_values`, which runs the real `Validator` over the real
resolved fields. Conditional logic, per-type formats, lengths, ranges,
patterns and the `wpcmb/validate` filters are all decided in one place, so a
rule added in PHP is enforced by the gate the moment it exists.

**Required works inside repeaters, groups and flexible layouts too.** Before
1.2.0 the sub fields of a composite field were sanitized but never validated,
so marking a repeater column required had no effect at all. Messages name the
row: *Row 2: Member is required.*

Deliberate limits, each for a reason:

- **A draft is never refused.** Saving work in progress must always be
  possible; refusing it would mean a half-written page could not be kept at
  all. `pending` is not blocked either, because submitting unfinished work for
  review is exactly when a required field is expected to be empty.
- **An already-published post is never unpublished.** An edit that happens to
  leave a required field empty reports the problem; it does not take a live
  page off the site. The gate stops the Update before it happens.
- **A save that is not ours is not judged.** The guard runs only on requests
  carrying our nonce, so programmatic inserts and other plugins' saves pass
  through untouched.
- **A direct REST publish is not covered.** `POST /wp/v2/pages/123` with
  `status: publish` from an API client carries no field values, and refusing it
  on the strength of what happens to be stored would block the block editor's
  own first publish — the values arrive in the meta box request a moment
  later. Publishing through the editor is guarded; publishing through the API
  is the API's business.
- **Failing open is deliberate.** If the validation request itself fails, the
  gate lets the save through and says so in the console. A network blip must
  not lock somebody out of their own work, and the server checks again on the
  way in regardless.


Custom rules hook `wpcmb/validate`, `wpcmb/validate/type={$type}` or `wpcmb/validate/name={$name}`; return a non-empty string to reject.

### Revisions and autosave

WordPress 6.4+ already stores, restores, diffs and autosaves any meta key named by `wp_post_revision_meta_keys`. Declaring the plugin's value keys there buys revision history, revision restore and autosave recovery in one filter — nothing is reimplemented.

## Hooks so far

| Hook | Type | Description |
|------|------|-------------|
| `wpcmb/modules` | filter | Module class names to boot. Receives `array $classes, Container $container`. |
| `wpcmb/booted` | action | All modules booted. Receives `Plugin $plugin`. |
| `wpcmb/activate` | action | Plugin activated, core options written. |
| `wpcmb/deactivate` | action | Plugin deactivated. |
| `wpcmb/capability` | filter | Capability required to manage field groups. |
| `wpcmb/location/params` | filter | Available location rule parameters. |
| `wpcmb/location/choices` | filter | Selectable values per location parameter. |
| `wpcmb/admin/field_types` | filter | Field types offered in the editor. |
| `wpcmb/admin/overview` | action | End of the Overview screen. |
| `wpcmb/field_group/pre_save` | filter | Sanitized config, immediately before storage. |
| `wpcmb/field_group/saved` | action | After a field group is stored. |
| `wpcmb/object_ref` | filter | Claim an object identifier the built-in forms reject. |
| `wpcmb/value/load` | filter | A value as it is read. Field types format here. |
| `wpcmb/value/save` | filter | A value before storage. Field types cast here. |
| `wpcmb/value/updated` | action | After a value is written. |
| `wpcmb/value/deleted` | action | After a value is deleted. |
| `wpcmb/register_field_groups` | action | Register groups from code. |
| `wpcmb/groups` | filter | Groups resolved for a context. |
| `wpcmb/location/value` | filter | Value of a custom rule parameter. |
| `wpcmb/location/match_rule` | filter | Outcome of a single rule. |
| `wpcmb/conditional/visible` | filter | Whether conditional logic shows a field. |
| `wpcmb/validate` | filter | Validation result. Return a message to reject. |
| `wpcmb/validate/type={$type}` | filter | Same, for one field type. |
| `wpcmb/validate/name={$name}` | filter | Same, for one field name. |
| `wpcmb/register_field_types` | action | Register a custom field type. |
| `wpcmb/field/choices` | filter | Options a choice field offers. |
| `wpcmb/field/relationship_query` | filter | Objects a relationship field offers. |
| `wpcmb/field/enhanced_config` | filter | Config for a script-enhanced field. |
| `wpcmb/field/sub_renderer` | filter | Renderer used for a composite type's sub fields. |
| `wpcmb/field/sub_sanitizer` | filter | Sanitizer used for a composite type's sub values. |
| `wpcmb/field/sub_formatter` | filter | Formatter used for a composite type's sub values. |
| `wpcmb/admin/sub_field_types` | filter | Which types get a nested field list in the editor. |
| `wpcmb/admin/layout_field_types` | filter | Which types are edited as a set of layouts. |
| `wpcmb/values/saved` | action | After an edit screen stores its values. |
| `wpcmb/form/template` | filter | Template used for a front-end form. |
| `wpcmb/form/allow_uploads` | filter | Whether a front-end form may accept uploads. |
| `wpcmb/form/notification` | filter | The notification email. |
| `wpcmb/form/submitted` | action | After a front-end submission is stored. |
| `wpcmb/permissions/read` | filter | Whether values may be read. |
| `wpcmb/permissions/edit` | filter | Whether values may be written. |
| `wpcmb/rest/field_schema` | filter | JSON Schema for a field's value. |
| `wpcmb/rest/meta_targets` | filter | Object types a group registers meta against. |
| `wpcmb/value/pre_get` | filter | Short-circuit a value read. |
| `wpcmb/block/template` | filter | Template used to render a block. |
| `wpcmb/storage/read` | filter | Claim a value read (HPOS orders). |
| `wpcmb/storage/write` | filter | Claim a value write. |
| `wpcmb/storage/delete` | filter | Claim a value delete. |
| `wpcmb/records/{$post_type}` | filter | Records of one type before they are used. Define them in code. |
| `wpcmb/export` | filter | An export payload before it is written. |
| `wpcmb/imported` | action | After an import. Receives `array $written, array $payload`. |

### Registering your own module

```php
add_filter( 'wpcmb/modules', function ( array $classes ): array {
	$classes[] = My\Plugin\Addon::class; // extends WPCMB\Abstracts\Module
	return $classes;
} );
```

## Moving a site between installs

Everything this plugin treats as configuration exports as one file: field
groups, and the post types, taxonomies, block types, forms and options pages
built from them. **Tools → Export** writes it; **Tools → Import** reads it. The
same code backs `wp wpcmb export` and `wp wpcmb import`, so there is one
definition of what a file holds rather than two that drift.

### What an import does with a file

**It matches on identity, so importing twice does not duplicate.** A field
group matches on its key, a post type and a taxonomy on theirs, an options page
on its slug. Whatever matches is updated in place; only what has no match is
created. Export, import, spot a typo, export, import again — the second run
changes the same records rather than building a second copy of the site.

A form has no natural key of its own, so it is identified by the field group it
renders plus its title. Two forms on the same group with the same title are the
same form to an import.

**Anything new arrives inactive.** A file somebody uploads must not start
putting fields on post screens before anyone has looked at it. `wp wpcmb import
--activate` publishes what it creates, which is for a deploy script that
already knows what it is importing.

**Every file this plugin has ever written still imports.** Three shapes are
accepted: the envelope current versions write, the bare list of field groups
earlier versions wrote, and a single field group on its own. A file exported a
year ago is the one thing an import has to be forgiving about. Anything else —
another plugin's JSON, a truncated file, an empty array — is refused before
anything is written, not part way through.

### The PHP export

The same configuration as code, for a theme or a small plugin. Field groups go
through `wpcmb_register_field_group()`; records go through the
`wpcmb/records/{$post_type}` filter each record type already applies to its own
list. Nothing in the plugin exists only to support this.

Once the configuration is in code, the records it replaces can be deleted: the
screens still show everything, and it deploys with the rest of your code
instead of being carried between installs by hand.

Leaving both in place is safe but does nothing useful. A stored field group
with the same key wins over a registered one, and a stored record registers
before a code one — `register_post_type` and `register_taxonomy` are skipped
for a name that already exists, so the record you can see in the admin is the
one in effect, and the code definition is the fallback for a site that has no
record. That is what makes "export to PHP, keep editing in the admin" work.

A key written by hand — `group_site_header` rather than a generated
`group_6a1f…` — survives a round trip. It has to: a key is an identity, values
are stored against it, and renaming it on import would orphan them and make the
next import create a second group.
## Data on uninstall

Deleting the plugin removes nothing unless `wpcmb_delete_data_on_uninstall` is set. Reinstalling should not cost a site its field groups.

## Checks

```bash
composer test        # all 20 PHP checks, one process each
npm test             # 158 browser tests against the real scripts
php tests/integration.php   # real WordPress, non-destructive
composer lint        # WordPress Coding Standards
```

### Three layers, each covering what the others cannot

**PHP checks** (`tests/*-check.php`) run without WordPress, against shims. Fast, and they run anywhere. Their weakness is the shims: twice a shim was *more lenient* than WordPress, so a check passed on behaviour production did not have. Both times the gap was found by a live run, and both times the shim was corrected rather than the assertion relaxed.

**Integration checks** (`tests/integration.php`) run against a real install with the real functions, covering exactly the places where being wrong about WordPress matters — slashing, tag stripping, URL sanitizing, location matching, and REST permission boundaries. Deliberately **non-destructive**: it creates its own fixtures, tracks them, and removes only those. It never empties a database, unlike the WordPress test suite, which wipes whatever it is pointed at.

**Browser tests** (`tests/js/*.test.js`) run the shipped scripts in jsdom against **real rendered markup**. The fixtures are generated by `php tests/js/build-fixtures.php`, which renders them through the actual PHP renderer — so a change to how input names are built fails a test rather than silently losing data. A test written against hand-written markup would pass while the real page was broken.

Individual PHP checks, if you want to run one:

```bash
php tests/bootstrap-check.php   # container + module boot pipeline
php tests/sanitize-check.php    # FieldGroup::sanitize(), the trust boundary
php tests/values-check.php      # object references + value storage
php tests/api-check.php         # location matching, conditional logic, validation
php tests/types-check.php       # field type sanitizing and formatting
php tests/repeater-check.php    # row order, limits, nesting
php tests/flexible-check.php    # layouts, orphan rows, per-layout limits
php tests/form-check.php        # form config signing, guests, targets
php tests/rest-check.php        # JSON Schema generation
php tests/blocks-check.php      # block settings and naming
php tests/integrations-check.php # integrations stay dormant without their hosts
php tests/security-check.php     # nonces, capabilities and storage boundaries
php tests/validation-check.php   # required fields, including inside repeaters
php tests/settings-check.php     # every group and field setting, where it takes effect
php tests/records-check.php      # post type, taxonomy, block, form and options page records
php tests/portability-check.php  # what an export holds, and what an import accepts
php tests/rows-check.php         # the row loop: positions, nesting, writers
```

None of these need WordPress.

## Packaging and release

```bash
php tests/release-check.php     # what the Plugin Directory asks of a submission
php bin/build.php               # the uploadable zip, in the plugin root
```

The build refuses to package anything the submission checks reject, because
the build is the last moment anybody looks.

| Rule | Enforced |
|---|---|
| Every header field the directory reads is filled in | ✅ |
| The slug, the text domain and every translated string agree | ✅ |
| The plugin name avoids the terms the directory restricts | ✅ |
| The translation headers and calls describe what ships | ✅ |
| `readme.txt` carries what the directory parses | ✅ |
| The version is the same in the header, the constant and the readme | ✅ |
| The licence is GPL, declared and shipped in full | ✅ |
| No shipped file uses what the directory refuses | ✅ |
| Every shipped PHP file parses and refuses direct access | ✅ |

The slug lives in three places that must agree: the folder and main file the
zip is built from, the text domain every translated string names, and the
package files. The checks hold them together — a text domain that drifts from
the slug means the directory's language packs silently never load, which is
the kind of thing that is noticed a year later by somebody else.

**What ships** is an allow list, not a deny list: the main file,
`uninstall.php`, `readme.txt`, `LICENSE`, and the `includes`, `templates`
and `assets` directories. A deny list ships whatever is added to
the checkout next, and the thing shipped by accident is always the thing
nobody meant to publish. The zip's one top-level folder is named after the
slug, whatever the checkout is called.

**Composer is a development dependency.** The plugin prefers Composer's
autoloader when it is there and falls back to its own PSR-4 loader when it is
not, so the package carries no `vendor/` and installs from the zip run on the
fallback. That path is worth testing before a release, because it is the one
every installed copy uses:

```bash
mv vendor vendor.off && php -r 'require "…/wp-load.php"; var_dump( function_exists( "wpcmb" ) );' ; mv vendor.off vendor
```

### The internal prefix is not the slug

The slug is `manpreetdev-custom-fields-meta-boxes`. The code prefix is
`WPCMB\`, `wpcmb_`, the `wpcmb_field_group` post type, the `_wpcmb_config`
meta key and every `wpcmb_*` option — and it stays that way. Renaming it would
orphan every field group, every stored value and every setting on every site
already running the plugin, which is a migration, not a rename.

The plugin has been renamed twice and the prefix survived both, which is the
point of keeping them separate. What a rename does cost:

- **The text domain**, which has to match the slug for the directory to serve
  language packs. Translations against an old domain need regenerating.
- **Theme template overrides**, which live in a folder named after the slug.
  A theme holding `metafields-custom-fields/form.php` has to rename that
  folder; the `wpcmb/form/template` filter is unaffected.
- **Nothing in the database.** An export written under a former slug still
  imports, because the marker inside an export file is checked against the
  slugs the plugin has had, not only the current one.


## Directory layout

Directories are created as their phase lands, not up front. Current tree:

```
manpreetdev-custom-fields-meta-boxes/
├── assets/
│   ├── css/admin.css             plugin screens
│   ├── css/fields.css            edit screens
│   ├── js/builder.js             field + location + logic builder
│   ├── js/fields.js              live logic, media picker, tabs
│   ├── js/repeater.js            rows, reorder, duplicate, CSV
│   ├── js/form.js                front-end AJAX submit
│   └── js/blocks.js              block editor, no build step
├── includes/
│   ├── Abstracts/
│   │   ├── FieldType.php         the field type contract
│   │   └── Module.php
│   ├── Admin/
│   │   ├── Ajax.php              repeater rows + CSV endpoints
│   │   ├── Assets.php            screen-gated asset loading
│   │   ├── DashboardWidget.php
│   │   ├── FieldGroupEditor.php  builder screen + the single save path
│   │   ├── FieldGroupList.php    columns, duplicate, cache invalidation
│   │   ├── Menu.php              menu + overview screen
│   │   ├── MetaBoxes.php         fields on post/term/user/comment/media
│   │   ├── OptionsPages.php      screens built from options_page rules
│   │   ├── Submissions.php       reading what a form stored
│   │   ├── SettingsPage.php
│   │   └── ToolsPage.php         the export/import screen
│   ├── FieldTypes/               11 classes, 52 types
│   ├── API/functions.php         the public template functions
│   ├── Database/
│   │   ├── Migrations.php        additive, one-way, keyed by an option
│   │   ├── Portability.php       what an export holds, what an import accepts
│   │   └── Revisions.php         revisioned meta keys (revisions + autosave)
│   ├── Fields/
│   │   ├── Conditional.php       server-side conditional logic
│   │   ├── Context.php           lazy description of the matched object
│   │   ├── FieldGroup.php        model + sanitizer
│   │   ├── Locations.php         rule catalogue, matching, summaries
│   │   ├── ObjectRef.php         loose identifier → (type, id)
│   │   ├── Permissions.php       one answer to who may read/write
│   │   ├── Registry.php          type registry + the value pipeline seam
│   │   ├── Renderer.php          wrappers, labels, naming, nesting
│   │   ├── Repository.php        reads, writes, duplication, caching, name index
│   │   ├── Resolver.php          which groups apply to a context
│   │   ├── Rows.php              the row loop's pointer stack
│   │   ├── Validator.php         server-side validation
│   │   └── Values.php            value get/update/delete/has
│   ├── Blocks/BlockRegistry.php  groups as blocks, PHP render
│   ├── CLI/                      wp wpcmb command
│   ├── Integrations/             shortcodes, Woo, Elementor, Bricks
│   ├── Frontend/
│   │   ├── Form.php              shortcode, signing, template location
│   │   └── Submission.php        one checked path for POST and AJAX
│   ├── Interfaces/Bootable.php
│   ├── REST/
│   │   ├── Controller.php        field-group + options-page routes
│   │   ├── MetaRegistrar.php     values on core's own endpoints
│   │   └── Schema.php            field definition → JSON Schema
│   ├── PostTypes/
│   │   ├── FieldGroupPostType.php
│   │   └── SubmissionPostType.php   one per group, named from its name
│   ├── Container.php
│   ├── Installer.php
│   └── Plugin.php
├── tests/
│   ├── *-check.php               16 standalone PHP checks
│   ├── integration.php          real WordPress, non-destructive
│   ├── run.php                  runs every PHP check
│   ├── shims.php
│   └── js/                      156 browser tests + generated fixtures
├── vendor/
├── bin/build.php                the uploadable zip
├── composer.json
├── phpcs.xml.dist
├── readme.txt                   the Plugin Directory listing
├── LICENSE                      GPLv2, in full
├── README.md
├── uninstall.php
└── manpreetdev-custom-fields-meta-boxes.php
```
