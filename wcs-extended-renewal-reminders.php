<?php
/*
Plugin Name: WCS Extended Renewal Reminders
Plugin URI: https://invite.hk
Description: Extends WooCommerce Subscriptions manual renewal handling: sends a second "early" renewal reminder at half the standard notice period; auto-creates a pending renewal order when the reminder fires; places the subscription on-hold; flags and notifies on overdue renewals; and corrects the billing schedule after a late payment. Includes an Overdue column and filter in the admin subscription list.
Version: 1.0
Author: Frey Mansikkaniemi
Author URI: https://frey.hk
License: GPL v2 or later
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/* =========================================================================
 * ADMIN NOTICES
 * ====================================================================== */

function its_wcs_reminders_admin_notice_woocommerce_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Extended Renewal Reminders</strong> requires
            <a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>
            to be installed and activated.
        </p>
    </div>
    <?php
}

function its_wcs_reminders_admin_notice_subscriptions_missing() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p>
            <strong>WCS Extended Renewal Reminders</strong> requires
            <a href="https://woocommerce.com/products/woocommerce-subscriptions/" target="_blank">WooCommerce Subscriptions</a>
            (premium) to be installed and activated.
        </p>
    </div>
    <?php
}


/* =========================================================================
 * PLUGIN INIT
 * ====================================================================== */

add_action( 'plugins_loaded', 'its_wcs_reminders_init', 5 );

function its_wcs_reminders_init() {

    if ( ! function_exists( 'WC' ) || ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'its_wcs_reminders_admin_notice_woocommerce_missing' );
        return;
    }

    if ( ! class_exists( 'WC_Subscriptions' ) ) {
        add_action( 'admin_notices', 'its_wcs_reminders_admin_notice_subscriptions_missing' );
        return;
    }

    // -----------------------------------------------------------------------
    // DUAL-NOTIFICATION SCHEDULER
    // Define and install the extended notification class after all plugins load
    // so WCS_Action_Scheduler_Customer_Notifications is guaranteed to exist.
    // -----------------------------------------------------------------------
    add_action( 'plugins_loaded', 'its_define_extended_notification_class', 10 );

    // Fire the reminder renewal email when Action Scheduler triggers it.
    add_action( 'woocommerce_scheduled_subscription_customer_notification_renewal_reminder', 'its_send_reminder_renewal_email', 10, 1 );

    // -----------------------------------------------------------------------
    // PENDING ORDER CREATION & ON-HOLD LOGIC
    // -----------------------------------------------------------------------

    // 1. Create a pending renewal order when the standard WCS reminder fires.
    add_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', 'its_create_order_on_renewal_reminder', 1, 1 );

    // 2. Put the subscription on-hold after the email has gone out (priority 20;
    //    WCS sends the email at priority 10).
    add_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', 'its_set_subscription_on_hold_after_reminder', 20, 1 );

    // 3. Flag orders created via the admin "Generate Renewal Order" button.
    add_action( 'woocommerce_generated_manual_renewal_order', 'its_flag_admin_created_renewal_order', 10, 1 );

    // -----------------------------------------------------------------------
    // EMAIL URL REPLACEMENT
    // -----------------------------------------------------------------------
    add_filter( 'woocommerce_mail_content', 'its_replace_renewal_url_in_email', 10, 1 );

    // -----------------------------------------------------------------------
    // OVERDUE HANDLING
    // -----------------------------------------------------------------------
    add_action( 'its_check_overdue_renewal', 'its_handle_overdue_renewal', 10, 2 );
    add_action( 'woocommerce_subscription_renewal_payment_complete', 'its_clear_overdue_flag', 10, 1 );

    // Daily sweep for any on-hold subscription that slipped through.
    add_action( 'init', 'its_schedule_daily_overdue_check' );
    add_action( 'its_daily_overdue_check', 'its_run_daily_overdue_check' );

    // -----------------------------------------------------------------------
    // BILLING SCHEDULE CORRECTION
    // -----------------------------------------------------------------------
    add_action( 'woocommerce_subscription_renewal_payment_complete', 'its_restore_original_billing_schedule', 10, 2 );
    add_action( 'woocommerce_order_status_changed', 'its_restore_schedule_on_manual_status_change', 10, 3 );

    // -----------------------------------------------------------------------
    // ADMIN UI — OVERDUE COLUMN & FILTER
    // -----------------------------------------------------------------------
    add_filter( 'manage_edit-shop_subscription_columns',           'its_add_overdue_column',              20    );
    add_action( 'manage_shop_subscription_posts_custom_column',    'its_render_overdue_column',           20, 2 );
    add_action( 'admin_footer-edit.php',                           'its_overdue_row_highlight_script'           );
    add_filter( 'views_edit-shop_subscription',                    'its_add_overdue_filter_view'                );
    add_action( 'pre_get_posts',                                   'its_filter_overdue_subscriptions_query'     );
}


