# DBMTS — Digital Batch Manufacturing & Traceability System
### Proof of Concept — Feature Overview & Presentation Draft

**Prepared for:** Wet Mustard Digitalisation Project
**Audience:** Stakeholder / management demo
**Status:** Proof of concept, built against the functional specification (Scope.txt v1.2)

---

## 1. What is DBMTS?

DBMTS is a purpose-built web application that replaces the paper-based batch manufacturing, packing and traceability records currently used on the Wet Mustard production line. It is driven by existing WinMan Manufacturing Orders (MOs), captures every stage of production electronically, and automatically produces the compliance paperwork, traceability chain and management reporting that today is done by hand.

**In one sentence:** *operators pick a live WinMan MO, work through an on-screen batch record instead of a paper batchcard, and the system takes care of traceability, CCP checks, sign-offs, PDF paperwork and email reporting automatically.*

---

## 2. The problem it solves

| Today (paper-based) | With DBMTS |
|---|---|
| MO number, product, batch number, BBE re-written by hand on every sheet | Entered once, reused everywhere automatically |
| Traceability (pallet → drum/bucket → pallecon → batch → ingredient lots) reconstructed manually from multiple paper sheets | One search box — forward or backward — returns the full genealogy instantly |
| CCP checks (metal detector, weights) recorded on paper, reviewed after the fact | Captured live on screen; a failing check raises an instant email alert |
| QA sign-off is a physical signature on paper | Electronic signature with user, timestamp and meaning, tied to an audit trail |
| Daily/weekly paperwork (calibration sheets, traceability sheets, etc.) filled in and filed manually | Auto-generated as a PDF and emailed to the right people on a schedule |
| No visibility of overdue checks, open batches, or exceptions without physically checking the floor | Reporting & notification framework flags these automatically |

---

## 3. Core capability areas

### 3.1 Manufacturing Order (MO) driven production
- Live read-only integration with WinMan (existing MOs only — DBMTS never creates or edits WinMan data other than the final finished-goods booking).
- Search/select outstanding Manufacturing Orders, view live WinMan Work-in-Progress components (the Bill of Materials) for the selected MO.
- Recipe-driven batch creation: batch size and process steps come from a controlled internal "Recipe" record, cross-checked against the WinMan structure.
- One MO can spawn multiple batches (e.g. large orders split across several physical batches), each tracked independently.

### 3.2 Electronic batch record (replaces the paper batchcard)
- Ingredient lot capture: lot number, quantity, weighed-by/when, tipped-by/when — with electronic sign-off for each step.
- Process step tracking with mandatory sign-offs and recipe-specific parameter capture (temperatures, times, etc.).
- Recipe **batch variants** — for products with more than one approved batch size, the operator selects the correct variant and the record adapts.
- A batch cannot be marked complete until every ingredient is signed and every required step is finished — enforced by the system, not by a supervisor checking paper afterwards.

### 3.3 Critical Control Point (CCP) & quality checks
- **Metal detector verification**: start-of-shift / hourly / end-of-shift checks (Fe, non-Fe, SS test pieces), pass/fail logic, bin lock/empty confirmation, mandatory failure-action notes.
- A failing metal detector check immediately raises a real-time alert (see §3.6) — no waiting for someone to notice on the daily report.
- **Calibration check sheets** (scales, salt meter, viscosity meter autozero) — daily electronic records replacing WM001/WM002/WM006/WM013 paper sheets.
- **QA review & sign-off**: a batch must be explicitly approved or rejected by QA before it is considered closed, with a reason recorded on rejection.

### 3.4 Packing, drums & primary packaging traceability
- **Pallecon filling**: serials, top/bottom seals, liner number/batch, fill weight.
- **Packing runs**: IBC consumption, hourly hygiene checks, weight checks (6 weighings → automatic average → pass/fail), pallet records.
- **Drum processing**: drum-by-drum records (filler weight, bag seal, drum seal, liner condition) grouped under pallets under a processing run.
- **Primary packaging traceability** (replaces WM014/WM015/WM047): supplier lot/job number or NVE-style traceability for drums, buckets & lids, in one flexible screen instead of three separate paper forms.

### 3.5 End-to-end traceability ("genealogy" search)
- Single global search screen, two directions:
  - **Backward** — from a finished pallet/drum/bucket/pallecon serial, batch number or ticket, trace back to the manufacturing batch and every ingredient lot used.
  - **Forward** — from a raw material or packaging lot number, find every batch it was used in.
- This is the feature that turns a multi-hour paper trace-back (during a recall or audit) into a single search.
- A "traceability exceptions" report flags batches with gaps in the chain automatically.

