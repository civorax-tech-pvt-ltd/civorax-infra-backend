# Project Cost and Progress Tracking: Build Brief

Company: Civorax Infra Pvt Ltd (Nepal). Stack: Laravel (routes in web.php).

## 0. Goal

For every project, track the contract value, all costs, and BOQ work progress, so the system can answer:
1. Is the project making a profit or a loss (now, and projected at completion)?
2. Is the work progressing as estimated (by BOQ item)?
3. Is spending ahead of progress?

## 1. Already built (reuse, do not rebuild)

- Contract value (project fee or accepted quotation)
- Payments received from the client
- Labour cost from approved muster rolls
- Approval workflow and GPS attendance
- BOQ items: the estimate module becomes the master BOQ library (see section 13.1). Project BOQs are picked from it, and new items are created in both places at once.

## 2. Business rules: VAT (must support both modes)

Civorax is PAN-only today and may register for VAT later. Registration must be a settings change only, with no rewriting of old records.

**Company settings**
- `vat_registered` (boolean, default No)
- `vat_registration_date` (nullable date)

**Purchase bill fields**
- `bill_type` (VAT bill or PAN bill), `vendor_id`, `bill_no`, `vendor_pan_vat`, `bill_date`
- `base_amount`, `vat_amount`, `total_amount`
- `billed_to_company`, `vat_claimable`, `paid_status`, bill photo

**Rules**
1. `vat_claimable` is computed, not typed. It is Yes only when: company `vat_registered` = Yes, the bill is a VAT bill, it is billed to Civorax, and `bill_date` >= `vat_registration_date`. Otherwise it is No.
2. Cost rule: if `vat_claimable` = Yes, ledger cost = `base_amount`. Otherwise ledger cost = `total_amount`.
3. The vendor ledger (billed, paid, owed) always uses `total_amount`, because that is what we owe the vendor.
4. The report shows "VAT paid to suppliers (not claimable)" as a separate line.
5. If `billed_to_company` is not Civorax, flag the bill and keep it out of the project cost ledger. It enters only after admin review.
6. While PAN-only, quotations and contract value have no VAT line. The contract value is the final price the client pays. Do not print "VAT 13%" on any quotation or invoice from a PAN-only company.
7. Labour, salaries and petty cash carry no VAT.
8. Budgets and BOQ rates for materials bought on VAT bills include the supplier's 13% while PAN-only. Materials on PAN bills stay at the bill price.
9. Each project stores `client_type` (house owner, company, government) and whether the price is VAT-inclusive or plus VAT, for future reporting.

Note for the owner: VAT and PAN rules, thresholds and filing details change with each Finance Act. Confirm them with your accountant. The system only stores and calculates what the settings tell it.

## 3. Budget (estimated cost)

- One budget amount per category, per project. Extendable to BOQ items later without changing the ledger.
- Categories: Materials, Labour, Subcontract, Equipment / machinery hire, Transport, Site expenses, Contingency.
- The budget is what we expect to spend, which is different from the quotation. Planned profit = contract value - total budget.
- Materials budget = expected total paid, including supplier VAT while PAN-only.

## 4. Actual costs and the project cost ledger

Every cost entry goes through approval and then lands in one ledger under a category.

| Source | How it gets in | Phase |
|---|---|---|
| Labour | Automatic from approved muster rolls | 1 (exists) |
| Material purchases | Purchase bill: vendor, bill no., bill type, base/VAT/total, items and quantities, photo, paid or unpaid | 2 |
| Site expenses | Supervisor petty-cash claim with receipt photo, admin approves | 2 |
| Subcontractors | Work order (agreed amount), then bills, then payments | 3 |
| Equipment and transport | Hire or trip entries (JCB hours, tipper trips) | 3 |
| Extra work (variations) | Client-approved additions to contract value | 4 |
| Staff cost (optional) | Engineer and supervisor salaries shared by GPS attendance days per project | 4 |

**Ledger columns:** project_id, category, source_type, source_id, amount (cost per rule 2), entry_date, status, approved_by, approved_at, nullable `boq_item_id` (for later cost tagging).

**Vendor ledger:** per vendor, billed (total), paid, outstanding. Printable statement for year-end balance confirmation. Optional "signed confirmation received" flag.

## 5. Report definitions

