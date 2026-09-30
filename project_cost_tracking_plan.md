# Project Cost and Progress Tracking: Build Plan

Source brief: `project_cost_tracking_brief.md`. This file is the working plan and is updated as work is done.
Legend: [x] done · [ ] to do · (§n) = brief section.

## How it fits the existing system

| Brief item | Existing piece reused |
|---|---|
| Contract value | `projects.fee` (locked to the accepted quotation) |
| Payments received | `payments` → `Project::amountPaid()` |
| Labour cost | Approved `muster_rolls` → posted to the new cost ledger automatically |
| Cash paid out (labour) | `wage_payments` |
| Approval pattern / permissions | Spatie permissions; super admin always passes; roles granted per entry type |
| Site entry screens | Team panel "Site" group, scoped by `SiteAccess` (own projects; approvers see all) |
| Estimate module → master BOQ library | New `boq_master_items` (there was no reusable BOQ data; quotation items stay as the client price list) |

## Phase 1 (done, 2026-10-01)

### 1.1 Company tax settings (§2, §13.2)
- [x] `company_settings` singleton: `vat_registered`, `vat_registration_date`, `vat_rate` (13), `vat_registration_limit`, `vat_warning_percent` (80), `fiscal_year_start_month` (Shrawan = 4)
- [x] Admin screen "Company & Tax Settings"
- [x] `CompanySetting::vatClaimable(billType, billedToCompany, billDate)` helper for Phase 2 (rule 1)
- [x] Rule 6: while PAN-only, quotations have no VAT line (VAT field hidden, forced to 0 on save)

### 1.2 Configurable approvers (§13.3)
- [x] Permissions: `approve_boq_measurements`, `approve_purchase_bills`, `approve_petty_cash`, `approve_work_orders`, `approve_variations`, `view_project_costs`
- [x] "Approval Settings" screen: pick roles per entry type (syncs role permissions; no code change needed later)
- [x] Approval checks use these permissions (super admin always allowed)
- [x] Nobody approves their own entry; approver and time stored

### 1.3 BOQ master library (§13.1)
- [x] `boq_master_items`: code, description, unit, default_rate, category, rate_includes_vat, norms (json, for Phase 5), is_active, rate_updated_at, created_by
- [x] Library screen: search by code/description/category, most-used first, deactivate (items used in projects cannot be deleted)
- [x] Duplicate warning on similar code/description; admin "merge into" another item
- [x] Default rate change only affects future projects (`rate_updated_at` kept)

### 1.4 Project BOQ and progress (§6)
- [x] `boq_items`: project_id, master_item_id, code, description, unit, quantity, rate, planned_value, planned_start, planned_end, is_variation, sort
- [x] Project page "BOQ" tab: add from library (search), create new item (saved to project + library in one action), copy from a previous project
- [x] `boq_measurements`: cumulative executed quantity, date, photos, remarks, status pending/approved/rejected, entered_by, approved_by/at
- [x] Engineers enter measurements (team panel); approvers approve; history kept
- [x] Item progress % = latest approved cumulative qty ÷ BOQ qty (not capped; "excess, variation needed" shown)
- [x] Project progress % = Σ(executed × rate) ÷ Σ planned value (value-weighted)
- [x] Item status (Not started / In progress / Completed) and schedule status (On track / Delayed) vs planned % today
- [x] Measurement history with photos per item; delayed items listed

### 1.5 Budget, ledger and report (§3, §4, §5)
- [x] `project_budgets`: one amount per category per project (Materials, Labour, Subcontract, Equipment, Transport, Site expenses, Contingency)
- [x] `project_costs` ledger: project_id, category, source_type/source_id, amount, entry_date, description, status, approved_by/at, boq_item_id
- [x] Approved muster rolls post labour cost automatically; returning/deleting a roll removes it; existing approved rolls back-filled
- [x] Project fields: client_type, price_basis (VAT-inclusive / plus VAT), manual % complete, cost-to-finish override
- [x] Project cost report: contract value, cost so far (% of budget), committed, est. cost to finish, projected final cost (no double counting), projected profit + margin, cash (received − paid out), VAT not claimable line
- [x] Budget vs actual per category: warning ≥ 80 %, alert > 100 %
- [x] Health status green / amber / red from % budget spent vs % work complete (BOQ, else manual %)
- [x] Printable statement; "Costs" button on projects

