<?php

declare(strict_types=1);

return [
    'menu_my_bookings' => '📋 Mening bandliklarim',
    'menu_help' => 'ℹ️ Yordam',
    'cmd_mybookings_desc' => '📋 Faol bandliklarimni ko‘rish',
    'cmd_help_desc' => 'ℹ️ Bot haqida ma’lumot',
    'cmd_start_desc' => '🚀 Asosiy menyu / Bandliklarim',

    'welcome_header' => '🏛 <b>CCAT Booking Botiga xush kelibsiz!</b>',
    'welcome_body' => "Ushbu bot <b>Toshkent Zamonaviy san’at markazi (CCA Tashkent)</b> raqamli QR-chiptalaridan qulay foydalanish imkoniyatini taqdim etadi.\n\n✨ <b>Imkoniyatlar:</b>\n• 🎟 PNG formatidagi lahzali QR-chipta\n• 📅 Tashrif yoki kutubxona vaqtini ko‘chirish\n• ❌ Bandlikni bir bosish bilan bekor qilish\n• 🌐 Barcha turdagi bandliklar: umumiy tashrif, kutubxona va tadbirlar\n\n📲 <b>Boshlash uchun:</b>\n<a href=\"https://ccat.uz\">ccat.uz</a> saytida joy band qiling va tasdiqlash oynasidagi <b>«Telegram orqali QR-kod olish»</b> tugmasini bosing!",

    'my_bookings_empty' => "📋 <b>Mening bandliklarim</b>\n\nSizda hozircha faol bandliklar mavjud emas.\n\nTashrif buyurish yoki tadbirlarga yozilish uchun <a href=\"https://ccat.uz\">ccat.uz</a> saytidan foydalaning.",
    'my_bookings_list' => "📋 <b>Mening bandliklarim</b>\n\nSizning faol bandliklaringiz. QR-chiptani ko‘rish, vaqtni o‘zgartirish yoki bekor qilish uchun quyidagilardan birini tanlang:",

    'btn_view_pass' => '🎟 Chiptani ochish',
    'btn_change_time' => '📅 Vaqtni o‘zgartirish',
    'btn_cancel_booking' => '❌ Bekor qilish',
    'btn_my_bookings' => '📋 Mening bandliklarim',
    'btn_back_to_booking' => '🔙 Bandlikka qaytish',
    'btn_keep_booking' => '🔙 Bandlikni qoldirish',
    'btn_confirm_cancel' => '❌ Ha, bekor qilish',
    'btn_back_to_dates' => '🔙 Sanalarga qaytish',

    'cancel_confirm_prompt' => "⚠️ <b>:ref bandligini bekor qilishni xohlaysizmi?</b>\n\nRostdan ham bandlikni bekor qilmoqchimisiz?\nBand qilingan joy darhol bo‘shatiladi.",
    'cancel_success' => "✅ <b>Bandlik bekor qilindi</b>\n\nSizning bandligingiz bekor qilindi va joy bo‘shatildi.\n\nBoshqa vaqtda tashrif buyurish uchun <a href=\"https://ccat.uz\">ccat.uz</a> saytida qayta ro‘yxatdan o‘ting.",
    'cancel_failed' => "⚠️ <b>Bekor qilishda xatolik</b>\n\n:error",

    'action_not_allowed_cancel_verified' => "⚠️ <b>Amal bajarilmadi</b>\n\nUshbu chipta kirishda tekshirib bo‘lingan. Uni bekor qilish mumkin emas.",
    'action_not_allowed_reschedule_verified' => "⚠️ <b>Amal bajarilmadi</b>\n\nUshbu chipta kirishda tekshirib bo‘lingan. Vaqtni ko‘chirish mumkin emas.",
    'action_not_allowed_verified' => "⚠️ <b>Amal bajarilmadi</b>\n\nUshbu chipta kirishda tekshirib bo‘lingan. Uni bekor qilish mumkin emas.",

    'reschedule_title' => "📅 <b>Vaqtni o‘zgartirish</b>\n\nIltimos, tashrif uchun yangi sanani tanlang:",
    'reschedule_select_time' => "⏰ <b>:date sanasi uchun vaqtni tanlang</b>\n\nBo‘sh vaqt oralig‘ini tanlang:",
    'reschedule_success' => "🎉 <b>Bandlik muvaffaqiyatli yangilandi!</b>\n\nTashrif vaqti o‘zgartirildi.\nSizning yangilangan elektron chiptangiz:",
    'reschedule_no_slots' => "ℹ️ <b>Vaqtni o‘zgartirib bo‘lmadi</b>\n\nAfsuski, ko‘chirish uchun bo‘sh vaqtlar topilmadi. Keyinroq urinib ko‘ring yoki <a href=\"https://ccat.uz\">ccat.uz</a> saytiga kiring.",

    'booking_not_found' => "❌ <b>Bandlik topilmadi</b>\n\n<code>:ref</code> raqami bo‘yicha faol bandlik topilmadi.\n\nIltimos, <a href=\"https://ccat.uz\">ccat.uz</a> saytidagi tasdiqnomangizni tekshiring.",
];
