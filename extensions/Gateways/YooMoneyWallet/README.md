# YooMoney Wallet (Paymenter gateway)

Personal-wallet RUB acceptance via YooMoney quickpay and HTTP notifications.

## Configuration

- Set **wallet_account** and **notification_secret** (HTTP notification secret from YooMoney).
- Callback URL for YooMoney: `POST …/extensions/yoomoney_wallet/notification` on your billing host (CSRF excluded).

## Amount fields (HTTP notifications)

Per YooMoney’s wallet HTTP notification spec:

- **`amount`** — operation amount credited to the receiver’s wallet (net of YooMoney commission in typical examples).
- **`withdraw_amount`** — amount debited from the payer (what leaves the sender’s wallet or card). This aligns with the **`sum`** you pass on the quickpay link for a full invoice payment.

They may differ (e.g. `amount=98.00`, `withdraw_amount=100.00`). The gateway uses this pair to close the invoice and record commission.

### Default behaviour (`fee_mode` = receiver_fee, `amount_match_basis` = withdraw_amount)

- Invoice remaining is compared to **`withdraw_amount`** (plus **amount tolerance** if set).
- **`ExtensionHelper::addPayment`** is called with:
  - **transaction `amount`** = **`withdraw_amount`** (gross applied to the invoice, same idea as PayPal’s gross capture on the invoice),
  - **`fee`** = **`withdraw_amount` − `amount`**, clamped at zero.
- **`InvoiceTransaction.fee`** does not change **`remaining`**; only **`amount`** does.

### Legacy strict mode

To restore the old “compare only credited **`amount`**” behaviour:

- **`fee_mode`** = **none**
- **`amount_match_basis`** = **amount**
- **`require_withdraw_amount`** = **false** (so payloads without **`withdraw_amount`** are still accepted)

Then **`addPayment`** uses the matched field only and **`fee`** is not set.

### Other settings

- **`amount_match_basis`** = **auto**: use **`withdraw_amount`** when present, otherwise **`amount`**.
- **`require_withdraw_amount`**: when the chosen basis needs **`withdraw_amount`** but it is missing, the notification is rejected (default: on).
- **`max_fee_tolerance`**: in **receiver_fee** mode, reject if **`withdraw_amount` − `amount`** exceeds this value; **0** / empty = no cap (only non-negative fee is required).

Do **not** rely on **amount tolerance** to hide a wrong choice of **`amount`** vs **`withdraw_amount`** or to absorb commission mistakes — fix **basis** and **fee_mode** instead.

**Payer gross-up** (increasing quickpay **`sum`** so net received equals the invoice) is **not** implemented in this gateway version.

## HTTP notifications

- **`test_notification`** is a **boolean** query field from YooMoney (e.g. `true` / `1`), **not** a value of `notification_type`. Real types stay `p2p-incoming` or `card-incoming`. Test pings are acknowledged with **200** and **never** call `addPayment`.
- **Signature `sign`:** HMAC-SHA256 over a canonical string of **all** notification parameters **except `sign`**, keys sorted alphabetically, values URL-encoded (UTF-8, RFC 3986). Output is **hex** (compare case-insensitively). **`sha1_hash` is included in the canonical string when present**; after **2026-05-18** YooMoney may stop sending it — absence must not break verification.
- **Empty `label`:** allowed by YooMoney docs, but **this gateway rejects** non-test notifications without a non-empty label, because linking a transfer to a Paymenter invoice requires the label we stored at `pay()`.

## Quickpay (checkout)

Official integration uses **POST** `https://yoomoney.ru/quickpay/confirm` with **`quickpay-form=button`** and required **`paymentType`**: **`PC`** (wallet) or **`AC`** (card). This module returns a **GET redirect URL** with the same parameters as a **shortcut**; before production, confirm YooMoney accepts GET with this query string or switch to an auto-submit POST form.

- **`default_payment_type`:** used when both wallet and card notification modes are enabled (otherwise `PC` or `AC` is chosen from toggles).

## Go-live

Re-run against the **official YooMoney test vector** and your wallet’s HTTP notification settings. With **receiver_fee** enabled, verify a **real** notification payload ( **`amount`** vs **`withdraw_amount`** ) before relying on it for money-critical cutover (“gate” checks). No secrets in this file or in tickets.
