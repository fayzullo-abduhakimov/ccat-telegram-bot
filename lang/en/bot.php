<?php

declare(strict_types=1);

return [
    'menu_my_bookings' => '📋 My Bookings',
    'menu_help' => 'ℹ️ Help',
    'cmd_mybookings_desc' => '📋 View my active bookings',
    'cmd_help_desc' => 'ℹ️ How to use this bot',
    'cmd_start_desc' => '🚀 Start / View bookings',

    'welcome_header' => '🏛 <b>Welcome to CCAT Booking Bot!</b>',
    'welcome_body' => "This bot provides instant access to your <b>Centre for Contemporary Art Tashkent</b> digital QR entrance passes.\n\n✨ <b>Features:</b>\n• 🎟 Instant QR Code pass in PNG format\n• 📅 Change your visit or reading room booking time\n• ❌ Cancel your booking anytime with one tap\n• 🌐 Works for all CCAT bookings: General Visits, Library, and Programme Events\n\n📲 <b>To get started:</b>\nBook your slot on <a href=\"https://ccat.uz\">ccat.uz</a> and tap <b>\"Get QR code via Telegram\"</b> on your confirmation popup!",

    'my_bookings_empty' => "📋 <b>My Bookings</b>\n\nYou do not have any active bookings at the moment.\n\nVisit <a href=\"https://ccat.uz\">ccat.uz</a> to reserve a visit or sign up for events.",
    'my_bookings_list' => "📋 <b>My Bookings</b>\n\nHere are your active reservations. Tap any booking below to view its digital QR pass, change time, or cancel:",

    'btn_view_pass' => '🎟 View Pass',
    'btn_change_time' => '📅 Change Time',
    'btn_cancel_booking' => '❌ Cancel Booking',
    'btn_my_bookings' => '📋 My Bookings',
    'btn_back_to_booking' => '🔙 Back to Booking',
    'btn_keep_booking' => '🔙 Keep Booking',
    'btn_confirm_cancel' => '❌ Yes, Cancel Booking',
    'btn_back_to_dates' => '🔙 Back to Dates',

    'cancel_confirm_prompt' => "⚠️ <b>Cancel Booking: :ref?</b>\n\nAre you sure you want to cancel your reservation?\nYour reserved slot will be freed immediately and cannot be held.",
    'cancel_success' => "✅ <b>Booking Cancelled</b>\n\nYour reservation has been cancelled and your place has been released.\n\nIf you would like to book a visit at another time, please register at <a href=\"https://ccat.uz\">ccat.uz</a>.",
    'cancel_failed' => "⚠️ <b>Cancellation Failed</b>\n\n:error",

    'action_not_allowed_cancel_verified' => "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be cancelled.",
    'action_not_allowed_reschedule_verified' => "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be rescheduled.",
    'action_not_allowed_verified' => "⚠️ <b>Action Not Allowed</b>\n\nThis booking has already been verified and attendance recorded. It cannot be cancelled.",

    'reschedule_title' => "📅 <b>Change Booking Time</b>\n\nPlease select a new date for your visit:",
    'reschedule_select_time' => "⏰ <b>Select Time for :date</b>\n\nChoose an available slot:",
    'reschedule_success' => "🎉 <b>Booking Successfully Updated!</b>\n\nYour reservation has been rescheduled.\nHere is your updated digital pass:",
    'reschedule_no_slots' => "ℹ️ <b>Cannot Change Time</b>\n\nThere are currently no open slots available for rescheduling. Please check back later or visit <a href=\"https://ccat.uz\">ccat.uz</a>.",

    'booking_not_found' => "❌ <b>Booking Not Found</b>\n\nWe could not find an active booking matching reference <code>:ref</code>.\n\nPlease check your confirmation on <a href=\"https://ccat.uz\">ccat.uz</a>.",
];