### 3.6 Reporting & real-time notifications framework
- **Scheduled reports** — daily production summary, open batches, overdue metal detector checks, packing weight exceptions, drum processing summary, QA approval queue, and more — generated and emailed automatically on a schedule (mirrors the existing MintSystemNew reporting pattern already used elsewhere in the business).
- **Auto-generated compliance documents** — any controlled document (e.g. calibration sheets, or a traceability record triggered by a specific raw material being issued to a batch) can be configured, without new code, to watch for a WinMan raw-material issue and automatically build + email the correct PDF for that day — the same mechanism already used for the packaging traceability documents (WM014/WM015) can now be pointed at any document, e.g. Vinegar IBC Traceability (WM003).
- **Real-time alerts** — configurable rules (missed metal detector check, batch open too long, QA approval overdue, CCP failure, packing weight breach) raise an immediate email, independent of the daily schedule, with a cooldown to prevent alert-storming.
- All of this is admin-configurable from within the app (recipients, schedule, thresholds) — no developer involvement needed to add/remove an email recipient or change a threshold.

### 3.7 WinMan finished-goods booking
- Once a batch is complete, its packed finished goods can be booked back into WinMan (inventory + MO decrement) via WinMan's own approved stored procedure — with duplicate-lot protection, outstanding-quantity checks and a full booking log. Gated behind a configuration flag for controlled roll-out.

### 3.8 Audit trail, electronic signatures & compliance
- Every sign-off (weighing, tipping, process step, CCP check, QA approval/rejection, booking) is captured as an **electronic signature**: who, what, when, and what it means.
- A parallel **audit trail** records every meaningful change (old value → new value, action, reason, user) across the system.
- On-demand **audit-ready batch export** — a single click produces a complete, self-contained HTML record of a batch (ingredients, steps, CCP checks, packing, drums, packaging, all signatures) suitable for handing to an auditor or customer.
- **System error log** — technical errors are captured and available to admins for review/export, supporting ongoing quality/IT investigation.

### 3.9 Role-based access & administration
- Roles: operator, production supervisor, QA/technical, planning, administrator, auditor.
- Microsoft Entra ID (Office 365) single sign-on, restricted to the company's tenant.
- Admin-only settings area: feature toggles, document/paperwork configuration, recipe & batch-size management, product-to-recipe mapping (reading live WinMan structure data), user role sync.

### 3.10 Operator-friendly navigation
- Tile-based main menu grouping work by area (Manufacturing, Packed/Packaging, Metal Detection, Quality & Lab Testing, Calibrations) rather than a technical menu — designed so a shop-floor operator can find their screen in one tap.

---

## 4. What this proves (proof-of-concept goals)

- **Feasibility** — a live, read-only integration with the existing WinMan ERP is achievable without touching WinMan's own data (other than the single approved booking call), proving the "MO-driven" approach from the spec works end-to-end.
- **Paper replacement** — every paper form named in the functional spec (batchcards, WM003/004/005/006/010/011/012/013/014/015/016/046/047, etc.) has a direct, working digital equivalent.
- **Traceability speed** — full genealogy (forward and backward) is retrievable in seconds instead of a manual paper trace.
- **Reduced manual reporting effort** — the same reporting engine already proven in MintSystemNew has been reused, so daily/weekly compliance paperwork and exception reporting requires zero manual effort once configured.
- **Extensibility without new development** — adding a new auto-generated document, report, or notification rule is a configuration change (Settings screens), not a code change, keeping ongoing running costs low.

---

## 5. Suggested demo flow (for the presentation)

1. **Login** (Microsoft 365 sign-in) → tile-based main menu.
2. **Start a batch** from a live outstanding WinMan MO → show the WinMan components pulled in automatically.
3. **Work through the batch record** — add an ingredient lot, sign it off (weighed/tipped), complete a process step.
4. **Record a metal detector check** — show a pass, then demonstrate a fail triggering an alert email.
5. **Packing / drum / packaging** screens — quickly show one of each being recorded.
6. **QA approve the batch.**
7. **Traceability search** — pick something just created and trace it backward and forward.
8. **Export the batch record** as an audit-ready document.
9. **Reporting admin** — show a scheduled report / auto-generated document configuration and a sent report log.
10. **Settings** — show how a new document trigger or recipient can be added without a developer.

---

## 6. Current limitations (be upfront about these)

- Visual/photographic QA comparison is explicitly out of scope for this phase (per spec).
- WinMan finished-goods booking is feature-complete but gated off by default pending controlled roll-out (`WINMAN_BOOKING_ENABLED`).
- DBMTS only *consumes* existing WinMan MOs — it does not create new MOs (a possible future phase).
- Some legacy manual entry screens (e.g. Wet Mustard Lab Testing, Rinse Water Test) remain manual-entry for now; only documents driven by a specific raw-material issue (e.g. IBC/packaging traceability) have been converted to auto-generation so far.

---

## 7. Technology summary (for a technical appendix / Q&A)

- Laravel 12 (PHP 8.2) using the "Vivid" architecture pattern (Controllers → Features → Jobs/Operations, grouped by business Domain) for a clean, testable codebase.
- Livewire/Volt for the interactive UI (no separate front-end framework/build complexity), Tailwind CSS for styling.
- SQL Server for the DBMTS application database; a strictly read-only (bar one approved stored procedure) connection to the separate WinMan SQL Server database.
- Automated test suite covering the core workflows (batch entry, CCP checks, traceability, reporting, booking, auth).
- PDF generation (Dompdf) for all controlled paperwork; Office 365/PHPMailer for email delivery of reports and alerts.
