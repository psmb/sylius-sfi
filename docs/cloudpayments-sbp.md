# CloudPayments SBP operations

## Application configuration

Create a separate enabled Sylius payment method backed by a CloudPayments gateway:

- Payment type: `SBP`
- Publishable key and secret key: the credentials for the CloudPayments site
- SBP link lifetime: `30` minutes unless the business requires a longer checkout window
- Test mode: enabled only while using the CloudPayments test environment

The existing card gateway must remain configured with payment type `Bank card`. Both gateways may use the same CloudPayments site credentials.

`CLOUDPAYMENTS_API_URL` is a local-test override. It must be unset in production so requests go to `https://api.cloudpayments.ru`.

## Rollout order

CloudPayments notification settings apply to the whole CloudPayments site, including existing card payments. Use this order so enabling Check cannot interrupt card checkout:

1. Deploy the application code and leave the new SBP payment method disabled.
2. Confirm that all three notification routes are publicly reachable over HTTPS.
3. Configure Check, Pay, and Fail notifications in the CloudPayments test site.
4. Complete the card and SBP test scenarios below.
5. Enable the SBP payment method for the intended Sylius channel.
6. Run the low-value production canary before making SBP broadly available.

## CloudPayments notifications

Configure these HTTPS endpoints in the CloudPayments merchant cabinet as POST notifications using UTF-8 encoding and the `CloudPayments` format:

- Check: `https://books.sfi.ru/payment/cloudpayments/notify/check`
- Pay: `https://books.sfi.ru/payment/cloudpayments/notify/pay`
- Fail: `https://books.sfi.ru/payment/cloudpayments/notify/fail`

Check must be enabled before launch. It prevents incorrect amounts and duplicate payment of an already completed Sylius payment. The endpoints validate both CloudPayments HMAC formats and choose the secret belonging to the payment's gateway.

CloudPayments must be able to reach these routes without authentication, redirects, maintenance-page interception, or CSRF protection. The application still authenticates every notification using HMAC.

## Release QA

Run the local regression suite:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist
```

In the CloudPayments test environment verify:

1. SBP link creation succeeds while Check notifications are enabled.
2. The invoice, amount, currency, account, receipt data, link TTL, and test-mode flag are correct in the CloudPayments transaction.
3. Completing payment in a bank returns to the shop and marks both the Sylius payment and order paid.
4. Returning to the shop before Pay arrives shows a pending state and never redirects back to the bank.
5. A failed attempt followed by a successful attempt completes the same payment.
6. Repeated Pay and Fail deliveries do not duplicate state transitions.
7. Existing card payment succeeds with and without 3-D Secure while Check is enabled.
8. Invalid HMAC, amount, currency, account, and invoice values are rejected.

Before broad rollout, perform one low-value production SBP payment and reconcile its CloudPayments transaction ID, Sylius payment state, order payment state, fiscal receipt, and customer return path.

## Monitoring and incidents

CloudPayments integration events are written to the normal Symfony application log. Alert on:

- `CloudPayments SBP link request failed`
- `CloudPayments sent a Pay notification with an unexpected status`
- `CloudPayments reported another successful transaction for a completed payment`
- uncaught errors on `/payment/cloudpayments/notify/*`

If rollout must be stopped, disable the SBP payment method in Sylius. Keep notification endpoints enabled until all in-flight links have expired and pending transactions have been reconciled.

CloudPayments SBP refunds are asynchronous. This integration does not initiate or process refunds. Until refund support is implemented, issue refunds in the CloudPayments merchant cabinet and update the Sylius order through the existing manual operations process.
