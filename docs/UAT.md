# User acceptance testing (UAT)

Purpose: before real people and real money use PlastiLink, the people who will run it check, on a **staging copy**, that it does what they need. Automated tests prove the code is consistent; only your team can confirm it fits how Gulu hubs actually work.

## How to run UAT

1. Use a staging address, not the live site (`docs/DEPLOYMENT.md`), or a local copy.
2. Load demo data: `php seed.php demo-users` then `php seed.php demo-data` (**staging/local only, never live**). The first command prints a password for each demo role; give each tester their own role's login.
3. Testers work through the scenario for their role. Mark each step **Pass**, **Fail** (say what happened, screenshot if you can) or **Not applicable**.
4. Fix or explicitly accept every Fail. Repeat the failed scenarios. Sign off at the end.

Use real devices: the picker and hub operator scenarios on a **phone** (the screen must not need sideways scrolling), others on a laptop.

---

## Scenario P: Waste picker (phone)
| # | Step | Expected | Result |
|---|---|---|---|
| P1 | Open the site, tap "Register as a waste picker", fill the form, accept consent | Message that the registration was received; cannot sign in yet | |
| P2 | Try to sign in before approval | Refused with a clear message | |
| P3 | After the hub approves, sign in with phone number and password | Your own dashboard; menu shows only picker pages | |
| P4 | Open "My collections" | Only your own deliveries, newest first | |
| P5 | Open a receipt | Weights, price, total, reference; no national ID | |
| P6 | Open "My earnings" | Balance equals approved collections minus payouts | |
| P7 | Request a payout above your available balance | Refused with the available amount | |
| P8 | Request a valid payout (cash, then mobile money) | Request appears as "waiting for approval" and holds that amount | |
| P9 | Cancel a pending request | Amount is available again | |
| P10 | Try to open another picker's page by changing the number in the address | Not found / not allowed | |
| P11 | Sign out; press Back | Sign-in page, not your data | |

## Scenario O: Hub operator (phone or tablet at the hub)
| # | Step | Expected | Result |
|---|---|---|---|
| O1 | Sign in; open "Record collection" | Search finds an active picker by name, code or phone | |
| O2 | Record gross and tare weights; net, price and value are shown | Net = gross − tare; value uses the hub price | |
| O3 | Save; save the same form twice quickly | One collection only | |
| O4 | Try to approve your own collection | Not allowed: someone else must approve | |
| O5 | Reweigh a pending collection | New weights replace the old; history kept | |
| O6 | Open pages you should not see (users, finance, reports) | Not allowed | |
| O7 | Look at another hub's collections | Not found | |

## Scenario H: Hub manager
| # | Step | Expected | Result |
|---|---|---|---|
| H1 | Approve a pending picker registration | Picker can now sign in; an SMS is queued | |
| H2 | Reject one with a reason | Picker cannot sign in; reason recorded | |
| H3 | Approve a collection recorded by the operator | Receipt issued; picker earns; hub stock increases | |
| H4 | Reject a collection with a reason | No earnings, no stock | |
| H5 | Transfer stock to another hub; receive it at the other end | Stock moves only on receipt; both ledgers match | |
| H6 | Dispatch to a recycler against an order; confirm | Stock leaves the hub | |
| H7 | Request an adjustment (damaged stock) | Needs a second person's approval | |
| H8 | Open Reports → Collections, Pickers, Hubs, Inventory | Only your hubs' figures; numbers agree with what you recorded | |
| H9 | Open Map and hotspots | Your hub(s) only | |
| H10 | Pay an approved cash payout; record the receipt note | Picker balance falls; payout shows paid | |

## Scenario R: Recycler
| # | Step | Expected | Result |
|---|---|---|---|
| R1 | Register your company on the public form | Pending until approved | |
| R2 | After approval, browse the marketplace | Available stock by hub and material | |
| R3 | Place an order (quantity, price) | The hub sees it; negotiation (counter-offers) works up to its limit | |
| R4 | Confirm a delivery (full, then partial) | Received kg recorded; shortfalls visible | |
| R5 | Open your invoice and statement | Amount = confirmed kg × agreed price; balance owed correct | |
| R6 | Try to open another recycler's order or invoice | Not found | |

## Scenario M: Project manager
| # | Step | Expected | Result |
|---|---|---|---|
| M1 | Approve a payout request made by a picker | Payment created, not yet paid | |
| M2 | Try to approve a request you made yourself | Not allowed | |
| M3 | Record a recycler's payment; ask a colleague to confirm it | Counts only after the second person confirms | |
| M4 | Reconcile successful payments against the statement | Items tick off; unreconciled list shrinks | |
| M5 | Open every report; download CSV, Excel and PDF of two | Files open in Excel / a PDF reader; figures equal the screen | |
| M6 | Open Programme progress | Targets, progress bars, monthly table; cannot edit targets | |

## Scenario A: Super administrator
| # | Step | Expected | Result |
|---|---|---|---|
| A1 | Create a hub, assign a manager and an operator | They see only that hub | |
| A2 | Add neighbourhoods **with real coordinates**; place a hub on the map | Map shows it; coordinates outside Uganda are refused | |
| A3 | Set prices for each material | Appear on new collections; old collections keep their old price | |
| A4 | Create, suspend and reactivate a user | Suspended user is signed out and cannot sign in | |
| A5 | Reset a user's password | One-time temporary password; user must change it | |
| A6 | Change a programme target | Progress updates; audit trail shows old and new value | |
| A7 | Open the audit trail | Shows approvals, payments, exports, denials | |
| A8 | Reverse a collection and a payment (with reasons) | Opposite entries; history kept; ledger checks still pass | |
| A9 | Open `/finance` and `/inventory` | "Ledger check passed" and "Stock check passed" | |

## Cross-checks everyone should try
- Wrong password five times: the account locks for a few minutes.
- Leave a form open for hours, then submit: asks you to reload (no data loss, no error page with technical text).
- Use the site on weak mobile data: pages should load in a few seconds (the large map and chart libraries only load when you ask for them).
- Look for any screen that shows a phone number, ID number or amount to someone who should not see it. Report it as a **security** failure.

## Sign-off

| Role | Name | Date | All scenarios passed? | Open issues |
|---|---|---|---|---|
| Waste picker representative | | | | |
| Hub operator | | | | |
| Hub manager | | | | |
| Recycler representative | | | | |
| Project manager | | | | |
| Super administrator / project owner | | | | |

Go-live decision: ☐ Approved ☐ Approved with accepted issues (listed above) ☐ Not approved
