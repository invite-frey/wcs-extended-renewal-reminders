# WCS Extended Renewal Reminders

A WordPress plugin that extends WooCommerce Subscriptions manual renewal handling end-to-end: scheduling an early second reminder, auto-creating a pending renewal order with a login-free payment link, placing the subscription on-hold, detecting and notifying on overdue renewals, and correcting the billing schedule after a late payment. Includes admin UI enhancements for visibility into overdue subscriptions.

---

## Requirements

| Dependency | Version |
|---|---|
| WordPress | 6.0+ |
| WooCommerce | 8.0+ |
| WooCommerce Subscriptions | 5.0+ (premium) |
| Action Scheduler | 3.6+ (bundled with WooCommerce) |
| PHP | 8.0+ |

---

## Installation

1. Copy `wcs-extended-renewal-reminders.php` into `wp-content/plugins/wcs-extended-renewal-reminders/`.
2. Activate the plugin from **Plugins → Installed Plugins**.
3. No additional configuration is required. The plugin reads the renewal notification offset from **WooCommerce → Settings → Subscriptions** and derives all timing from it automatically.

> **Note:** This plugin only acts on **manual** subscriptions (those not charged automatically by a payment gateway). Automatic subscriptions are never put on-hold or issued pending renewal orders by this plugin.

---

## How It Works

The plugin follows a single linear flow from the moment WCS fires a renewal reminder through to payment confirmation and schedule correction.

### 1. Early ("reminder") notification

The plugin replaces the WCS default notification scheduler with an extended subclass (`WCS_Action_Scheduler_Customer_Notifications_Extended`). For every renewal notification WCS schedules, the extended scheduler also schedules a second action:

```
woocommerce_scheduled_subscription_customer_notification_renewal_reminder
```

This fires at the **midpoint** between the standard reminder and the actual renewal date. For example, if WCS is configured to send a reminder 6 days before renewal, the early reminder fires 9 days before renewal (halfway between day −12 and day −6).

