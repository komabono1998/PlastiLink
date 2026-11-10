# Changelog

Phases follow the project plan. Every phase was tested before it was committed.

- **Waste picker editing:** managers (Super Administrator, Hub Manager) can edit a picker's name, phone, neighbourhood, hub, address details, national ID and notes (`/pickers/{id}/edit`, migration 0023, audited with field names only, hub-scoped), and approve, suspend or reactivate from the picker list as well as the picker page.
- **Phase 11, testing and deployment:** enforced Content-Security-Policy (no inline scripts; confirmations and printing now use data attributes), HSTS and more headers; route-by-role security suite; end-to-end journey test; load test; `create-admin`, pre-flight check, deployment package builder (single or split layout), production `.htaccess`; encrypted, verified, pruned backups with restore; runbooks, UAT scripts, user guides, data-protection notes.
- **Phase 10, geographic and programme monitoring:** map and hotspots (Leaflet, bundled), validated coordinates, programme progress and audited target editing, Geographic and Programme reports; front-end libraries are now tracked by git.
- **Phase 9, integrations:** SMS queue with Africa's Talking gateway, MTN MoMo and Airtel Money payout providers, token-protected callbacks, USSD for pickers.
- **Phase 8, reports and analytics:** eight reports with CSV, Excel and PDF exports, audited and role-scoped.
- **Phase 7, financial management:** money ledger, earnings, payouts, payments, invoices, recycler payments, reconciliation.
- **Phase 6, recycler marketplace:** orders, negotiation, reservation, dispatch and delivery.
- **Phase 5, inventory:** stock ledger, transfers, dispatches, adjustments.
- **Phase 4, collection hub operations:** prices, collections, weighing, approval, receipts.
- **Phase 3, administration:** users, hubs, neighbourhoods, registrations and approvals.
- **Phase 2, authentication and RBAC.**
- **Phase 1, foundation.**
