=== ManpreetDev Custom Fields and Meta Boxes ===
Contributors: manpreetdev21
Tags: custom fields, meta box, repeater, options page, custom post type
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.17.12
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Field groups, meta boxes and a developer-friendly field API: 51 field types, repeaters, options pages, blocks and public forms.

== Description ==

Add custom fields to posts, pages, custom post types, terms, users, comments, options pages and public forms. Build a field group in the admin, choose where it appears, and read the values back with one function.

**Fifty-one field types**, grouped by what they do rather than by name: text, number, email, URL, password, range, colour, date, time, phone, slug, UUID, currency, textarea, select, checkbox, radio, toggle, button group, rating, country, region, file, image, gallery, video, audio, post object, page link, relationship, taxonomy, user, link, rich text, code, JSON, HTML, repeater, flexible content, message, separator, tab, accordion, group, icon picker, map, signature, QR code, barcode, embed and address.

**Where a group appears is a rule, not code.** Twenty-four location parameters cover post type, template, status, format, category, taxonomy, a specific post or page, page type and parent, the current user, user role and profile form, terms, attachments, comments, menus, menu items, widgets, options pages, and WooCommerce product types and order statuses.

**Values live in native WordPress storage.** A field on a post is post meta; a field on a term is term meta; an options page writes prefixed options. Nothing is kept in a custom table, so every value stays readable with `get_post_meta()` and reaches the REST API, exports and migrations like any other metadata.

= For developers =

    wpcmb_the_field( 'subtitle' );                          // escaped output
    $value = wpcmb_get_field( 'subtitle', $post_id );       // the stored value
    $all   = wpcmb_get_fields( 'options_site-settings' );   // a whole options page

Field groups can be registered in PHP with `wpcmb_register_field_group()` and kept in version control, exported as JSON or PHP from the Tools screen, and extended with your own field types through `wpcmb/register_field_types`. Validation, conditional logic, sanitizing and the save path are shared by every type, including your own.

= Required fields are enforced =

A required field stops a post or page reaching a public status — from either editor, from Quick Edit and from Bulk Edit. The browser holds the Publish button while a field is invalid, and the server refuses the status change if the browser is bypassed. An already-published post is never unpublished over a validation error; the problem is reported instead.

= Options pages =

Set a group's location to an options page and the screen is created, rendered and saved for you. No `add_menu_page`, no render callback, nothing in the theme.

= Settings on every group and every field =

A field group sets where it renders, how its labels and instructions are placed, which core elements to hide on the edit screen, whether its fields reach the REST API and the block editor, and — for a group that creates an options page — that page's menu location, capability and icon.

Each field type adds its own: prepend and append text, minimum and maximum, a validation pattern and message, layout, an optional empty choice, free-typed values, return format, and for media fields the library to pick from, the allowed file types, minimum and maximum file size, and minimum and maximum image dimensions.

File and image limits are checked when the value is saved, not only when the picker is open, so they hold for a public form, the REST API and code as well.

= Public forms =

    [wpcmb_form group="group_abc123"]                 signed-in visitors
    [wpcmb_form group="group_abc123" guests="1"]      anyone

Submissions are stored in their own custom post type per group, with the values shown on the submission screen. The form's configuration travels signed with the site's own salts, so a visitor cannot edit the markup to change what a submission does.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it.
3. Open **Meta Boxes → Field Groups** and add a group.
4. Add fields, choose where the group appears, and publish it.

No configuration is required.

== Frequently Asked Questions ==

= Where are the values stored? =

In WordPress's own tables. A field on a post is post meta under the field's own name, so `get_post_meta( $id, 'subtitle', true )` works whether or not this plugin is active. Term, user and comment fields use their own meta tables; options pages use options named `wpcmb_{page}_{field}`.

= What happens to my data if I deactivate the plugin? =

Nothing. Values stay where they are, and the template functions are the only thing that stops working. Deleting the plugin leaves the data too, unless you opt in under **Meta Boxes → Settings**.

= Can I define field groups in code? =

Yes. Export a group as PHP from the Tools screen, drop it into your theme or a plugin, and it is registered without touching the database. A stored group with the same key takes precedence, so you can export to code and carry on editing in the admin.

= Does it work with the block editor? =

Yes, in both editors. A group can also be registered as a block, in which case its fields are edited in the block sidebar and the values live in the block's attributes.

= Can visitors submit a form without an account? =

Only if you ask for it. `guests="1"` on the shortcode accepts submissions from anyone; without it a visitor is asked to sign in. Uploads always need an account.

= Is it translation ready? =

Yes. Every string is translatable under the `manpreetdev-custom-fields-meta-boxes` text domain, and the layout uses logical CSS properties so right-to-left needs no second stylesheet.

== Screenshots ==

1. The field group editor: fields, their settings, and the rules that decide where the group appears.
2. Fields on a post, in the block editor.
3. An options page, created from a location rule.
4. A repeater, with rows that reorder, duplicate and import from CSV.
5. The field group list, with each group's key, field count and location.

== Changelog ==

= 1.17.12 =
* Fixed: duplicating a field group could switch off the original. Keys with an underscore after their prefix — most of them — were not renamed, so the inactive copy took over the original's key. Every key is now renamed, conditional rules follow, and an inactive group can never hide an active one.
* Fixed: the Modified column showed when a group was created, not when it last changed, and an inactive group showed 1970. Sorting by Key did nothing and is removed.
* Fixed: Private and Pending statuses switched a group off without saying so. A field group is now always Active or Inactive, and one restored from the trash keeps the state it had.
* Search also finds groups by key and field name. Quick Edit and the month filter are removed, the views read Active and Inactive, and notices say "field group" rather than "post".

= 1.17.11 =
* Local JSON writes only to this plugin's folder in uploads. The `wpcmb/json/save_path` filter is removed, and file names carry a random per-site token so they cannot be guessed.
* Submitted values are cleaned the moment they are received, and validation hooks are given only sanitized values.
* A logged-out visitor submitting a front-end form no longer writes to the database. The result comes back in a signed, short-lived link instead.
* The signed form state now passes WordPress's text sanitizer unchanged.

= 1.17.10 =
* Fixed: values were lost when a save changed what a location rule looks at, such as publishing a group meant for drafts.
* Fixed: opening and saving a field group could rewrite a location rule naming something the site no longer offers.
* Fixed: User Form rules now save on Edit User and work on Add User. The Register User and Block options, which never worked, are removed.
* Fixed: page, format and category rules no longer match content they do not apply to.

= 1.17.9 =
* "Hide on screen" now works in the block editor.
* Fixed: "Beside fields" labels left no room for the field in narrow positions.


= Earlier versions =
The full history of every release is in `changelog.txt`, included with the plugin.

== Upgrade Notice ==

= 1.5.0 =
A field name beginning with an underscore is now stored without it. If you have one, rename the field or migrate its meta key before upgrading.
