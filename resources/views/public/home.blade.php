<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Shop the HCI GroceryOne catalog and access store operations, HR, and employee services.">
    <meta name="theme-color" content="#f8fafc">
    <title>HCI GroceryOne | Shop and Store Operations</title>
    @include('legacy.theme-init')
    @vite(['resources/css/legacy/landing.css','resources/js/legacy/landing.js'])
</head>
<body>
<header class="landing-header">
    <div class="landing-utility">
        <span>Public product catalog</span>
        <span aria-hidden="true">·</span>
        <span>Point of sale, inventory, and HR &amp; payroll</span>
    </div>
    <div class="landing-nav-wrap">
        <div class="page-scroll-progress" aria-hidden="true">
            <span></span>
        </div>
        <nav class="landing-shell landing-nav" aria-label="Main navigation">
            <a class="landing-brand" href="#home" aria-label="HCI GroceryOne home">
                <span class="brand-mark"><x-legacy-brand-icon/></span>
                <span>Manage <strong>Grocery</strong></span>
            </a>
            <button class="nav-menu-toggle" type="button" id="nav-menu-toggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="landing-nav-links">
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </button>
            <div class="landing-nav-links" id="landing-nav-links">
                <a class="landing-link" href="#home">Home</a>
                <a class="landing-link" href="#how-it-works">How it works</a>
                <a class="landing-link" href="#featured">Catalog</a>
                <a class="landing-link" href="#for-staff">For staff</a>
                <a class="landing-link" href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/apply.php">Careers</a>
                <div class="nav-actions">
                    <button class="theme-toggle" type="button" id="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">Dark</button>
                    <a class="button nav-catalog-button" href="{{ route('catalog') }}">Browse products</a>
                    <a class="button button--quiet nav-staff-button" href="{{ route('login') }}">Staff login</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<main>
    <section class="landing-shell landing-hero" id="home" aria-labelledby="hero-title">
        <div class="hero-copy">
            <div class="hero-labels">
                <span>Your neighborhood grocery</span>
                <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/apply.php">Work with us <span aria-hidden="true">↗</span></a>
            </div>
            <h1 id="hero-title">One store.<br><span>Connected work.</span></h1>
            <p class="hero-description">Browse products as a customer. Give your team one place for checkout, stock, sales reporting, and people operations.</p>
            <div class="hero-actions">
                <a class="button" href="{{ route('catalog') }}">Browse the catalog <span aria-hidden="true">↗</span></a>
                <a class="button button--quiet" href="{{ route('login') }}">Staff sign in</a>
            </div>
            <div class="hero-workspaces" aria-label="Connected workspaces">
                <a href="{{ route('catalog') }}"><strong>Shop</strong><span>public catalog</span></a>
                <a href="{{ route('login') }}"><strong>Run</strong><span>store operations</span></a>
                <a href="{{ route('login') }}"><strong>Manage</strong><span>HR &amp; payroll</span></a>
            </div>
        </div>

        <aside class="catalog-window" aria-label="Live public catalog preview">
            <div class="catalog-window-top">
                <span class="mini-brand"><span class="brand-mark"><x-legacy-brand-icon/></span> HCI GroceryOne</span>
                <span class="window-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>
            <div class="catalog-window-content">
                <div class="window-availability"><span></span> Available to browse <small>Live catalog</small></div>
                <div class="window-title">
                    <div>
                        <p>Customer-facing catalog</p>
                        <h2>Products and prices,<br>before you visit.</h2>
                    </div>
                    <span class="window-basket"><x-legacy-brand-icon/></span>
                </div>
                <div class="window-product-list">
                    @foreach(array_slice($featuredProducts, 0, 3) as $product)

                        <a class="window-product" href="{{ route('catalog',['q'=>$product['product_name']]) }}">
                            @if(!empty($product['image']))
                                <img src="{{ route('product.image',$product['id']) }}" alt="" loading="lazy">
                            @else
                                <span class="window-product-fallback"><x-legacy-brand-icon/></span>
                            @endif
                            <span class="window-product-name">
                                <strong>{{ $product['product_name'] }}</strong>
                                <small>{{ $product['category'] }}</small>
                            </span>
                            <strong class="product-price">₱{{ number_format((float) $product['price'],2) }}</strong>
                        </a>
                    @endforeach
                    @if(!$featuredProducts)
                        <p class="window-empty">Browse the public catalog for the latest available products.</p>
                    @endif
                </div>
                <div class="window-footer">
                    <a href="{{ route('catalog') }}">See all available products <span aria-hidden="true">↗</span></a>
                    <span>Availability can change in store</span>
                </div>
            </div>
        </aside>
    </section>

    @if($featuredCategories)
    <nav class="category-strip landing-shell" aria-label="Browse available categories">
        <span class="category-strip-label">Browse available categories</span>
        <div class="category-chips">
            @foreach($featuredCategories as $category)
                <a href="{{ route('catalog',['category'=>$category]) }}">{{ $category }}</a>
            @endforeach
        </div>
        <a class="category-arrow" href="{{ route('catalog') }}" aria-label="Browse the full catalog">↓</a>
    </nav>
    @endif

    <section class="section workflow-section" id="how-it-works" aria-labelledby="experience-title">
        <div class="landing-shell">
            <div class="section-heading workflow-heading scroll-reveal">
                <p class="eyebrow">One connected system</p>
                <h2 id="experience-title">From the shelf<br>to the back office.</h2>
                <p>A quick tour of the real work this system supports. Scroll through each step to see where it fits.</p>
            </div>

            <div class="workflow-list">
                <div class="workflow-steps">
                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">01 / CUSTOMER CATALOG</span>
                        <h3>Let customers check before they come in.</h3>
                        <p>Browse live products, categories, prices, and availability in one clear public catalog.</p>
                        <a href="{{ route('catalog') }}">Browse the catalog <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual catalog-demo" aria-label="Illustration of the public product catalog">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>HCI GroceryOne</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Customer catalog</span>
                            <h4>Browse products</h4>
                            <p>Public product listing</p>
                            <div class="demo-chips"><i>Beverages</i><i>Condiments</i><i>Dairy &amp; Eggs</i></div>
                            @foreach(array_slice($featuredProducts, 0, 3) as $product)
                                <div class="demo-product-row"><span class="demo-product-icon">M</span><span><strong>{{ $product['product_name'] }}</strong><small>{{ $product['category'] }} · In stock</small></span><b>₱{{ number_format((float) $product['price'],2) }}</b></div>
                            @endforeach
                        </div>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">02 / POINT OF SALE</span>
                        <h3>Keep checkout connected to stock.</h3>
                        <p>Staff use the POS to record sales. Completed sales update inventory, so the store's product availability reflects checkout activity.</p>
                        <a href="{{ route('login') }}">Staff sign in <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual stock-demo" aria-label="Illustration of inventory updates">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>HCI GroceryOne</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Store operations</span>
                            <h4>Products &amp; stock</h4>
                            <p>Inventory and sales reporting</p>
                            <div class="demo-stock-row"><span><strong>Brown Egg (Tray)</strong><small>Dairy &amp; Eggs</small></span><b>18 <small>in stock</small></b></div>
                            <div class="demo-stock-row"><span><strong>Soft Drink (350mL)</strong><small>Beverages</small></span><b>35 <small>in stock</small></b></div>
                            <div class="demo-stock-row"><span><strong>White Rice (1kg)</strong><small>Pantry</small></span><b>12 <small>in stock</small></b></div>
                            <div class="demo-notice"><span aria-hidden="true">✓</span> Inventory updates after completed sales.</div>
                        </div>
                        <p class="demo-caption">Interface illustration. Sign in to use staff tools.</p>
                    </div>
                </article>

                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">03 / PEOPLE &amp; OPERATIONS</span>
                        <h3>Give the team one place to manage the work.</h3>
                        <p>Role-based tools bring together people services, schedules, payroll, reporting, and everyday store operations.</p>
                        <a href="{{ route('login') }}">Explore staff access <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual people-demo" aria-label="Illustration of staff operations dashboard">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>HCI GroceryOne</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Team workspace</span>
                            <h4>Good morning, team.</h4>
                            <p>Your workspaces, in one place.</p>
                            <div class="workspace-tiles">
                                <span><i>↗</i><strong>Point of sale</strong><small>Checkout &amp; returns</small></span>
                                <span><i>▦</i><strong>Inventory</strong><small>Stock &amp; suppliers</small></span>
                                <span><i>◎</i><strong>People</strong><small>HR &amp; payroll</small></span>
                            </div>
                        </div>
                        <p class="demo-caption">Your role determines which staff tools you can access.</p>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">04 / HUMAN RESOURCES</span>
                        <h3>Keep every employee record in one place.</h3>
                        <p>Review employee profiles, roles, and service details, with protected document records available through the employee workflow.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/employees.php">Open employee records <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of employee records">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Employee records</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Human resources</span>
                            <h4>Employee directory</h4>
                            <p>Profiles, roles, and records</p>
                            <div class="demo-stock-row"><span><strong>Employee profile</strong><small>Department · Position</small></span><b>Active</b></div>
                            <div class="demo-stock-row"><span><strong>Account role</strong><small>Role-based access</small></span><b>Scoped</b></div>
                            <div class="demo-stock-row"><span><strong>Employee documents</strong><small>Protected personnel files</small></span><b>On file</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · labels are examples.</p>
                    </div>
                </article>

                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">05 / ATTENDANCE &amp; LEAVE</span>
                        <h3>Track time without spreadsheets.</h3>
                        <p>Review daily attendance, late time, absences, and approved leave alongside employee schedules.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/attendance.php">Review attendance <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of attendance and leave records">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Attendance</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Attendance &amp; leave</span>
                            <h4>Daily status</h4>
                            <p>Attendance entries and leave status</p>
                            <div class="demo-chips"><i>Late</i><i>Absent</i><i>On leave</i></div>
                            <div class="demo-stock-row"><span><strong>Attendance entry</strong><small>Time in · time out</small></span><b>Recorded</b></div>
                            <div class="demo-stock-row"><span><strong>Late time</strong><small>Minutes recorded</small></span><b>Reviewed</b></div>
                            <div class="demo-stock-row"><span><strong>Leave request</strong><small>Approval status</small></span><b>Tracked</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · statuses reflect system options.</p>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">06 / PAYROLL</span>
                        <h3>Turn payroll runs into clear payslips.</h3>
                        <p>Payroll records bring pay, allowances, deductions, approvals, and net pay together for employee payslips.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/payslip_view.php">View payslip access <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of a payroll payslip">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Payroll · Payslip</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Payroll</span>
                            <h4>Pay period summary</h4>
                            <p>Illustrative values · not a payroll record</p>
                            <div class="demo-stock-row"><span><strong>Gross pay</strong><small>Pay and allowances</small></span><b>₱—.—</b></div>
                            <div class="demo-stock-row"><span><strong>Deductions</strong><small>Itemized on the payslip</small></span><b>₱—.—</b></div>
                            <div class="demo-stock-row"><span><strong>Net pay</strong><small>Released payslip total</small></span><b>₱—.—</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · sample amounts are intentionally blank.</p>
                    </div>
                </article>

                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">07 / RECRUITMENT</span>
                        <h3>Move applicants through hiring without losing the thread.</h3>
                        <p>Review applications by stage, coordinate interviews, and track requirements through the hiring workflow.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/applicant_review_queue.php">Review applicants <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of the applicant review queue">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Applicant review</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Recruitment</span>
                            <h4>Applicant pipeline</h4>
                            <p>Review stage and next action</p>
                            <div class="demo-chips"><i>Resume screening</i><i>Interview</i><i>Requirements</i></div>
                            <div class="demo-stock-row"><span><strong>Applicant record</strong><small>Resume screening</small></span><b>Review</b></div>
                            <div class="demo-stock-row"><span><strong>Applicant record</strong><small>Initial interview</small></span><b>Scheduled</b></div>
                            <div class="demo-stock-row"><span><strong>Applicant record</strong><small>For requirements</small></span><b>In progress</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · no applicant data shown.</p>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">08 / CONTRACTS &amp; LETTERS</span>
                        <h3>Know which letters are waiting, sent, or signed.</h3>
                        <p>Prepare HR letters and contracts, route them for approval, and track delivery and recipient responses.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/hr_letters.php">Open contracts &amp; letters <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of the contracts and letters list">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Contracts &amp; letters</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Human resources</span>
                            <h4>Letter activity</h4>
                            <p>Approval and recipient response</p>
                            <div class="demo-chips"><i>Sent</i><i>Accepted</i><i>Pending approval</i></div>
                            <div class="demo-stock-row"><span><strong>Employment contract</strong><small>Recipient response</small></span><b>Sent</b></div>
                            <div class="demo-stock-row"><span><strong>Official HR letter</strong><small>Approval workflow</small></span><b>Pending</b></div>
                            <div class="demo-stock-row"><span><strong>Signed document</strong><small>Stored with the record</small></span><b>Accepted</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · example statuses only.</p>
                    </div>
                </article>

                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">09 / FINANCIAL REPORTS</span>
                        <h3>See the figures behind each business decision.</h3>
                        <p>Review sales, expenses, margins, and payroll breakdowns over a selected reporting period.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/financial_analysis.php">Explore financial analysis <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of financial report totals">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Financial analysis</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Financial reports</span>
                            <h4>Period summary</h4>
                            <p>Illustrative values · select a reporting period</p>
                            <div class="demo-stock-row"><span><strong>Revenue</strong><small>Sales in selected period</small></span><b>₱—.—</b></div>
                            <div class="demo-stock-row"><span><strong>Expenses</strong><small>Recorded business costs</small></span><b>₱—.—</b></div>
                            <div class="demo-stock-row"><span><strong>Gross margin</strong><small>Calculated from report data</small></span><b>—%</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · sample totals are intentionally blank.</p>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">10 / PURCHASE &amp; SUPPLIERS</span>
                        <h3>Follow each order from supplier to delivery.</h3>
                        <p>Create purchase orders with supplier details, then track approvals, ordered items, and receiving progress.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/purchase_orders.php">Manage purchase orders <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of purchase orders and suppliers">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Purchase orders</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Procurement</span>
                            <h4>Order tracking</h4>
                            <p>Supplier and receiving status</p>
                            <div class="demo-chips"><i>Draft</i><i>Ordered</i><i>Partially received</i></div>
                            <div class="demo-stock-row"><span><strong>Purchase order</strong><small>Supplier name · items</small></span><b>Ordered</b></div>
                            <div class="demo-stock-row"><span><strong>Delivery record</strong><small>Received quantity</small></span><b>In progress</b></div>
                            <div class="demo-stock-row"><span><strong>Order total</strong><small>Calculated from line items</small></span><b>₱—.—</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · no live order data shown.</p>
                    </div>
                </article>

                <article class="workflow-step scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">11 / REPORTS &amp; EXPORTS</span>
                        <h3>Export reports with a record of the run.</h3>
                        <p>Build sales reports for a selected period, export supported formats, and record who exported how many rows.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/reports.php">Browse sales reports <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of report exports and export auditing">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Reports &amp; exports</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Sales reports</span>
                            <h4>Export a report</h4>
                            <p>Choose report and file format</p>
                            <div class="demo-chips"><i>Export Excel</i><i>Export PDF</i></div>
                            <div class="demo-stock-row"><span><strong>Report export</strong><small>Report · format</small></span><b>Ready</b></div>
                            <div class="demo-stock-row"><span><strong>Export audit entry</strong><small>User · time · row count</small></span><b>Recorded</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · audit fields reflect the export log.</p>
                    </div>
                </article>

                <article class="workflow-step workflow-step--reverse scroll-reveal">
                    <div class="workflow-copy">
                        <span class="workflow-index">12 / PRIVACY &amp; COMPLIANCE</span>
                        <h3>Give privacy requests a secure path to review.</h3>
                        <p>Capture applicant consent and manage data subject requests with identity checks, status tracking, and expiring response links.</p>
                        <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/privacy_requests.php">Review privacy requests <span aria-hidden="true">↗</span></a>
                    </div>
                    <div class="workflow-visual" aria-label="Illustration of the privacy request review queue">
                        <div class="demo-window-bar"><span class="brand-mark"><x-legacy-brand-icon/></span><strong>Privacy requests</strong><span class="window-dots"><i></i><i></i><i></i></span></div>
                        <div class="demo-window-content">
                            <span class="demo-kicker">Privacy &amp; compliance</span>
                            <h4>Request review</h4>
                            <p>Verify identity before disclosure</p>
                            <div class="demo-chips"><i>Pending</i><i>Identity check</i><i>In review</i></div>
                            <div class="demo-stock-row"><span><strong>Access request</strong><small>Identity verification required</small></span><b>Pending</b></div>
                            <div class="demo-stock-row"><span><strong>Response file</strong><small>Signed, expiring download</small></span><b>Protected</b></div>
                        </div>
                        <p class="demo-caption">Illustrative interface preview · no requester data shown.</p>
                    </div>
                </article>
                </div>
                <div class="workflow-preview-rail" aria-label="Live feature preview">
                    <div class="workflow-preview-sticky">
                        <div class="workflow-preview-glow" aria-hidden="true"></div>
                        <div class="workflow-preview-stage" aria-live="polite"></div>
                        <div class="workflow-preview-progress" aria-hidden="true">
                            <span></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section" id="featured" aria-labelledby="featured-title">
        <div class="landing-shell">
            <div class="section-heading">
                <p class="eyebrow">Featured picks</p>
                <h2 id="featured-title">What's in stock right now.</h2>
                <p>A sample from the active, in-stock catalog. Prices and availability may change as sales are recorded.</p>
            </div>
            @if($featuredProducts)
                <div class="product-grid">
                    @foreach($featuredProducts as $product)

                        <a class="product-card" data-product-id="{{ $product['id'] }}" href="{{ route('catalog',['q'=>$product['product_name']]) }}">
                            <span class="product-image">
                                @if(!empty($product['image']))
                                    <img src="{{ route('product.image',$product['id']) }}" alt="" loading="lazy">
                                @else
                                    <span class="feature-icon" aria-hidden="true">MG</span>
                                @endif
                                <span class="product-category">{{ $product['category'] }}</span>
                            </span>
                            <span>
                                <strong>{{ $product['product_name'] }}</strong>
                                <span class="product-price">₱{{ number_format((float) $product['price'],2) }}</span>
                                <span class="product-stock">{{ number_format((int) $product['stock']) }} in stock</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="hero-description">No in-stock products are available to feature right now.</p>
            @endif
            <div class="hero-actions featured-actions">
                <a class="button button--quiet" href="{{ route('catalog') }}">Browse all products <span aria-hidden="true">↗</span></a>
            </div>
        </div>
    </section>

    <section class="section staff-section" id="for-staff" aria-labelledby="cta-title">
        <div class="landing-shell">
            <div class="landing-cta">
                <div class="staff-copy">
                    <p class="eyebrow">For the store team</p>
                    <h2 id="cta-title">Different roles.<br>One staff sign-in.</h2>
                    <p>Staff access is for authorized team members. Available tools depend on the account’s role and permissions.</p>
                    <a class="button staff-login-button" href="{{ route('login') }}">Go to staff sign-in <span aria-hidden="true">↗</span></a>
                </div>
                <nav class="staff-role-list" aria-label="Staff workspace areas">
                    <a class="staff-role-card" href="{{ route('login') }}">
                        <span class="staff-role-number" aria-hidden="true">01</span>
                        <span class="staff-role-copy"><strong>Sales floor</strong><span>POS checkout and daily store workflows</span></span>
                        <span class="staff-role-arrow" aria-hidden="true">→</span>
                    </a>
                    <a class="staff-role-card" href="{{ route('login') }}">
                        <span class="staff-role-number" aria-hidden="true">02</span>
                        <span class="staff-role-copy"><strong>Inventory &amp; management</strong><span>Products, stock oversight, and sales reporting</span></span>
                        <span class="staff-role-arrow" aria-hidden="true">→</span>
                    </a>
                    <a class="staff-role-card" href="{{ route('login') }}">
                        <span class="staff-role-number" aria-hidden="true">03</span>
                        <span class="staff-role-copy"><strong>HR &amp; payroll</strong><span>Attendance, leave, and payroll workflows</span></span>
                        <span class="staff-role-arrow" aria-hidden="true">→</span>
                    </a>
                </nav>
            </div>
        </div>
    </section>