/* =========================================================================
 * PART 1 — DUAL-NOTIFICATION SCHEDULER
 *
 * Extends WCS_Action_Scheduler_Customer_Notifications to schedule a second
 * "early" renewal reminder at the midpoint between the standard notification
 * time and the actual renewal date.
 * ====================================================================== */

function its_define_extended_notification_class() {

    if ( ! class_exists( 'WCS_Action_Scheduler_Customer_Notifications' ) ) {
        error_log( 'WCS Extended Renewal Reminders: WCS_Action_Scheduler_Customer_Notifications not found — dual-notification scheduler not loaded.' );
        return;
    }

    /**
     * Extended notification scheduler.
     *
     * Adds one extra Action Scheduler action per subscription:
     *   woocommerce_scheduled_subscription_customer_notification_renewal_reminder
     *
     * This fires halfway between the standard renewal notification and the
     * actual renewal date.
     */
    class WCS_Action_Scheduler_Customer_Notifications_Extended extends WCS_Action_Scheduler_Customer_Notifications {

        /** Action tag used for the second (early) reminder. */
        protected static $reminder_action = 'woocommerce_scheduled_subscription_customer_notification_renewal_reminder';

        /**
         * Override to also schedule the early reminder for renewal notifications.
         *
         * @param WC_Subscription $subscription
         * @param string          $notification_type
         */
        protected function schedule_notification( $subscription, $notification_type ) {
            parent::schedule_notification( $subscription, $notification_type );

            if ( 'next_payment' === $notification_type ) {
                $this->schedule_reminder_renewal_notification( $subscription );
            }
        }

        /**
         * Schedule the early reminder notification.
         *
         * @param WC_Subscription $subscription
         */
        protected function schedule_reminder_renewal_notification( $subscription ) {
            $subscription_id = $subscription->get_id();

            if ( ! WC_Subscriptions_Email_Notifications::notifications_globally_enabled() ) {
                return;
            }

            if ( ! $subscription->has_status( array( 'active', 'pending-cancel' ) ) ) {
                return;
            }

            if ( self::is_subscription_period_too_short( $subscription ) ) {
                return;
            }

            $event_date = $subscription->get_date( 'next_payment' );
            if ( ! $event_date ) {
                return;
            }

            // Standard notification timestamp.
            $standard_timestamp  = $this->subtract_time_offset( $event_date, $subscription, 'next_payment' );

            // Early reminder sits halfway between the standard notification and the renewal.
            $next_payment_time   = $subscription->get_time( 'next_payment' );
            $time_before_renewal = $next_payment_time - $standard_timestamp;
            $reminder_timestamp  = (int) ( $standard_timestamp + ( $time_before_renewal / 2 ) );

            if ( $reminder_timestamp <= time() ) {
                return; // Already passed — skip.
            }

            $action      = self::$reminder_action;
            $action_args = self::get_action_args( $subscription );

            $next_scheduled = as_next_scheduled_action( $action, $action_args, self::$notifications_as_group );

            if ( $reminder_timestamp === $next_scheduled ) {
                return; // Already scheduled at the right time.
            }

            $this->unschedule_actions( $action, $action_args );
            as_schedule_single_action( $reminder_timestamp, $action, $action_args, self::$notifications_as_group );
        }

        /**
         * Override to also clean up the early reminder on unschedule.
         */
        public function unschedule_all_notifications( $subscription = null, $exceptions = array() ) {
            parent::unschedule_all_notifications( $subscription, $exceptions );

            if ( in_array( self::$reminder_action, $exceptions, true ) ) {
                return;
            }

            if ( $subscription ) {
                $this->unschedule_actions( self::$reminder_action, self::get_action_args( $subscription ) );
            }
        }

        /**
         * Override to preserve the early reminder when a subscription goes on-hold,
         * and to remove it on cancellation / expiry.
         */
        public function update_status( $subscription, $new_status, $old_status ) {
            $action      = self::$reminder_action;
            $action_args = self::get_action_args( $subscription );

            // Snapshot the reminder timestamp before the parent wipes it.
            // We only preserve it for on-hold; all other terminal statuses
            // should clear it as normal.
            $saved_timestamp = null;
            if ( 'on-hold' === $new_status ) {
                $saved_timestamp = as_next_scheduled_action( $action, $action_args, self::$notifications_as_group );
            }

            parent::update_status( $subscription, $new_status, $old_status );

            // Restore for on-hold if still in the future.
            if ( $saved_timestamp && $saved_timestamp > time() ) {
                as_schedule_single_action( $saved_timestamp, $action, $action_args, self::$notifications_as_group );
            }

            // For pending-cancel, explicitly remove the early reminder
            // (the parent handles the other terminal statuses).
            if ( 'pending-cancel' === $new_status ) {
                $this->unschedule_actions( $action, $action_args );
            }
        }
    } // end class WCS_Action_Scheduler_Customer_Notifications_Extended

    // -----------------------------------------------------------------------
    // Install the extended scheduler in place of the WCS default one.
    // -----------------------------------------------------------------------

    $wcs_core = WC_Subscriptions_Core_Plugin::instance();
    if ( ! $wcs_core ) {
        error_log( 'WCS Extended Renewal Reminders: Unable to access WC_Subscriptions_Core_Plugin — dual-notification scheduler not installed.' );
        return;
    }

    $default_scheduler = $wcs_core->notifications_scheduler;
    if ( $default_scheduler ) {
        remove_action( 'woocommerce_before_subscription_object_save', array( $default_scheduler, 'update_notifications' ), 10 );
    }

    $extended_scheduler              = new WCS_Action_Scheduler_Customer_Notifications_Extended();
    $wcs_core->notification_scheduler = $extended_scheduler;

    add_action( 'woocommerce_before_subscription_object_save', array( $extended_scheduler, 'update_notifications' ), 10, 2 );
}


