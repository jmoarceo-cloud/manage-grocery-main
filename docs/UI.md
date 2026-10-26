# Native interface and interaction evidence

Updated 2026-10-26. The owner's latest instruction requires the original UI and
features, with Laravel as the stack. The navy/teal public storefront and new
staff workspace described by earlier screenshots are superseded designs.
Home, initial login, public catalog, product details and comparison now use original HTML converted to Blade
with the original blue palette, layout, typography, wording, SVGs and scripts.
Their CSS/theme assets are copied from `project/` with source hashes recorded
in [ORIGINAL_UI_SOURCE](audit/ORIGINAL_UI_SOURCE.json). Locally served Plus Jakarta
Sans and Fraunces retain their OFL notices in `resources/fonts` and `public/fonts`.

Home and initial login each have four source-presentation comparisons: desktop
1440x1000/mobile390x844, light/dark, reduced motion and the same local font files.
All had zero pixels differing by more than eight channel values. This is a
viewport comparison against independently rendered original presentation text,
not a live legacy request, full-page proof or whole-application acceptance.
See [home results](audit/ORIGINAL_UI_BROWSER.json), [login results](audit/ORIGINAL_LOGIN_BROWSER.json)
and `docs/ui/original-style-*` / `source-*-reference-*` screenshots.
Catalog screenshots and responsive search/allergen controls are browser checked;
catalog pixel equivalence has not been measured. Original light mode removes
`data-theme` in shared theme-sync JS. Its behavior is preserved.

Remaining native staff/MFA/code-lookup views still need their original UI
restored. Functional behavior descriptions below are partial historical native
coverage, not acceptance of those redesigned screens. Private/kiosk access and
password reset continue through their original PHP pages and separate sessions.
No new feature work is authorized by this UI correction.

The register supports barcode/manual lookup, quantity editing/removal,
discount and approval inputs, amount received, server receipt confirmation,
printing and refund/void actions. F2 focuses lookup and F9 submits payment.
Cart text uses DOM textContent. A retained UUID and server resolution endpoint
recover completed checkout after a network retry. Only public cart fields
and that key are stored in sessionStorage; credentials and customer discount
identity fields are not persisted there.

Inventory offers name/barcode search, low/out-of-stock filters, retained
pagination queries and expiry warnings. Admin restock selects a named active
product and asks for confirmation. Inventory Staff cannot POST direct restock.
Product/category/supplier tables have validated search/sort and pagination;
forms show validation errors and preserve ordinary input. Sensitive credentials
are not flashed. Product-image errors reveal an SVG fallback rather than an
empty image slot. Keyboard focus and input labels are explicit.

## Evidence and limits

Playwright checks desktop 1440px and mobile 390px views, JavaScript errors,
horizontal overflow, image/fallback visibility, authentication, role denial,
checkout/retry/receipt and inventory controls. See [TESTING](TESTING.md) for
the latest results and the shared-database outage limitation.

Screenshots in `docs/ui/laravel-*` and `legacy-home-baseline.png` capture the
earlier real public pages (public product information only). Files prefixed
`synthetic-*` show only synthetic data on the verified test pair. Screenshots
are visual evidence, not proof of complete accessibility or business parity.
No real staff/customer screen or personnel document was captured.

Scanner/camera compatibility, nutrition comparison, advanced labels, all
legacy navigation, full assistive-technology and cross-browser verification
remain open. Remaining modules link explicitly to the preserved application;
those links require its separate login.

The verification screen now supports resend to an already enrolled address and
shows a recovery-code path when mail delivery is throttled/unavailable. Password
alone still cannot open the workspace. Image delivery rejects inactive products,
path traversal and files with a false image extension; direct mixed-storage
URLs are denied on Apache and the synthetic development router.
Manual basket discounts now have a native request form, history/status filter,
Finance/Admin approve/reject controls, owner cancellation and a register selector
for owned unused approvals. Refresh updates the approval notice and preview;
the final server checkout still validates quote and stock. A synthetic browser
flow signs into separate Cashier and Finance contexts, approves 10% on a PHP42.50
basket, checks out at PHP38.25, retries without a second sale and inspects the
receipt. The Finance queue screenshot uses synthetic IDs/reasons only.
Native Product labels supports active-product search, a page of up to 96 items,
bulk selection and a choice of shelf QR/EAN-13 output. Admin/Inventory Staff can
print; Cashier is denied. Product names are escaped. Invalid/missing EAN-13 or
checksum is shown as readable text with an explicit correction state, without
rewriting the stored barcode. Print CSS uses A4 and hides navigation/toolbars.
Catalog cards, public details, label prices and checkout share the current
product-discount calculation; discounted cards also show the regular price.