</main>

<footer class="landing-footer">
    <div class="landing-shell footer-card">
        <div class="footer-brand">
            <a class="landing-brand" href="{{ route('home') }}" aria-label="HCI GroceryOne home">
                <span class="brand-mark" aria-hidden="true"><x-legacy-brand-icon/></span>
                <span>Manage <strong>Grocery</strong></span>
            </a>
            <p>A grocery catalog and store operations system for customers and staff.</p>
        </div>
        <nav class="footer-group" aria-label="Shop links">
            <strong>Shop</strong>
            <a href="{{ route('catalog') }}">Browse products</a>
            <a href="{{ route('home') }}#featured">Featured picks</a>
            <a href="{{ route('home') }}#how-it-works">How it works</a>
        </nav>
        <nav class="footer-group" aria-label="Careers links">
            <strong>Careers</strong>
            <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/apply.php">Apply for a job</a>
            <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/apply.php">Application information</a>
            <a href="{{ rtrim(config('app.legacy_url'),'/') }}/HRMS/privacy_request.php">Privacy request</a>
        </nav>
        <nav class="footer-group" aria-label="Staff access links">
            <strong>Staff access</strong>
            <a href="{{ route('login') }}">Staff login</a>
            <a href="{{ route('home') }}#for-staff">Available modules</a>
        </nav>
        <div class="footer-copyright">© {{ date('Y') }} HCI GroceryOne</div>
    </div>
</footer>


</body>
</html>