/* -------------------------------------------------------------------------
 * Send the early reminder email.
 *
 * Fires a dedicated action that can be hooked for a custom template.
 * Falls back to the standard renewal notification email if nothing is hooked.
 * ---------------------------------------------------------------------- */

function its_send_reminder_renewal_email( $subscription_id ) {
    $subscription = wcs_get_subscription( $subscription_id );
    if ( ! $subscription ) {
        return;
    }

    $next_payment_date = $subscription->get_date( 'next_payment' );
    if ( ! $next_payment_date ) {
        return;
    }

    /**
     * Fires when the early renewal reminder is due.
     *
     * Hook here to send a custom email template.
     *
     * @param WC_Subscription $subscription
     * @param string          $next_payment_date  MySQL datetime string (UTC).
     */
    do_action( 'woocommerce_subscription_customer_notification_renewal_reminder', $subscription, $next_payment_date );

    // Fallback: use the standard renewal notification email if nothing hooked above.
    if ( ! has_action( 'woocommerce_subscription_customer_notification_renewal_reminder' ) ) {
        do_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', $subscription_id );
    }
}


/* =========================================================================
 * PART 2 — PENDING ORDER CREATION & ON-HOLD LOGIC
 *
 * Flow:
 *   1. WCS fires woocommerce_scheduled_subscription_customer_notification_renewal
 *      (via Action Scheduler) when a manual renewal reminder is due.
 *   2. We create a pending renewal order if one doesn't already exist.
 *   3. After the email fires we put the subscription on-hold and schedule an
 *      overdue check for one day after the original due date.
 *   4. The renewal URL in the outgoing email is rewritten to a clean
 *      /checkout/order-pay/ URL that works with login-free payment links.
 *
 * Automatic renewals are never touched — is_manual() gates every entry point.
 * ====================================================================== */

