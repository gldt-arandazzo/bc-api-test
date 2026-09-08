# BC API Test

A small PHP CLI that runs an end-to-end sales-order test against a Business Central
environment and emails the result to the ERP group for verification.

Used when the ERP admin requests a test on a new BC version: run the script, and it
creates → updates → releases a sales order, then notifies the group with the SO number
and a deep link to the order in Business Central.

## What it does

1. **Get a token** — Azure AD client-credentials flow (app-only). No tokens to paste.
2. **Create a sales order** — `POST salesOrders` with account/item lines and a shipping address.
3. **Update the header** — `PUT salesOrderHeaders('Order','<SO#>')` (shipping agent, payment
   method/terms, division, sales source, location) with `If-Match: *`.
4. **Release the order** — `POST .../Microsoft.NAV.releaseOrder`.
5. **Email the group** — sends via Amazon SES (SMTP) with the SO number, external document
   number, environment, and a BC deep link so the ERP group can verify the order.

Any non-2xx response from BC prints the error and aborts, so the release and email only
happen on a fully successful run. Each run creates a **new** order with a timestamped
external document number (`API-TEST-<timestamp>`).

## Requirements

- PHP 8.1+ with the cURL extension
- [Composer](https://getcomposer.org/)

## Setup

```bash
composer install
cp .env.example .env
# then edit .env
```

Fill in `.env`:

| Variable | Notes |
|----------|-------|
| `BC_TENANT_ID`, `BC_ENVIRONMENT`, `BC_COMPANY_ID` | From the BC API URL |
| `BC_API_PATH` | Custom API segment, e.g. `greatLakesDental/webAPI/v1.0` |
| `BC_COMPANY_NAME` | Optional; only sharpens the email deep link |
| `BC_CLIENT_ID`, `BC_CLIENT_SECRET` | Azure AD app (client-credentials / app-only) |
| `BC_CURRENCY_CODE`, `BC_CUSTOMER_NUMBER`, `BC_EXTERNAL_DOC_PREFIX` | Test order data |
| `SES_FROM`, `SES_TO` | From address and comma-separated recipient list |
| `SES_SMTP_HOST`, `SES_SMTP_PORT` | SES SMTP endpoint for your region (e.g. `email-smtp.us-east-1.amazonaws.com:587`) |
| `SES_SMTP_USERNAME`, `SES_SMTP_PASSWORD` | **SES SMTP credentials** (see note below) |

`.env` is git-ignored — it holds the app secret and SMTP password, so it never gets committed.

## Run

```bash
php bc_order_test.php
```

## Notes / gotchas

- **SES SMTP credentials are not IAM API keys.** They look similar (the username is an
  `AKIA…` key id), but the SMTP *password* is a derived signature (~44 chars) and the AWS
  SDK / SES API (SigV4) cannot authenticate with it. This script sends over the SES **SMTP**
  endpoint on purpose. Create SMTP credentials in the SES console → *SMTP settings*.
- **SES sandbox.** If the SES account is still in sandbox, the send succeeds but mail to
  **unverified** recipients is silently dropped. If some recipients don't receive the email,
  either verify those addresses in SES or request production access.
- **Deep link.** Points at the Sales Order card (page **42**, the BC standard). If your
  environment uses a custom sales-order page, change the `page=42` value in `bc_order_test.php`.
