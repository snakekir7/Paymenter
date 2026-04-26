# YooMoney Wallet (Paymenter gateway)

Personal-wallet RUB acceptance via YooMoney quickpay and HTTP notifications.

## Configuration

- Set **wallet_account** and **notification_secret** (HTTP notification secret from YooMoney).
- Callback URL for YooMoney: `POST …/extensions/yoomoney_wallet/notification` on your billing host (CSRF excluded).

## HTTP notifications

- **`test_notification`** is a **boolean** query field from YooMoney (e.g. `true` / `1`), **not** a value of `notification_type`. Real types stay `p2p-incoming` or `card-incoming`. Test pings are acknowledged with **200** and **never** call `addPayment`.
- **Signature `sign`:** HMAC-SHA256 over a canonical string of **all** notification parameters **except `sign`**, keys sorted alphabetically, values URL-encoded (UTF-8, RFC 3986). Output is **hex** (compare case-insensitively). **`sha1_hash` is included in the canonical string when present**; after **2026-05-18** YooMoney may stop sending it — absence must not break verification.
- **Empty `label`:** allowed by YooMoney docs, but **this gateway rejects** non-test notifications without a non-empty label, because linking a transfer to a Paymenter invoice requires the label we stored at `pay()`.

## Quickpay (checkout)

Official integration uses **POST** `https://yoomoney.ru/quickpay/confirm` with **`quickpay-form=button`** and required **`paymentType`**: **`PC`** (wallet) or **`AC`** (card). This module returns a **GET redirect URL** with the same parameters as a **shortcut**; before production, confirm YooMoney accepts GET with this query string or switch to an auto-submit POST form.

- **`default_payment_type`:** used when both wallet and card notification modes are enabled (otherwise `PC` or `AC` is chosen from toggles).

## Go-live

Re-run against the **official YooMoney test vector** and your wallet’s HTTP notification settings. No secrets in this file or in tickets.