/* -------------------------------------------------------------------------
 * 2a. Create pending renewal order when the reminder fires
 * ---------------------------------------------------------------------- */

function its_create_order_on_renewal_reminder( $subscription_id ) {
    $subscription = wcs_get_subscription( $subscription_id );

    if ( ! $subscription || ! $subscription->is_manual() ) {
        return;
    }

    if ( its_get_pending_renewal_order( $subscription ) ) {
        return; // One already exists.
    }

    $renewal_order = wcs_create_renewal_order( $subscription );

    if ( is_wp_error( $renewal_order ) ) {
        wc_get_logger()->error(
            sprintf(
                'WCS Extended Reminders: Failed to create renewal order for subscription #%d: %s',
                $subscription_id,
                $renewal_order->get_error_message()
            ),
            array( 'source' => 'wcs-extended-reminders' )
        );
        return;
    }

    $renewal_order->update_status( 'pending', __( 'Auto-created for login-free payment link.', 'wcs-extended-reminders' ) );
    $renewal_order->update_meta_data( '_its_autologin_renewal', '1' );
    $renewal_order->save();
}

/* -------------------------------------------------------------------------
 * 2b. Put subscription on-hold after the email has fired (priority 20)
 * ---------------------------------------------------------------------- */

function its_set_subscription_on_hold_after_reminder( $subscription_id ) {
    $subscription = wcs_get_subscription( $subscription_id );

    if ( ! $subscription || ! $subscription->is_manual() ) {
        return;
    }

    $pending_order = its_get_pending_renewal_order( $subscription );
    if ( ! $pending_order ) {
        return;
    }

    // Store the original next_payment date so we can restore the billing
    // schedule correctly after a late payment.
    $subscription->update_meta_data( '_its_original_next_payment', $subscription->get_date( 'next_payment' ) );

    $subscription->update_status( 'on-hold', __( 'Awaiting manual renewal payment.', 'wcs-extended-reminders' ) );

    $next_payment  = $subscription->get_date( 'next_payment' );
    $due_timestamp = $next_payment ? wcs_date_to_time( $next_payment ) : time();

    // Schedule the overdue check for one day after the due date.
    as_schedule_single_action(
        $due_timestamp + DAY_IN_SECONDS,
        'its_check_overdue_renewal',
        array( $subscription->get_id(), $pending_order->get_id() ),
        'wcs-extended-reminders'
    );
}

/* -------------------------------------------------------------------------
 * 2c. Flag orders created via the admin "Generate Renewal Order" button
 * ---------------------------------------------------------------------- */

function its_flag_admin_created_renewal_order( $renewal_order_id ) {
    $order = wc_get_order( $renewal_order_id );
    if ( ! $order ) {
        return;
    }

    if ( ! $order->has_status( 'pending' ) ) {
        $order->update_status( 'pending', __( 'Set pending for login-free payment link.', 'wcs-extended-reminders' ) );
    }

    $order->update_meta_data( '_its_autologin_renewal', '1' );
    $order->save();
}

/* -------------------------------------------------------------------------
 * 2d. Helper: find an existing pending renewal order for a subscription
 * ---------------------------------------------------------------------- */

function its_get_pending_renewal_order( WC_Subscription $subscription ) {
    foreach ( $subscription->get_related_orders( 'ids', 'renewal' ) as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->has_status( 'pending' ) ) {
            return $order;
        }
    }
    return null;
}


/* =========================================================================
 * PART 3 — RENEWAL URL REPLACEMENT IN EMAIL
 *
 * WCS renewal reminder emails use an early-renewal URL
 * (?subscription_renewal_early=ID) rather than the standard order-pay URL.
 * We intercept the composed email body and swap it for a clean order-pay
 * URL so that login-free payment links work without modification.
 * ====================================================================== */

