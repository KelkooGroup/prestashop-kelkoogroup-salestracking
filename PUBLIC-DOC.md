# Prestashop

Install Kelkoo Sales Tracking on PrestaShop 1.7, 8 or 9 with the official
**Kelkoo Group Sales Tracking** module: no theme or core file to edit, and
it survives theme changes and PrestaShop upgrades.

If you can't install modules on your shop, follow the
[manual installation](#manual-installation-prestashop-8-and-newer) instead.

## Before you start

### Get your merchantId

See [How To get your merchantId](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/how-to-get-your-merchantid).

### Remove previous installations

If your shop already sends sales to Kelkoo (a previous module, or tags
pasted in `head.tpl`, `OrderConfirmationController.php` or
`order-confirmation-table.tpl`), remove them first: otherwise every sale is
sent twice. See [Remove Previous Installations of Kelkoo Sales Tracking](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/remove-previous-installations-of-kelkoo-sales-tracking)
for what to look for.

## Install the module

1. Download the module: DOWNLOAD_URL
2. In your PrestaShop back office, go to **Modules > Module Manager** and
   click **Upload a module**.
3. Drop the downloaded zip file. PrestaShop installs the module; it then
   appears in **Modules > Advertising & Marketing** as
   **Kelkoo Group Sales Tracking**.

## Configure the module

Click **Configure** on the module:

- **Merchant Identifier**: your merchantId (digits only).
- **Country**: optional, the 2-letter code of the country your products are
  listed on Kelkoo for (fr, de, es, it, nl, uk...).

Click **Save**. Sales are tracked from the next order on.

### Running several campaigns, in several countries, or on several websites?

One row is enough even if you run several campaigns with Kelkoo: use any of
your Merchant IDs, Kelkoo matches each sale to the right campaign regardless
of which one you send.

If you operate more than one website, install the module separately on each
one, using a Merchant ID that belongs to that website. acme.com and acme.fr
are the same website for Kelkoo; acme.com and safe.com are two different
websites.

## What the module sends

- **Lead tag**, on every page of your shop: remembers that the visitor came
  from Kelkoo.
- **Conversion tag**, on the order confirmation page, and the same sale sent
  **server to server** from your shop, so it is still tracked when the
  visitor's browser blocks the conversion tag.

Each sale includes the order id, the amount paid (tax and shipping
included), the basket (product reference, name, unit price and quantity)
and the optional fields described in [Why these optional fields matter](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/how-kelkoo-sales-tracking-automatically-reads-your-google-analytics-data-layer#Why-these-optional-fields-matter):
currency, VAT amount, shipping cost, discount amount, voucher codes and
whether the customer already ordered on your shop (not sent for guest
checkouts). There is nothing to fill in.

A sale is sent once, even if the customer reloads the confirmation page.
When PrestaShop splits a cart into several orders (for instance one per
carrier), the sale covers all of them.

## Configure your Consent Management Platform (CMP)

Kelkoo Sales Tracking relies on two tags with different consent
requirements:

- the **Lead Tag** (`https://s.kk-resources.com/leadtag.js`) must be gated
  behind user consent: declare it as an advertising script in your CMP;
- the **Conversion Tag** (`https://s.kk-resources.com/ks.js`) should not be
  blocked by a missing consent signal, or your sales will be
  under-reported. Block the lead tag by its full URL, never the whole
  `s.kk-resources.com` domain.

See [Configuring Consent Management (CMP)](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/configuring-consent-management-cmp) for full setup and
verification instructions.

## Test your integration

Place a test order, then check [Test your Kelkoo Sales Tracking setup](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/testing-troubleshooting/test-your-kelkoo-sales-tracking-setup).

## Uninstall

In **Modules > Module Manager**, uninstall **Kelkoo Group Sales Tracking**.
It removes its settings and stops sending sales.

---

## Manual installation (PrestaShop 8 and newer)

Only if you can't install the module above. You need to edit part of your
code; these changes are lost when you change theme, and may be overwritten
by a PrestaShop upgrade.

### Backup your files

Before doing any changes, back up the files you're going to modify.

### Check the Recompile option

Go to **Advanced Parameters > Performance** and make sure that
**Recompile templates if the files have been updated** is selected for
**Template compilation**.

### Install the lead tag

1. Open `themes/YOUR_THEME/templates/_partials/head.tpl` (YOUR_THEME being
   the name of your theme, the default one being `classic`).
2. Add:

   ```html
   <script async="true" type="text/javascript" src="https://s.kk-resources.com/leadtag.js"></script>
   ```

3. Clear your cache in **Advanced Parameters > Performance > Clear cache**.

### Install the conversion tag

1. Open `controllers/front/OrderConfirmationController.php`.
2. Replace the following code block:

   ```php
   $this->context->smarty->assign([
       'HOOK_ORDER_CONFIRMATION' => $this->displayOrderConfirmation($this->order),
       'HOOK_PAYMENT_RETURN' => $this->displayPaymentReturn($this->order),
       'order' => (new OrderPresenter())->present($this->order),
       'order_customer' => $this->objectPresenter->present($this->customer),
       'registered_customer_exists' => Customer::customerExists($this->customer->email, false, true),
   ]);
   ```

   With:

   ```php
   /* Kelkoo Sales Tracking */
   // Every order of the cart: PrestaShop splits a cart into several orders
   // (e.g. one per carrier), and this page only shows the first one.
   $kelkooOrders = array_merge([$this->order], $this->order->getBrother()->getResults());
   $productsKelkoo = [];
   foreach ($kelkooOrders as $kelkooOrder) {
       foreach ($kelkooOrder->getProducts() as $product) {
           $productsKelkoo[] = [
               'productname' => $product['product_name'],
               'productid' => $product['product_reference'],
               'quantity' => (int) $product['product_quantity'],
               'price' => (float) $product['unit_price_tax_incl'],
           ];
       }
   }
   $this->context->smarty->assign([
       'HOOK_ORDER_CONFIRMATION' => $this->displayOrderConfirmation($this->order),
       'HOOK_PAYMENT_RETURN' => $this->displayPaymentReturn($this->order),
       // JSON_HEX_*: product names can't break out of the <script> tag.
       'products_json' => json_encode($productsKelkoo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
       'sales' => $this->order->getOrdersTotalPaid(),
       'orderid' => $this->order->id,
       'order' => (new OrderPresenter())->present($this->order),
       'order_customer' => $this->objectPresenter->present($this->customer),
       'registered_customer_exists' => Customer::customerExists($this->customer->email, false, true),
   ]);
   /* end Kelkoo Sales Tracking */
   ```

3. Open
   `themes/YOUR_THEME/templates/checkout/_partials/order-confirmation-table.tpl`
   and add at the bottom of the file (replace `MERCHANTID_VALUE` with your
   merchantId):

   ```html
   <script type="text/javascript">
     window._kkstrack = {
       merchantInfo: [{ merchantId: "MERCHANTID_VALUE" }],
       orderValue: '{$sales}',
       orderId: '{$orderid}',
       basket: {$products_json nofilter},
       // All the fields below are OPTIONAL. We strongly recommend filling in as many as
       // you can (see "Optional fields" below) - simply remove any line you cannot fill.
       // coupon: 'COUPON_CODE',
       // returningUser: 'RETURNING_USER',
       // currency: 'CURRENCY',
       // vatAmount: 'VAT_AMOUNT',
       // shippingCost: 'SHIPPING_COST',
       // affiliation: 'AFFILIATION',
       // discountAmount: 'DISCOUNT_AMOUNT',
       // paymentStatus: 'PAYMENT_STATUS',
       // customerLifetimeValue: 'CUSTOMER_LIFETIME_VALUE'
     };
     (function() {
       var s = document.createElement('script');
       s.type = 'text/javascript';
       s.async = true;
       s.src = 'https://s.kk-resources.com/ks.js';
       var x = document.getElementsByTagName('script')[0];
       x.parentNode.insertBefore(s, x);
     })();
   </script>
   ```

   We recommend assigning `_kkstrack` on `window` explicitly
   (`window._kkstrack = {...}`, as above) rather than as a bare global, to
   avoid conflicts if this script runs inside another scope. See
   [Add Basket Information to Kelkoo Sales Tracking](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/add-basket-information-to-kelkoo-sales-tracking) for the full basket
   format and canonical field names.

4. If you have a
   `themes/YOUR_THEME/templates/checkout/_partials/order-confirmation-table-simple.tpl`
   file (express checkout), add the same code to it.

### Optional fields

None of the fields commented out in the code sample above are required for
Kelkoo Sales Tracking to work: a sale is still tracked without them. We
strongly encourage you to fill in as many as you can, since they directly
improve how we optimise your campaigns. For why each field matters, see
[Why these optional fields matter](https://docs.kelkoogroup.com/for-advertisers/kelkoo-sales-tracking/reference-configuration/how-kelkoo-sales-tracking-automatically-reads-your-google-analytics-data-layer#Why-these-optional-fields-matter). The module fills most of them for you.

### Consent, several campaigns or websites, testing

Same as with the module: see
[Configure your Consent Management Platform (CMP)](#configure-your-consent-management-platform-cmp),
[Running several campaigns...](#running-several-campaigns-in-several-countries-or-on-several-websites)
and [Test your integration](#test-your-integration) above.
