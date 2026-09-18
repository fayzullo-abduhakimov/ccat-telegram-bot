# CCAT Telegram Booking Bot

A dedicated Laravel application for delivering **Center for Contemporary Art Tashkent (CCAT)** digital QR entrance passes, rescheduling visit/library times, and cancelling bookings via Telegram.

---

## 🌟 Features

- 🎟 **Instant QR Code Delivery**: Automatically sends the visitor's admission QR code in PNG format as soon as they redirect from the CCAT booking submission popup.
- 📋 **Complete Visit Details**: Displays Name, Surname, Date, Time, Building / Venue, and Event title in Telegram.
- 📅 **Change Booking Time**: Interactive inline buttons allow visitors to view available upcoming dates and slots, and reschedule their booking directly within Telegram.
- ❌ **Cancel Booking**: Interactive cancellation with confirmation, instantly freeing up the reserved slot and daily quota in the CCAT system.
- 🌐 **Works for All Booking Systems**:
  - **General Visits**: Building B admission slots.
  - **Library Reading Room**: Service Building, 3rd floor time slots and durations.
  - **Programme Events**: Exhibition and workshop registrations.
- ⚡ **Dual Operation Modes**:
  - **Long-Polling (`php artisan telegram:poll`)**: Runs locally without SSL, public domain, or tunneling services.
  - **Webhook (`POST /api/telegram/webhook`)**: Production-ready webhook endpoint for server deployments.

---

## 🚀 Getting Started

### 1. Requirements
- PHP 8.2+
- Composer
- A Telegram Bot Token from [@BotFather](https://t.me/BotFather)

### 2. Configuration (`.env`)
In `C:\Users\f.abduhakimov\Desktop\ccat-telegram-bot\.env`:

```env
TELEGRAM_BOT_TOKEN="your_bot_token_from_botfather"
TELEGRAM_BOT_USERNAME="ccat_booking_bot"
CCAT_API_URL="http://localhost:8000/api/bot"
```

> **Note**: Also ensure `TELEGRAM_BOT_USERNAME=ccat_booking_bot` is configured in the main CCAT project (`ccat_new/.env`).

---

## 🏃‍♂️ Running the Bot

### Option A: Local Development (Long Polling)
No public domain or ngrok required! Simply run:

```bash
php artisan telegram:poll
```

The bot connects to Telegram and displays live logs of incoming messages, button clicks, and booking operations.

### Option B: Production Webhook
To set your production HTTPS webhook URL:

```bash
php artisan telegram:webhook set --url=https://your-domain.com/api/telegram/webhook
```

To inspect current webhook status:
```bash
php artisan telegram:webhook info
```

To delete webhook (e.g. to switch back to local polling):
```bash
php artisan telegram:webhook delete
```

---

## 🧪 Testing

### Test Connection to CCAT API
You can test fetching booking details and QR code without Telegram:

```bash
php artisan booking:check <booking_token_here>
```

### Run Automated Tests
```bash
php artisan test
```

---

## 🔄 How the Flow Works

1. **User Books on CCAT Website**:
   The user fills the booking form (Visit, Library, or Programme) and submits.
2. **Popup Modal Appears**:
   The confirmation popup presents the QR code, download link, and a new button: **"Get QR code via Telegram"**.
3. **Redirect to Bot**:
   Clicking the button opens Telegram with deep-link:
   `https://t.me/<bot_username>?start=<token>`
4. **User Taps Start**:
   The bot reads the token, queries CCAT's API, and immediately sends:
   - The QR Code image in PNG format
   - Full booking details (Name, Date, Time, Building, Status)
   - Inline action buttons: `[ 📅 Change Time ]` and `[ ❌ Cancel Booking ]`
5. **Rescheduling / Cancellation**:
   - Tapping `[ 📅 Change Time ]` fetches upcoming open days and slots from CCAT and lets the user pick a new time.
   - Tapping `[ ❌ Cancel Booking ]` prompts confirmation and deletes/cancels the reservation in CCAT.