By default the early reminder sends the same email as the standard renewal notification. Hook `woocommerce_subscription_customer_notification_renewal_reminder` to substitute a custom template (see [Hooks reference](#hooks-reference) below).

### 2. Pending renewal order creation

When the **standard** WCS renewal reminder fires (priority 1, before the email is sent), the plugin creates a `pending` renewal order for the subscription if one does not already exist. The order is flagged with the `_its_autologin_renewal` meta key so downstream login-free payment link handlers can identify it.

Orders created via the admin **Generate Renewal Order** button are flagged the same way.

### 3. Subscription placed on-hold

After the reminder email has been sent (priority 20), the subscription is placed `on-hold` and the current `next_payment` date is snapshotted in `_its_original_next_payment` meta. This preserves the original billing schedule for correction after a late payment.

An overdue check is then scheduled via Action Scheduler for **one day after the original due date**.

### 4. Renewal email URL replacement

WCS renewal reminder emails normally contain an early-renewal URL (`?subscription_renewal_early=ID`). The plugin intercepts the composed email body via `woocommerce_mail_content` and replaces every occurrence of this URL with a clean `/checkout/order-pay/` URL pointing to the pending renewal order. This makes the link compatible with login-free payment handling without any changes to that handler.

### 5. Overdue detection and notification

When the Action Scheduler overdue check fires, the plugin checks whether the renewal order is still `pending` or `failed`. If so — and if no overdue flag has been set yet — it:

- Sets `_its_overdue_since` meta on the subscription (timestamp, used to prevent duplicate notifications and to drive the admin UI).
- Adds an order note to the subscription.
- Sends a **customer email** containing a fresh payment link.
- Sends an **admin email** with links to the subscription and renewal order in WP Admin.

A **daily Action Scheduler sweep** (`its_daily_overdue_check`) also catches any on-hold subscription that became overdue via a path outside the normal reminder flow — for example, a subscription the admin put on-hold manually.

### 6. Billing schedule correction

When payment is eventually received, WCS recalculates `next_payment` from the payment date, which pushes the schedule forward if the payment was late. The plugin corrects this by calculating `original_next_payment + 1 interval` and updating the date if WCS set a different value. This correction fires on both:

- `woocommerce_subscription_renewal_payment_complete` (gateway-confirmed payment).
- `woocommerce_order_status_changed` to `processing` or `completed` (manual admin status change).

The `_its_original_next_payment` and `_its_overdue_since` meta keys are deleted after correction to keep the subscription clean for the next cycle.

---

## Admin UI

### Overdue column

An **Overdue** column is added immediately after the Status column on the **WooCommerce → Subscriptions** list. Overdue subscriptions show a red `OVERDUE` badge with a tooltip displaying the date the flag was set. Non-overdue rows show a muted dash.

### Row highlighting

Rows for overdue subscriptions are highlighted with a light red background (`#fff0f0`) via inline CSS injected into `admin_footer-edit.php`. The highlight deepens on hover (`#ffe0e0`).

### Overdue filter view

An **Overdue** link is added to the status filter tabs at the top of the subscription list, showing the current count. Clicking it filters the list to overdue subscriptions only.

---

## Hooks Reference

### Actions you can hook

| Hook | Parameters | Description |
|---|---|---|
| `woocommerce_subscription_customer_notification_renewal_reminder` | `WC_Subscription $subscription`, `string $next_payment_date` | Fires when the early reminder is due. Hook here to send a custom email. If nothing is hooked, the plugin falls back to the standard WCS renewal notification email. |
| `its_check_overdue_renewal` | `int $subscription_id`, `int $renewal_order_id` | Action Scheduler action. Called one day after the renewal due date. Can be unscheduled if you want to suppress automatic overdue handling. |
| `its_daily_overdue_check` | — | Action Scheduler recurring action. Fires daily to catch any subscriptions missed by the per-subscription scheduled check. |

### Actions used internally (can be unhooked)

| Hook | Type | Priority | Description |
|---|---|---|---|
| `plugins_loaded` | action | 10 | Defines and installs the extended notification scheduler |
| `woocommerce_scheduled_subscription_customer_notification_renewal` | action | 1 | Creates the pending renewal order |
| `woocommerce_scheduled_subscription_customer_notification_renewal` | action | 20 | Places the subscription on-hold and schedules the overdue check |
| `woocommerce_generated_manual_renewal_order` | action | 10 | Flags admin-generated renewal orders |
| `woocommerce_mail_content` | filter | 10 | Rewrites the renewal URL in outgoing emails |
| `woocommerce_subscription_renewal_payment_complete` | action | 10 | Clears the overdue flag |
| `woocommerce_subscription_renewal_payment_complete` | action | 10 | Corrects the billing schedule after payment |
| `woocommerce_order_status_changed` | action | 10 | Corrects the billing schedule on manual order status change |
| `init` | action | default | Registers the daily overdue check with Action Scheduler if not already registered |

---

## Subscription Meta Keys

| Key | Type | Description |
|---|---|---|
| `_its_autologin_renewal` | `string` (`'1'`) | Set on pending renewal orders created by this plugin. Used by login-free payment link handlers to identify plugin-created orders. |
| `_its_original_next_payment` | `string` (MySQL datetime) | Snapshot of `next_payment` taken when the subscription is placed on-hold. Deleted after payment and schedule correction. |
| `_its_overdue_since` | `string` (MySQL datetime) | Set when the overdue notification fires. Prevents duplicate notifications. Drives the admin Overdue column and filter. Deleted after payment and schedule correction. |

---

## Frequently Asked Questions

**Does this affect automatic (gateway-charged) subscriptions?**  
No. Every entry point is gated by `$subscription->is_manual()`. Automatic subscriptions are never put on-hold, never issued a pending renewal order, and never flagged as overdue by this plugin.

**What if a subscription already has a pending renewal order when the reminder fires?**  
The plugin detects it via `its_get_pending_renewal_order()` and skips order creation. The existing order is used for the payment URL and overdue check instead.

**Can I use a custom email template for the early reminder?**  
Yes. Hook `woocommerce_subscription_customer_notification_renewal_reminder` with your own handler. As long as something is hooked to that action, the plugin's fallback (which re-fires the standard WCS renewal email) will not run.

**What happens if order creation fails?**  
The error is logged to the WooCommerce logger under the `wcs-extended-reminders` source. The subscription is not placed on-hold and no overdue check is scheduled for that cycle. The daily sweep will pick it up the following day if the subscription is on-hold by other means.

**Will overdue emails be sent more than once?**  
No. The `_its_overdue_since` meta key is checked before any notification is sent. Once set, neither the per-subscription scheduled check nor the daily sweep will send another notification.

**What if the admin clears `_its_overdue_since` manually?**  
The daily sweep will treat the subscription as not-yet-notified and send a fresh overdue notification on its next run. This is intentional — clearing the flag is the supported way to re-send an overdue notice.

---

## Changelog

### 1.0
- Initial release, extracted and consolidated from the theme extension (`auto-create-renewal-order.php`) and the notification functionality previously bundled in **WCS Price Sync**.
- All Action Scheduler actions re-grouped under `wcs-extended-reminders`.
- Debug `error_log()` calls removed from production code.
- Text domain updated to `wcs-extended-reminders` throughout.

---

## License

GPL v2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)

**Author:** Frey Mansikkaniemi · [frey.hk](https://frey.hk)
