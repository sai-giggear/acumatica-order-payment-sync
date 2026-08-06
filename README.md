# Acumatica Order & Payment Sync

WooCommerce plugin. Posts sales orders and AR payments to Acumatica ERP over
OAuth2 once an order reaches `processing`.

## Setup

Everything lives on one screen, **Acumatica Sync → Settings**.

- **Connection.** OAuth endpoint, credentials, and the endpoint path Acumatica
  publishes the API under. "Test connection" checks them against the stored
  values, so save first.
- **Authorised host.** The hostname this install is allowed to sync as. Syncing
  runs only while it matches the site's real host. A staging copy or a restored
  backup carries the same options and credentials, so this comparison is the
  only thing that keeps one from posting test orders into Acumatica as real
  ones. Nothing syncs until it is set.
- **Order type, customer ID, website field.** Sent on every order.
- **Payment methods.** One block per WooCommerce payment method slug, mapping it
  to an Acumatica payment method, entry type, processor-fee meta key, and the
  fee and cash accounts. A blank field inherits from the defaults below, so
  shared account numbers are typed once, and each placeholder shows what that
  field will actually send. The fee meta key is the exception and is never
  inherited: a method with no processor fee would otherwise wait out the full
  retry schedule for a fee that never arrives.

None of this is in the source. The host map, customer IDs and GL accounts used
to be hardcoded, which put one company's chart of accounts in the plugin.

## Updates

The plugin checks this repo's releases and offers the update in
**Dashboard → Updates** like any other plugin. No configuration needed.

First install on a site is manual: upload the release zip through
**Plugins → Add New → Upload**. After that it updates itself. To apply updates
unattended rather than being notified, switch the plugin to "Enable auto-updates"
on the Plugins screen.