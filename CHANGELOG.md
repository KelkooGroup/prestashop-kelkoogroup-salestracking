# Changelog

## 1.0.0

First release, for PrestaShop 1.7, 8 and 9.

- Lead tag on every page of the shop; no theme or core file to edit.
- Conversion tag on the order confirmation page, and the same sale sent
  server to server, so it is still tracked when the visitor's browser blocks
  the conversion tag.
- Each sale includes the order id, the amount paid, the basket (product
  reference, name, unit price and quantity) and the optional fields:
  currency, VAT amount, shipping cost, discount amount, voucher codes and
  whether the customer already ordered on the shop (not sent for guest
  checkouts).
- A sale is sent only once, even when the customer reloads the confirmation
  page.
- A cart that PrestaShop splits into several orders (for instance one per
  carrier) is sent as one sale covering all of them.
- Kelkoo, Google and Microsoft click identifiers (`kelkooId`, `gclid`,
  `msclkid`) captured on landing and attached to the sale.
- Settings page: one row per campaign (merchant identifier and optional
  country), validated on save, with guidance on which tag a Consent
  Management Platform must block until consent (the lead tag) and which it
  must never block (the conversion tag).