**Project cost report**
```
Contract value (accepted quotation + approved extra work)
Cost so far (approved ledger)              and % of budget
Committed, not yet billed (work orders, POs)
Estimated cost to finish (remaining beyond committed)
--------------------------------------------------
Projected final cost = cost so far + committed + estimated cost to finish
Projected profit = contract value - projected final cost   (and margin %)
Cash: received - paid out
VAT paid to suppliers (not claimable)
```
- "Estimated cost to finish" is the remaining cost beyond what is already committed, so nothing is counted twice.
- Cash position is shown separately from profit.
- Budget vs actual per category. Warning at 80%, alert when over 100% (for example "Materials 104% of budget").
- Two profit lines: "Project profit" and "Profit after staff cost". The second is shown only when staff cost exists.
- Final printable profit or loss statement after project completion.
- Company dashboard: all projects side by side (contract value, cost, profit, margin, progress, status), showing which make money and which lose.

**Project health status (green / amber / red)**
- Compare % of budget spent with % of work complete.
- Green: spending at or below progress.
- Amber: spending 5 to 10 points ahead of progress, or any category above 80%.
- Red: spending more than 10 points ahead, or projected final cost above contract value.
- % of work complete comes from BOQ progress (section 6). If the project has no BOQ, use a manual % complete entry.

## 6. BOQ progress tracking (independent of cost, Phase 1)

**Tables**
- `boq_items`: project_id, code, description, unit, quantity, rate, planned_value (= quantity x rate), planned_start, planned_end, is_variation
- `boq_measurements`: boq_item_id, measured_date, executed_quantity (cumulative), photos, entered_by, status (pending / approved / rejected), approved_by, approved_at, remarks

**Rules**
- Engineers enter executed quantity with date and site photos. Admin approves. Only approved measurements count.
- Measurements are cumulative and every one is kept as history.
- Item progress % = latest approved cumulative quantity / BOQ quantity.
- If executed quantity is above BOQ quantity, show "excess, variation needed". Do not cap silently.
- Work not in the BOQ is added as an extra item with `is_variation` = true.
- Project progress % = sum(executed quantity x rate) / sum(planned_value), weighted by value, not a simple average.
- Item status: Not started, In progress, Completed. Schedule status: On track or Delayed, by comparing actual progress with planned progress today from planned_start and planned_end.
- Progress is calculated from quantity only, never from cost.

**Screen:** item table (BOQ qty, executed qty, progress %, planned % today, schedule variance, status), project progress bar, list of delayed items, measurement history and photos per item.

## 7. Material quantity tracking (Phase 3, after purchase bills)

Phase 1 and 2 are cost-only for materials. Later, add light quantity tracking for key materials (about 5 to 8 items such as cement, rod, bricks, sand, aggregate):
- Purchase bill lines store item and quantity (mandatory for key materials).
- Supervisor records each delivery (quantity and challan photo).
- Monthly stock count by the supervisor: used = received - left. A reminder is sent on a set date.
- Planned quantity per key material from the estimate. The report compares purchased vs planned vs expected use (from progress), for example "Cement purchased 1,400 bags, planned 1,000, work 60% done".
- Transfer or return entries to move leftover material to another project at cost, or back to the supplier.
- No daily stock register.

## 8. BOQ cost per item (Phase 5, optional per project)

- Item actual cost is always calculated from ledger entries with `boq_item_id`, never typed in.
- Direct costs (labour gangs, subcontract, equipment, transport, petty cash) carry an optional BOQ item tag. Muster roll gets an optional "worked on item" field.
- Shared materials: `material_issues` (project, item, material, quantity, date, issued_by, approved_by), valued at the project's weighted-average purchase rate (VAT-inclusive when not claimable). Limit to key materials.
- `boq_item_norms` (material or labour per unit, for example cement bags per m3). When no issue entry exists, estimate from norms x executed quantity and mark it "estimated".
- Show actual cost split into recorded and estimated, plus a "tagged cost coverage" % per item, so a low figure warns that a saving may be false.
- Item report: planned, executed %, earned value (executed qty x rate), actual cost, variance, projected cost at completion, green/amber/red.
- Untagged costs stay in the project ledger under their category, so project profit is always complete.

## 9. Company-level extras

- **Turnover tracker:** sum client bills per fiscal year (Shrawan to Ashad), project the year-end total from signed contracts, and warn at 80% of the VAT registration limit. The limit is a company setting, not hard-coded.
- **VAT summary (when registered):** per month, output VAT, input VAT claimed, net payable, and carried-forward credit.
- **Purchases on VAT bills per fiscal year** shown as an early sign that registration may be due.
- Show profit both ways per project (as PAN-only and as VAT-registered), using the project's client type and price basis, so the owner can compare before registering.