function its_replace_renewal_url_in_email( $content ) {
    if ( ! preg_match( '/[?&]subscription_renewal_early=(\d+)/', $content, $matches ) ) {
        return $content;
    }

    $subscription_id = (int) $matches[1];
    $subscription    = wcs_get_subscription( $subscription_id );

    if ( ! $subscription ) {
        return $content;
    }

    $pending_order = its_get_pending_renewal_order( $subscription );
    if ( ! $pending_order ) {
        return $content;
    }

    $pay_url = add_query_arg(
        array(
            'pay_for_order'        => 'true',
            'key'                  => $pending_order->get_order_key(),
            'subscription_renewal' => 'true',
        ),
        wc_get_endpoint_url( 'order-pay', $pending_order->get_id(), wc_get_checkout_url() )
    );

    // Replace every occurrence of the WCS early-renewal URL in the email.
    $content = preg_replace(
        '/https?:\/\/[^\s"\']+[?&]subscription_renewal_early=' . $subscription_id . '[^\s"\']*/',
        esc_url( $pay_url ),
        $content
    );

    return $content;
}


/* =========================================================================
 * PART 4 — OVERDUE HANDLING
 *
 * Fires one day after the original due date. If the order is still unpaid,
 * flags the subscription as overdue and notifies the customer and admin.
 * The subscription status is NOT changed again — the payment link remains
 * fully functional.
 * ====================================================================== */

function its_handle_overdue_renewal( $subscription_id, $renewal_order_id ) {
    $subscription = wcs_get_subscription( $subscription_id );
    $order        = wc_get_order( $renewal_order_id );

    if ( ! $subscription || ! $order ) {
        return;
    }

    // If the payment came in, WCS already reactivated the subscription.
    if ( ! $order->has_status( array( 'pending', 'failed' ) ) ) {
        return;
    }

    // Avoid duplicate notifications.
    if ( $subscription->get_meta( '_its_overdue_since' ) ) {
        return;
    }

    $subscription->update_meta_data( '_its_overdue_since', current_time( 'mysql' ) );
    $subscription->add_order_note(
        sprintf(
            __( 'Subscription marked overdue. Renewal order #%d remains unpaid.', 'wcs-extended-reminders' ),
            $renewal_order_id
        )
    );
    $subscription->save();

    $customer_email = $order->get_billing_email();
    $customer_name  = $order->get_billing_first_name();
    $pay_url        = add_query_arg(
        array(
            'pay_for_order' => 'true',
            'key'           => $order->get_order_key(),
        ),
        wc_get_endpoint_url( 'order-pay', $order->get_id(), wc_get_checkout_url() )
    );

    // Customer notification.
    wp_mail(
        $customer_email,
        sprintf( __( 'Your subscription renewal is overdue — %s', 'wcs-extended-reminders' ), get_bloginfo( 'name' ) ),
        sprintf(
            __( "Dear %s,\n\nYour subscription renewal payment is now overdue.\n\nYou can complete your payment here:\n%s\n\nIf you have any questions, please contact us.\n\n%s", 'wcs-extended-reminders' ),
            $customer_name,
            $pay_url,
            get_bloginfo( 'name' )
        ),
        array( 'Content-Type: text/plain; charset=UTF-8' )
    );

    // Admin notification.
    wp_mail(
        get_option( 'admin_email' ),
        sprintf( __( 'Overdue subscription renewal — #%d', 'wcs-extended-reminders' ), $subscription_id ),
        sprintf(
            __( "Subscription #%d has an overdue renewal.\n\nCustomer: %s (%s)\nRenewal order: #%d\n\nSubscription: %s\nRenewal order: %s", 'wcs-extended-reminders' ),
            $subscription_id,
            $order->get_formatted_billing_full_name(),
            $customer_email,
            $renewal_order_id,
            admin_url( 'post.php?post=' . $subscription_id . '&action=edit' ),
            admin_url( 'post.php?post=' . $renewal_order_id . '&action=edit' )
        ),
        array( 'Content-Type: text/plain; charset=UTF-8' )
    );
}

/* -------------------------------------------------------------------------
 * Clear the overdue flag when payment is eventually received.
 * ---------------------------------------------------------------------- */

function its_clear_overdue_flag( $subscription ) {
    if ( $subscription->get_meta( '_its_overdue_since' ) ) {
        $subscription->delete_meta_data( '_its_overdue_since' );
        $subscription->save();
    }
}

/* -------------------------------------------------------------------------
 * Daily sweep: catch on-hold subscriptions that went overdue via any path.
 * ---------------------------------------------------------------------- */

