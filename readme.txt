=== Top5Way Fake Order Guard ===
Contributors: top5way
Requires Plugins: woocommerce
Stable tag: 1.2.0

Stops fake orders on WooCommerce before they are placed. A blocked customer goes to your WhatsApp with the order already typed.

== What it does ==
* Block by phone, device or IP from a button on the order screen, or add a number by hand.
* Blocked checkouts create no order. Each one is saved with a code like BLK-1001.
* Shows the Steadfast record, device and earlier orders on the order screen.
* Your own message above the Open WhatsApp button. With no WhatsApp number set, the customer sees a plain message instead.
* Create order: when a blocked customer proves real on WhatsApp, one button makes the order from their attempt.
* Repeat orders: the same phone, device or IP ordering again and again goes to WhatsApp instead. The limits are in Settings.
* Allow list, so your office connection is never blocked.
* Auto verify by Steadfast record, unless Top5Way Tracking is active: then Tracking does it.

== Setup ==
1. Install and activate. WooCommerce must be active.
2. WooCommerce > Fake Order Guard > Settings: add your WhatsApp number.
3. Add your office IP under Never block these IPs.
4. Steadfast keys are optional.
5. Using the older T5 Fake Order Blocker? Deactivate it first. The block list and history carry over.

== Notes ==
Deactivating keeps all data. Deleting the plugin removes its tables and settings.
