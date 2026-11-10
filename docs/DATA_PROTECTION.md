# Data protection notes

> This is an engineering summary for the PlastiLink team and its partners. It is **not legal advice**. Uganda's Data Protection and Privacy Act, 2019 applies; have your organisation's legal adviser confirm obligations such as registration with the Personal Data Protection Office, appointment of a data protection officer, and cross-border transfer rules (hosting abroad, SMS and payment providers).

## What personal data PlastiLink holds

| Data | Who it is about | Why | Who can see it |
|---|---|---|---|
| Name, phone number | waste pickers, staff, recycler contacts | identify, pay, notify | staff for their own hubs; on finance screens phone numbers are masked for everyone except Super Administrators |
| National ID number | waste pickers (optional) | verification | stored; kept out of receipts, reports, exports, SMS and USSD (check the picker page with your own eyes during UAT) |
| Collections, earnings, payouts | waste pickers | income record and payment | the picker; staff for their own hubs; project managers and administrators |
| Business name, address, registration no. | recyclers | trading | staff with recycler permissions |
| Sign-in history, audit trail | all users | security | Super Administrator (audit permission) |
| Location | hubs, recycling companies, neighbourhood centres only | maps | **never** an individual picker or collection |

## Built-in safeguards

- Consent text is shown and recorded (version and time) at registration.
- Role-based access with server-side checks on every route; hub staff see only their hubs.
- Four-eyes rules: nobody approves their own collection, payout or recycler payment.
- Passwords are hashed; temporary passwords are shown once and must be changed.
- Everything sensitive is in an append-only audit trail; exports are audited.
- Reports and exports contain no national IDs and no phone numbers; individual pickers are never mapped.
- Backups are encrypted; secrets live only in the server's local config.
- Provider integrations (SMS, mobile money) are off by default and send only what they need (an amount, a phone number, a short message).

## Your organisational tasks

- Publish a short privacy notice at hubs and on the registration page (what is collected, why, who sees it, how to ask for correction or removal).
- Decide retention: how long registration, collection and payment records are kept. Financial records usually have a statutory minimum; do not delete ledger rows. To honour a removal request for a picker who has money history, **deactivate** the account and remove optional personal fields (national ID, phone) instead of deleting history; ask a developer to do this carefully and log it.
- Limit who gets Super Administrator and Project Manager accounts; review them monthly.
- Train hub staff never to share logins and never to photograph or message screens containing personal data.
- Agree with Africa's Talking, MTN and Airtel (as your processors) what data they receive and how it is protected.
- Have a breach procedure (`docs/OPERATIONS.md`, Incidents) and know who must be informed, and how fast.
