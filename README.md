<h1 align="center">Ezone Pay for OpenCart</h1>

<p align="center">
  Accept Ezone Pay payment links directly from OpenCart checkout.
</p>

## Description

Ezone Pay for OpenCart lets customers pay for store orders using single-use
Ezone Pay payment links. The extension creates a unique OpenCart order
reference, displays the returned checkout link as a QR code, polls Ezone Pay
for confirmation, and updates the OpenCart order only after the payment has
been confirmed.

## Features

- Development and production API modes.
- Separate API keys for development and production.
- Optional API key configuration through server environment variables.
- Single-use Ezone Pay payment links with unique OpenCart order references.
- QR code checkout from the OpenCart payment confirmation step.
- Payment confirmation using Ezone Pay payment-link details and transactions.
- Configurable pending and paid OpenCart order statuses.
- Optional geo zone restriction, payment status, sort order, and API error
  debug logging.
- Local payment tracking table to prevent duplicate order completion.

## Compatibility

- OpenCart 4.x
- PHP with cURL enabled
- MySQL or MariaDB

## Installation

1. Download or build the extension package as `ezonepay.ocmod.zip`.
2. Log in to the OpenCart admin panel.
3. Go to `Extensions > Installer`.
4. Upload `ezonepay.ocmod.zip`.
5. Go to `Extensions > Extensions`.
6. Choose `Payments` from the extension type dropdown.
7. Find `Ezone Pay` and click `Install`.
8. Click `Edit` to configure the payment method.

## Configuration

The extension supports two API modes:

- Development: `https://test.ezonepay.ly`
- Production: `https://api.ezonepay.ly`

To configure Ezone Pay in OpenCart:

1. Go to `Extensions > Extensions`.
2. Choose `Payments` from the extension type dropdown.
3. Find `Ezone Pay` and click `Edit`.
4. Choose the Ezone Pay environment.
5. Add the development and/or production API key.
6. Select the pending order status used after a payment link is created.
7. Select the paid order status used after Ezone Pay confirms payment.
8. Optionally choose a geo zone, debug logging setting, and sort order.
9. Set `Status` to `Enabled`.
10. Save the payment method.

The pending and paid order statuses must be different.

To get a development API key, register or log in at `https://demo.ezonepay.ly/`,
then go to `الإعدادات > مفاتيح API`.

To get a production API key, register or log in at `https://my.ezonepay.ly/`,
then go to `الإعدادات > مفاتيح API`.

When creating an API key, enable these permissions:

- `إنشاء رابط دفع`
- `عرض روابط الدفع`

You can also provide API keys through server environment variables:

```bash
EZONEPAY_DEV_API_KEY=your-dev-secret-key
EZONEPAY_PRODUCTION_API_KEY=your-production-secret-key
```

Environment variables take priority over API keys stored in the OpenCart admin
payment settings.

## Checkout Payment Flow

1. The customer selects Ezone Pay as the payment method during checkout.
2. OpenCart creates a single-use Ezone Pay payment link for the order total.
3. OpenCart marks the order with the configured pending order status.
4. OpenCart displays the returned checkout link as a QR code and as an external
   checkout button.
5. The customer scans the QR code or opens the Ezone Pay checkout link and
   completes the payment.
6. OpenCart polls Ezone Pay until the payment is confirmed.
7. OpenCart updates the order to the configured paid order status and redirects
   the customer to checkout success.

## Payment Confirmation

After creating a payment link, OpenCart stores a local pending payment record in
the `ezonepay_payment` table. The extension checks Ezone Pay payment-link
details and payment-link transactions to confirm that the payment was completed
for the expected amount and order reference.

The extension uses a single-use payment link, an order-specific reference, an
order lock, and local payment states to reduce duplicate completion, replayed
references, and mismatched payment confirmations.

## External Services

This extension connects to the Ezone Pay API to create payment links, display
QR checkout links, and confirm whether a payment has been completed. This
service is provided by Ezone.

The extension connects to one of these Ezone Pay API endpoints depending on the
selected environment mode:

- Development: `https://test.ezonepay.ly`
- Production: `https://api.ezonepay.ly`

When a customer starts an Ezone Pay payment, the extension sends the configured
API key, payment amount, generated OpenCart order reference, internal reference,
payment title, note, maximum usage count, and customer first name, last name,
and phone number to Ezone Pay. This data is sent so Ezone Pay can create a
payment link and return the checkout URL.

After the customer pays, the extension may send the configured API key, payment
link ID, amount, and OpenCart order reference to Ezone Pay to check payment-link
details and payment-link transactions. This data is sent so OpenCart can
confirm whether the payment was completed.

Payment details entered by the customer in the Ezone Pay checkout are submitted
directly to Ezone Pay and are not processed by this OpenCart extension.

Terms of service: https://ezonepay.ly/ar/terms

Privacy policy: https://ezonepay.ly/ar/privacy

## Publishing

OpenCart extension packages are uploaded as `.ocmod.zip` files. The package
should contain the `upload` directory at the archive root:

```text
ezonepay.ocmod.zip
└── upload/
    └── extension/
        └── ezonepay/
            ├── admin/
            ├── catalog/
            └── install.json
```

From this repository, you can build the upload package with:

```bash
zip -r ezonepay.ocmod.zip upload
```

Before publishing publicly:

1. Install the generated package on a clean OpenCart 4.x store.
2. Confirm the extension appears under `Extensions > Extensions > Payments`.
3. Configure development API credentials and complete a test checkout.
4. Confirm the order moves from the configured pending status to the configured
   paid status after payment.
5. Disable debug logging before submitting a production-ready package.
6. Confirm the extension name, description, license, screenshots, support URL,
   and trademark usage are approved for public distribution.

## Frequently Asked Questions

### Does this extension process card or wallet credentials?

No. The extension creates Ezone Pay payment links and displays the returned
checkout link as a QR code in OpenCart checkout.

### Why does the order stay pending?

OpenCart has not yet confirmed the payment with Ezone Pay. Make sure the
selected API key can create and view payment links, that the customer completed
the payment for the expected amount, and that the configured API environment
matches the API key.

### Can API keys be stored outside OpenCart?

Yes. Set `EZONEPAY_DEV_API_KEY` and/or `EZONEPAY_PRODUCTION_API_KEY` in the web
server or PHP-FPM environment. These values override the keys configured in the
OpenCart payment settings.

### What customer data is required?

Ezone Pay requires customer name and phone details when creating the payment
link. The extension uses customer, payment address, shipping address, and order
data when available. If no customer details are available, it sends a default
OpenCart customer payload.

## Trademark Notice

This extension uses the Ezone Pay name to identify the payment service it
integrates with. Public distribution should only proceed after you confirm that
you have permission from the Ezone Pay trademark owner to use the name, brand,
and any related marks.

## Changelog

### 1.0.0

- Initial OpenCart 4 release.

## License

LGPL-3