### 1.6 Verification
- [x] Tests for acceptance checks 5, 6, 7, 8 (checks 1–4 are VAT bill rules → Phase 2)
- [x] Full test suite green

## Phase 2 (done, 2026-10-01)

### 2.1 Purchase bills (§2, §4)
- [x] Vendors get `pan_vat_no`; picked or created on the fly from the bill form
- [x] `purchase_bills`: project, vendor, bill_type (VAT/PAN), bill_no, vendor_pan_vat, bill_date, base/VAT/total, billed_to_company, vat_claimable (computed on save, never typed), category (default Materials), item lines (item, qty, unit, rate) , photos, status draft→pending→approved/rejected, entered_by, approved_by/at, review_note
- [x] Rule 2: ledger cost = base when claimable, else total; supplier VAT not claimed goes to the report's "VAT paid to suppliers" line
- [x] Rule 5: bill not billed to the company is flagged; it enters the ledger only when a super admin approves it after review
- [x] Nobody approves their own bill; approvers from Approval Settings (`approve_purchase_bills`)
- [x] Approval posts one ledger entry; rejecting/reopening removes it
- [x] Duplicate bill warning (same vendor + bill no.)

### 2.2 Vendor ledger
- [x] `vendor_payments`: vendor, project, optional bill, amount, date, method, reference (who: `pay_vendors`)
- [x] Per vendor: billed (approved bills' total amount, rule 3), paid, outstanding; per-bill paid status (unpaid / part paid / paid)
- [x] Vendor statement page + printable statement; "balance confirmation received" record (as-of date, balance, note)
- [x] Cash paid out in the project report includes vendor payments

### 2.3 Petty cash
- [x] `petty_cash_claims`: project, claimed_by, date, category (Site expenses default), description, amount, receipt photos, status, approver, "reimbursed" (paid) date
- [x] Approval posts to the ledger (no VAT, rule 7); reimbursement counts in cash paid out

### 2.4 Verification
- [x] Acceptance checks 1–4 as tests
- [x] Full test suite green

## Phase 3 (done, 2026-10-01)

### 3.1 Subcontractor work orders
- [x] `work_orders`: project, subcontractor (vendor), scope, agreed amount, dates, status pending → approved → closed / cancelled; approvers `approve_work_orders`; nobody approves their own
- [x] Subcontractor bills are purchase bills linked to a work order (category Subcontract); a bill cannot exceed what is left of the agreed amount
- [x] Report "Committed" = approved open work orders' agreed amount minus their approved bills (never double-counted with cost so far)
- [x] Payments through the existing vendor payments / ledger

### 3.2 Equipment and transport
- [x] `equipment_entries`: project, kind (equipment / transport), provider vendor (optional), description, date, unit (hour / trip / day / km), quantity × rate = amount, photo, status; approvers `approve_equipment`
- [x] Approved entries post to the ledger (Equipment / Transport); entries with a vendor count as billed in that vendor's ledger

### 3.3 Key materials (quantities)
- [x] `key_materials` list (cement, rod, bricks, sand, aggregate … editable); planned quantity per project
- [x] Purchase bill lines can name a key material (quantity required) → "purchased"
- [x] `material_deliveries` with challan photo (supervisor) → "received"
- [x] `material_stock_counts` monthly: used = received ± transfers − left; reminder on a set day
- [x] `material_transfers`: to another project at cost (ledger credit / debit) or back to supplier (credit)
- [x] Report: planned vs purchased vs received vs used vs expected use from progress (e.g. "Cement purchased 1,400 bags, planned 1,000, work 60% done")

### 3.4 Verification
- [x] Tests for committed (no double count), work-order bill limit, equipment ledger and vendor billing, material usage maths and transfers
- [x] Full test suite green

## Phase 4 (done, 2026-10-01)

### 4.1 Variations (extra work)
- [x] `variations`: project, title, description, amount added to the contract, optional extra cost budget (category), client approval reference / date / document, status pending → approved / rejected; approvers `approve_variations`; nobody approves their own
- [x] Contract value = fee + approved variations (report, client balance and payment limits)
- [x] Extra cost budget of approved variations adds to that category's budget (budgets themselves are not rewritten)
- [x] Client portal shows approved extra work

### 4.2 Staff cost
- [x] Team members get a monthly salary (admin only)
- [x] `staff_cost_allocations` (project, member, month, days, salary, amount): salary × project days ÷ days present; a day split between two sites counts half each; office days stay overhead
- [x] Computed from GPS visits while daily detail exists (daily job for this and last month, before pruning) and saved permanently
- [x] Report: "Staff cost (allocated)" and "Profit after staff cost", shown only when staff cost exists

### 4.3 Company dashboard
- [x] All projects side by side: contract value, cost so far, projected final cost, projected profit, margin, % complete, health, status; totals

### 4.4 Tax & turnover
- [x] Fiscal year (Shrawan–Ashad, from settings): client receipts, projected year-end from signed contracts, warning at the set % of the VAT registration limit
- [x] Purchases on VAT bills per fiscal year (early sign)
- [x] VAT summary when registered: per month output VAT, input VAT claimed, net payable, carried-forward credit
- [x] Profit both ways per project: as PAN-only (current) and as if VAT registered (using client type and price basis)

### 4.5 Verification
- [x] Tests for variations, staff allocation, turnover/VAT maths, dashboard
- [x] Full test suite green

## Phase 5 (done, 2026-10-01)
- [x] Per-project switch "Track cost per BOQ item" (tag fields appear only when on)
- [x] Optional BOQ item tag on direct costs: muster roll ("worked on item"), work orders (their bills inherit it), purchase bills, equipment / transport, petty cash; the ledger entry carries `boq_item_id`
- [x] `material_issues` (project, BOQ item, key material, qty, date, issued_by, approved_by): valued at the project's weighted-average purchase rate (VAT-inclusive when not claimable); approvers `approve_boq_measurements`
- [x] Norms per BOQ item (material per unit, e.g. cement bags per m³), copied from the library item and editable; when a material has no issue on an item, estimate = norm × executed qty × average rate, marked "estimated"
- [x] Item report: planned value, executed %, earned value (executed qty × rate), actual cost = recorded + estimated, variance, projected cost at completion, green / amber / red, tagged-cost coverage % (low coverage warns a saving may be false)
- [x] Untagged costs stay in the project ledger (project profit always complete)
- [x] Tests and full suite green

## Decisions and assumptions (Phase 1)
- **Estimated cost to finish** defaults to `max(0, budget − cost so far − committed)`; an override can be typed per project. So projected final cost never double-counts committed amounts.
- **% work complete**: BOQ progress when the project has BOQ items; otherwise the manual % on the project; if that is blank too, the existing task/milestone progress is used and labelled as such.
- **Committed** is 0 until work orders / POs exist (Phase 3).
- **Cash paid out** = wage payments now; vendor and petty-cash payments join in Phase 2.
- Only super admins and roles with `view_project_costs` see cost and profit figures.
- VAT/PAN thresholds are settings the owner fills from the accountant; nothing is hard-coded.

## Where things are (Phase 1)

| Feature | Screen |
|---|---|
| VAT status, rate, limit, fiscal year | Admin › Finance › Company & Tax Settings |
| Who approves what / who sees profit | Admin › Team › Approval Settings |
| BOQ master library | Projects › BOQ Library (team can add; admin edits, merges, deactivates) |
| Project BOQ, progress, measurement history | Project › edit › "BOQ & Progress" tab |
| Measurement approval queue | Site › BOQ Measurements (badge shows pending) |
| Budget, cost report, health, ledger | Projects list › "Costs" (or "Costs & profit" on the project) |
| Printable statement / final P&L | "Print statement" on the cost page (`/site/projects/{id}/costs`) |

Tests: `tests/Feature/ProjectCostTrackingTest.php` (15 tests, acceptance checks 5–8 plus library, approvals, VAT settings, rule 6).

## Phase 2 screens

| Feature | Screen |
|---|---|
| Enter / approve / pay supplier bills | Site › Purchase Bills (badge = waiting for approval) |
| Petty-cash claims, approval, "mark paid back" | Site › Petty Cash |
| Vendor balance, statement, payments, confirmations | Projects › Vendors › Statement (print: /site/vendors/{id}/statement) |
| Who approves / pays / sees vendor ledger | Admin › Team › Approval Settings |

Tests: 	ests/Feature/PurchaseBillsAndPettyCashTest.php (8 tests incl. acceptance checks 1–4).

## Phase 3 screens

| Feature | Screen |
|---|---|
| Subcontractor work orders (approve, enter bill, close / cancel) | Site › Work Orders |
| Machine hire hours, transport trips | Site › Equipment & Transport |
| Deliveries, monthly stock count, transfers / returns, planned quantities, usage table | Site › Site Materials |
| The list of key materials | Site › Key Materials List (admin) |
| Key materials on the cost statement | Projects › Costs (section "Key materials") |

Tests: `tests/Feature/WorkOrdersEquipmentAndMaterialsTest.php` (8 tests).

Notes: bills against a work order are purchase bills (category Subcontract) and cannot exceed what is left on the order; hire entries naming a provider are that provider's bill in the vendor ledger; stock-count reminder runs monthly on `SITE_STOCK_COUNT_DAY` (default 1st) for execution projects with deliveries and no count that month.

## Phase 4 screens

| Feature | Screen |
|---|---|
| Extra work (variations): enter, approve (client is told) | Projects › Extra Work (Variations) |
| Monthly salary for staff cost | Team › Team Members › edit (admin only) |
| All projects side by side, weakest first | Finance › Projects Profitability |
| Fiscal-year turnover vs VAT limit, VAT-bill purchases, VAT summary | Finance › Tax & Turnover (roles with "Vendor ledger & tax") |
| Staff cost, profit after staff cost, profit "if VAT registered" | Project › Costs & profit |

Tests: `tests/Feature/VariationsStaffCostAndTaxTest.php` (7 tests).

Notes: staff cost is saved nightly (00:30, before attendance pruning at 01:00) for this and last month by `costs:allocate-staff`; months whose daily detail is already gone are left as saved. Turnover counts project payments and course fees received in the fiscal year. Output VAT is taken as the VAT inside amounts received on/after the registration date.

## Phase 5 screens

| Feature | Screen |
|---|---|
| Turn on per project | Project › edit › "Track cost per BOQ item" |
| Tag a cost to an item | "BOQ item (optional)" on muster roll, work order, purchase bill, equipment, petty cash (only when turned on) |
| Norms | BOQ Library item and the project's BOQ line (edit) |
| Issue material to an item / approve | Site › Site Materials › "Issue to BOQ item" |
| Cost per item report | Projects › Costs (section "Cost per BOQ item") and the printed statement |

Tests: `tests/Feature/BoqItemCostingTest.php` (5 tests).

## Status
All five phases of the brief are built. Suggested next: run Phases 1–4 on live projects, then switch on item costing for the bigger jobs.

## Deploy notes
`composer install`, `php artisan migrate --force`, then fill **Company & Tax Settings** and **Approval Settings**.