function its_schedule_daily_overdue_check() {
    if ( ! as_next_scheduled_action( 'its_daily_overdue_check' ) ) {
        as_schedule_recurring_action(
            strtotime( 'tomorrow midnight' ),
            DAY_IN_SECONDS,
            'its_daily_overdue_check',
            array(),
            'wcs-extended-reminders'
        );
    }
}

function its_run_daily_overdue_check() {
    $subscriptions = wcs_get_subscriptions( array(
        'subscription_status'    => 'on-hold',
        'subscriptions_per_page' => -1,
    ) );

    foreach ( $subscriptions as $subscription ) {

        if ( $subscription->get_meta( '_its_overdue_since' ) ) {
            continue; // Already flagged.
        }

        $next_payment = $subscription->get_date( 'next_payment' );
        if ( ! $next_payment ) {
            continue;
        }

        $due_timestamp = wcs_date_to_time( $next_payment );
        if ( ( $due_timestamp + DAY_IN_SECONDS ) > time() ) {
            continue; // Grace period hasn't elapsed.
        }

        $pending_order = its_get_pending_renewal_order( $subscription );

        if ( ! $pending_order ) {
            // Subscription didn't go through our normal flow — create an order now.
            if ( ! $subscription->is_manual() ) {
                continue; // Automatic subscriptions handle their own recovery.
            }

            $renewal_order = wcs_create_renewal_order( $subscription );

            if ( is_wp_error( $renewal_order ) ) {
                wc_get_logger()->error(
                    sprintf(
                        'WCS Extended Reminders daily check: Failed to create renewal order for subscription #%d: %s',
                        $subscription->get_id(),
                        $renewal_order->get_error_message()
                    ),
                    array( 'source' => 'wcs-extended-reminders' )
                );
                continue;
            }

            $renewal_order->update_status( 'pending', __( 'Auto-created by overdue check.', 'wcs-extended-reminders' ) );
            $renewal_order->update_meta_data( '_its_autologin_renewal', '1' );
            $renewal_order->save();
            $pending_order = $renewal_order;
        }

        its_handle_overdue_renewal( $subscription->get_id(), $pending_order->get_id() );
    }
}


/* =========================================================================
 * PART 5 — BILLING SCHEDULE CORRECTION
 *
 * When a manual subscription goes on-hold we snapshot the original
 * next_payment date. After the customer eventually pays (possibly late),
 * WCS would push next_payment to today + interval. We correct it to
 * original_date + interval so the billing schedule is preserved.
 * ====================================================================== */

function its_restore_original_billing_schedule( $subscription, $renewal_order ) {
    $original_next_payment = $subscription->get_meta( '_its_original_next_payment' );

    if ( ! $original_next_payment ) {
        return;
    }

    $correct_next_payment = wcs_add_time(
        $subscription->get_billing_interval(),
        $subscription->get_billing_period(),
        wcs_date_to_time( $original_next_payment )
    );

    $wcs_calculated = $subscription->get_time( 'next_payment' );

    if ( $wcs_calculated !== $correct_next_payment ) {
        $subscription->update_dates( array(
            'next_payment' => gmdate( 'Y-m-d H:i:s', $correct_next_payment ),
        ) );

        $subscription->add_order_note(
            sprintf(
                __( 'Next payment date corrected from %s to %s to preserve original billing schedule.', 'wcs-extended-reminders' ),
                date_i18n( get_option( 'date_format' ), $wcs_calculated ),
                date_i18n( get_option( 'date_format' ), $correct_next_payment )
            )
        );
    }

    $subscription->delete_meta_data( '_its_original_next_payment' );
    $subscription->save();
}

// Also handles the case where an admin manually marks the renewal order
// as processing or completed from the WooCommerce order screen.
function its_restore_schedule_on_manual_status_change( $order_id, $old_status, $new_status ) {
    if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || ! wcs_order_contains_renewal( $order ) ) {
        return;
    }

    foreach ( wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
        its_restore_original_billing_schedule( $subscription, $order );
    }
}


/* =========================================================================
 * PART 6 — ADMIN UI: OVERDUE COLUMN, ROW HIGHLIGHT & STATUS FILTER
 * ====================================================================== */