## 10. Build phases

1. **Phase 1:** company tax settings (VAT status, limit, rate, fiscal year), configurable approver roles, BOQ master library with search and create-on-the-fly, cost budget by category, project cost report (labour and payments received already exist), BOQ items with measurements and progress, progress-vs-spend status, manual % complete fallback.
2. **Phase 2:** purchase bills with vendor ledger, VAT fields and rules, petty-cash claims with approval.
3. **Phase 3:** subcontractor work orders, bills and payments; equipment and transport entries; key material quantities, deliveries, monthly stock count, transfers.
4. **Phase 4:** extra work / variations (adds to contract value), staff cost allocation, company dashboard, turnover tracker, VAT summary.
5. **Phase 5:** BOQ cost per item.

## 11. Technical requirements

- Laravel migrations, models, policies and form requests. Money stored as decimal(14,2), never float.
- Approval states on every entry: draft, pending, approved, rejected. Only approved entries count in reports.
- Audit trail on amount, status and approval changes.
- Photo uploads for bills, receipts, challans and measurements.
- Role permissions: supervisor and engineer enter, admin approves, accountant views vendor ledger and VAT.
- `vat_claimable` computed from company settings and bill date, so registering later is only a settings change.
- Reports calculated from the ledger, never from typed totals.

## 12. Acceptance checks

1. PAN-only: a 1,130 VAT bill (base 1,000, VAT 130) adds 1,130 to cost, and the report shows 130 under "VAT paid to suppliers (not claimable)".
2. After the registration date is set: the same kind of bill dated on or after it adds 1,000 to cost. Bills dated before it still add 1,130.
3. The vendor ledger shows 1,130 owed in both modes.
4. A bill billed to another company does not enter any project cost ledger and is flagged.
5. Projected final cost does not double-count committed amounts.
6. A category at 80% shows a warning, and at over 100% shows an alert.
7. A BOQ item with 30 of 45 units approved shows 67%. Executed above BOQ quantity shows "excess, variation needed".
8. Project status turns red when spending is more than 10 points ahead of progress.

## 13. Decisions from the owner (final)

### 13.1 BOQ master library (estimate module)

There is no reusable BOQ data to rely on, so the estimate module is the master library of BOQ items, built up over time.

- **Master table `boq_master_items`** (company-wide): code, description, unit, default rate, default material/labour norms (optional), category, VAT-inclusive flag for the rate, active flag, created_by.
- **Project BOQ is picked from the library.** When a project is set up, the user searches the master library and adds the items that apply to that project, with a project-specific quantity and rate (the default rate is copied in, then editable). This creates `boq_items` rows linked by `master_item_id`.
- **Create new items on the fly.** If an item is not in the library, the user creates it while building the project's BOQ. It is saved to the project and to the master library at the same time (same screen, one action), so it is searchable for later projects.
- **Quick search** in the library by code, description and category, with recent and most-used items first, so a new project's BOQ is fast to assemble.
- **Copy from a previous project** as a shortcut: choose a finished project and import its BOQ items with quantities and rates to edit.
- **Rate changes do not rewrite history.** A project keeps its own copied rate. Editing a master item's default rate affects only future projects. Keep `rate_updated_at` on the master item.
- **Duplicate control:** warn when a new item's code or description closely matches an existing one. Admin can merge or deactivate duplicates. Items used in any project cannot be deleted, only deactivated.
- In PAN-only mode, rates for VAT-billed materials include the supplier's 13% (per section 2).

### 13.2 VAT registration limit

- A configurable company tax setting, not hard-coded. Suggested settings: `vat_registration_limit` (amount), `vat_warning_percent` (default 80), `fiscal_year_start_month` (Shrawan), and the `vat_rate` (default 13). The owner enters the current values from the accountant, and can change them when the Finance Act changes. The turnover tracker (section 9) reads these values.

### 13.3 Approvals

- Default: the **admin role** approves measurements, purchase bills, petty-cash claims and other cost entries.
- **Configurable approver roles:** a settings screen lets the admin choose which roles may approve each type of entry (measurements, purchase bills, petty cash, work orders, variations). Build approval checks against these permissions, not against a hard-coded "admin" role, so this works later without code changes.
- The person who enters an entry cannot approve their own entry. Every approval stores who approved and when.