The synthetic label browser check decodes the rendered EAN-13 bars and QR from
canvas pixels using the existing local ZXing-WASM reader, generates a local A4
PDF, then opens the decoded native product URL. No new decoder app endpoint or
external image upload is introduced. Screenshots are synthetic only. Physical
printer calibration, actual phone cameras and other symbologies remain untested.
Verified native product pages now show description/brand/unit/weight, nutrition,
ingredients, allergens, storage and related active in-stock products. All other
review states show a generic unavailable state. The comparison picker accepts up
to three verified products, preserves selection and shows unknown values as
Not listed, retaining actual zero. Different/missing categories/bases/servings
block the comparison table; no unit conversion or universal best-food ranking
is inferred. This preserves legacy category/basis checks and adds serving-size
consistency. See the FDA reference below for the serving-size interpretation,
not a claim of regulatory approval or product accuracy.

Mobile comparison keeps row labels visible while scrolling, provides a scroll
hint and a keyboard-focusable table region. Product placeholders remain visible
until an actual image loads; the known default.png sentinel uses the native SVG
without an unnecessary image request. Browser checks cover desktop/mobile facts,
zero/unknown data and horizontal access to the second product column.

Reference: [FDA label-reading guidance](https://www.fda.gov/food/nutrition-facts-label/how-understand-and-use-nutrition-facts-label)
explains matching serving sizes when comparing recorded nutrient information.

Account security is available to verified staff through the sidebar. It shows
own account details, a masked verification email and recovery count, followed by
current-password/recovery-code proof and confirmed new password fields. Changing
proof method disables the unused input; no credential is prefilled or flashed
back. Mobile uses one column. Success signs out with a login notice; fresh MFA
is required. The remaining HR profile/payroll/avatar features are separate.
The synthetic screenshot is `docs/ui/synthetic-account-security-mobile.png`.

Catalog now links to Find by barcode. The native page offers a labelled manual
code input, leading-zero help, package-code illustration, Enter submission and a
generic unknown/invalid state with a catalog alternative. Its two desktop columns
stack on mobile. A connected keyboard-style scanner may enter the same field;
only keyboard Enter was simulated, not physical hardware. Browser evidence opens
the correct out-of-stock active product using GTIN fallback. Reviewed screenshots:
docs/ui/synthetic-product-code-desktop.png and synthetic-product-code-mobile.png.

Product discounts appears for Inventory Staff/Admin/Finance in native navigation.
The request form supports active product search, percentage/fixed/removal and a
business reason; the queue shows price-at-request, previous/proposed discounts,
proposed effective price and state. Finance sees decision controls; owning
Inventory Staff/Admin sees cancellation. Stale/expired decisions show validation
messages and preserve the proposal for rejection/cancellation. Mobile stacks the
form and provides a focusable horizontally scrolling table with a scroll hint;
the browser reaches its rightmost cancellation action. Separate Finance approval
changes the synthetic public detail from PHP 12.50 to PHP 11.25. Reviewed images:
synthetic-product-discount-request-mobile.png and synthetic-product-discount-finance.png
under docs/ui.

The product edit page links to Edit and review product information. Description,
brand, unit and storage fields sit alongside saved ingredient/nutrition/source
values; mobile stacks the cards and headings. Draft/submit/resubmit controls
follow the current state. Admin sees separate saved-information verification and
reason-required rejection forms. Unsaved edit changes disable those decisions
with a save/reload notice. Web-sourced data shows its label-review requirement.
Source references are safe plain text, never a private-photo link. Review history
shows recent actions without raw JSON. Browser screenshots under docs/ui are
synthetic-product-information-mobile.png and synthetic-product-information-admin.png;
both were inspected and spacing refined. The public page hides modified verified
facts until Admin's saved-information decision succeeds.

The existing Uncategorized product option now saves and reopens correctly instead of producing a database error. A browser creates a synthetic product without category/supplier/barcode, confirms success and the retained empty category, then continues inventory checks. No visual layout or asset change was needed.

Personal information is now in the account navigation for native staff. A
read-only employment card accompanies the bounded contact/personal form; mobile
stacks the cards and fields. The repeat-email and password inputs start empty;
errors reload saved values. Country hints update without truncating the number,
and unsupported saved formats have a keep-current option. An unlinked account
sees the HR contact empty state. Reviewed synthetic captures:
[desktop](ui/synthetic-personal-information-desktop.png) and
[mobile](ui/synthetic-personal-information-mobile.png). Chromium verifies390px
layout, saved contact/address, absent bank/ID markers and unchanged sign-in email.
Attendance, leave, overtime, payslips and avatar controls are not part of this form.

My schedule appears in native account navigation. Today is highlighted; recurring
days display Monday-first, and a separate chronological preview shows approved
day-off covers. Overnight markers, inactive warnings, unconfigured states and
missing-time confirmation remain explicit. Cards stack responsively without a
wide roster table. The same synthetic linked staff Chromium flow captures
[desktop](ui/synthetic-own-schedule-desktop.png) and
[mobile](ui/synthetic-own-schedule-mobile.png), verifies seven entries in each
list, cover/overnight labels and no horizontal overflow at390px. The page
explains that leave/holidays and clock-in eligibility use separate workflows.


The first browser attempt timed out because decorative navigation symbols were
included in accessible link names. Sidebar icons now use aria-hidden, leaving
the visible link text as its accessible name. Screenshot review also caught the
mobile drawer during its closing transition; the final capture waits for the
drawer to leave the viewport and disables animations. These findings required
a fresh guarded browser rerun; the final complete report passes all ten.

My leave is in the native account navigation. Three balance cards distinguish
recorded values from default allocation estimates; pending nonreservation and
calendar-day vacation rules are explicit. The request form uses labeled dates,
type and a brief private reason; submissions appear as Pending in paginated,
start-year/status-filtered own history. Errors reload without private old input.
The existing linked staff Chromium flow checks submit/retry exactly once and
captures [desktop](ui/synthetic-own-leave-desktop.png) and
[mobile](ui/synthetic-own-leave-mobile.png) without390px overflow. Approval,
cancellation, credit editing and medical attachments remain separate workflows.


Browser verification initially timed out on the nested leave-type label lookup;
the select now has an explicit accessible label. Visual review then caught a
Windows text-edit encoding artifact in the default-balance caption, corrected to
plain text. The final captures were regenerated after these fixes.

Leave approvals now appears only for eligible native HR-domain reviewers. The
page states its current scope, filters pending/approved/rejected history and
offers named, confirmed Approve/Reject controls. Request cards retain readable
reasons/dates on mobile; stale conflicts require a fresh review. Reviewed
[desktop](ui/synthetic-leave-approvals-desktop.png) and
[mobile](ui/synthetic-leave-approvals-mobile.png) captures use synthetic data.
The eleven-scenario Chromium run confirms separate HR approval, disappearance
from Pending, Approved history and an employee's updated own balance. General
HR dashboard/roster monitoring, cancellation and attachments remain separate.

My overtime now appears in native account navigation. A bounded retrospective
request form is followed by paginated year/status history. Each request clearly
separates requested hours from recorded punch/work/expected/excess evidence;
missing/invalid punches and absent supervisor remain explicit. The page explains
current-schedule limits and separate approval/payroll checks. Reviewed synthetic
[desktop](ui/synthetic-own-overtime-desktop.png) and
[mobile](ui/synthetic-own-overtime-mobile.png) captures show3.50 requested versus
2.00 recorded excess, no private GPS/bank/ID markers and no390px overflow. The
dedicated browser also checks a normalized retry leaves one pending request.

Overtime approvals now appears for eligible HR Admin/supervisors. Request cards
state current assigned-team scope and keep requested hours separate from recorded
work/expected/excess, with missing/invalid punch states. Named confirmation
controls and stale-work conflicts require a current review. Reviewed synthetic
[desktop](ui/synthetic-overtime-approvals-desktop.png) and
[mobile](ui/synthetic-overtime-approvals-mobile.png) show3.50 requested and2.00
excess. Separate supervisor/employee Chromium sessions confirm approval/history
and unchanged evidence,403 owner queue denial, visible390px controls and no
private markers/script errors. On-behalf/team preview remains separate.

## Original product detail, 2026-10-26

The detail screen now preserves the original photograph/placeholder, effective
price and original-price strikeout, stock color, verification notice, nutrition
and information cards, source link and related products. Unknown nutrition rows
are omitted and zero is shown as 0, matching the source; no invented zero is
introduced. Original dashboard return contexts map to allowlisted native routes.
The sole dynamic stock-color PHP expression maps to an equivalent CSS variable;
the remaining original CSS is identical. Shared theme/font/SVG assets stay local.

Four full-page source-presentation comparisons (desktop1440/mobile390, light/dark)
had zero pixels exceeding the eight-value threshold. See [product results](audit/ORIGINAL_PRODUCT_BROWSER.json)
and `original-style-product-*` / `source-product-reference-*` screenshots.
The reference uses fingerprint-pinned original presentation and the same native
synthetic public data/fonts. It never executes original config, queries or scan
logging. This proves the compared presentation only; native availability and
business safety remain separately tested. Full comparison/staff UI, private
session and analytics parity are still open.

## Original nutrition comparison, 2026-10-26

Comparison now uses its original navigation/picker/table, exact CSS, theme/SVG
and actual Arial/Georgia fallbacks; the source never loads the named web fonts.
Single-product tables and source per-row numeric-minimum green cells are restored.
Unknown values remain Not listed and zero remains a numeric value. Native
verification/category/basis/serving guards, current selections, numeric ids[] and
keyboard scrolling remain. Minimum colors are source display formatting, not
evidence of dietary value or a universal best-food result.

Eight source-presentation checks passed the unchanged0.2% differing-pixel limit
(channel differences above eight): initial viewport and selected table at desktop1440/
mobile390, light/dark. See [comparison results](audit/ORIGINAL_COMPARISON_BROWSER.json)
and `original-style-comparison-*` / `source-comparison-*-reference-*` screenshots.
The selected picker keeps native current values, so selected full-page equality
is not claimed. The reference excludes original config, queries and client
redirects. Staff/MFA/scanner/private and other screens remain to be restored.