/* -------------------------------------------------------------------------
 * 6a. Register the "Overdue" column on the subscription list table.
 * ---------------------------------------------------------------------- */

function its_add_overdue_column( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $label ) {
        $new_columns[ $key ] = $label;
        if ( 'status' === $key ) {
            $new_columns['its_overdue'] = __( 'Overdue', 'wcs-extended-reminders' );
        }
    }
    return $new_columns;
}

function its_render_overdue_column( $column, $post_id ) {
    if ( 'its_overdue' !== $column ) {
        return;
    }

    $subscription = wcs_get_subscription( $post_id );
    if ( ! $subscription ) {
        return;
    }

    $overdue_since = $subscription->get_meta( '_its_overdue_since' );
    if ( ! $overdue_since ) {
        echo '<span style="color:#ccc;">—</span>';
        return;
    }

    $date_formatted = date_i18n( get_option( 'date_format' ), strtotime( $overdue_since ) );

    printf(
        '<mark class="its-overdue-badge" style="background:#e00; color:#fff; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600; white-space:nowrap;" title="%s">%s</mark>',
        esc_attr( sprintf( __( 'Overdue since %s', 'wcs-extended-reminders' ), $date_formatted ) ),
        esc_html__( 'OVERDUE', 'wcs-extended-reminders' )
    );
}

/* -------------------------------------------------------------------------
 * 6b. Highlight overdue rows with a red background (via JS + inline CSS).
 * ---------------------------------------------------------------------- */

function its_overdue_row_highlight_script() {
    global $post_type;
    if ( 'shop_subscription' !== $post_type ) {
        return;
    }

    $overdue_ids = get_posts( array(
        'post_type'      => 'shop_subscription',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_key'       => '_its_overdue_since',
        'meta_compare'   => 'EXISTS',
        'fields'         => 'ids',
    ) );

    if ( empty( $overdue_ids ) ) {
        return;
    }

    $overdue_ids = array_map( 'intval', $overdue_ids );
    ?>
    <style>
        tr.its-overdue-row td       { background-color: #fff0f0 !important; }
        tr.its-overdue-row:hover td { background-color: #ffe0e0 !important; }
    </style>
    <script>
        ( function () {
            var overdueIds = <?php echo wp_json_encode( $overdue_ids ); ?>;
            overdueIds.forEach( function ( id ) {
                var row = document.getElementById( 'post-' + id );
                if ( row ) {
                    row.classList.add( 'its-overdue-row' );
                }
            } );
        } )();
    </script>
    <?php
}

/* -------------------------------------------------------------------------
 * 6c. Add an "Overdue" filter link to the status tabs at the top of the list.
 * ---------------------------------------------------------------------- */

function its_add_overdue_filter_view( $views ) {
    $overdue_count = count( get_posts( array(
        'post_type'      => 'shop_subscription',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_key'       => '_its_overdue_since',
        'meta_compare'   => 'EXISTS',
        'fields'         => 'ids',
    ) ) );

    if ( ! $overdue_count ) {
        return $views;
    }

    $current = isset( $_GET['its_overdue'] ) && '1' === $_GET['its_overdue'];
    $url     = add_query_arg( array(
        'post_type'   => 'shop_subscription',
        'its_overdue' => '1',
    ), admin_url( 'edit.php' ) );

    $views['its_overdue'] = sprintf(
        '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
        esc_url( $url ),
        $current ? ' class="current" aria-current="page"' : '',
        esc_html__( 'Overdue', 'wcs-extended-reminders' ),
        $overdue_count
    );

    return $views;
}

/* -------------------------------------------------------------------------
 * 6d. Filter the list query when the Overdue view is active.
 * ---------------------------------------------------------------------- */

function its_filter_overdue_subscriptions_query( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if (
        'shop_subscription' !== $query->get( 'post_type' ) ||
        ! isset( $_GET['its_overdue'] ) ||
        '1' !== $_GET['its_overdue']
    ) {
        return;
    }

    $query->set( 'meta_key',     '_its_overdue_since' );
    $query->set( 'meta_compare', 'EXISTS' );
    $query->set( 'post_status',  'any' );
}
