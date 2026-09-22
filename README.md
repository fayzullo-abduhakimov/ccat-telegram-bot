# CCAT Telegram Booking Bot

A dedicated Laravel application for delivering Centre for Contemporary Art Tashkent (CCAT) digital QR entrance passes, rescheduling visit and library times, and cancelling bookings via Telegram.

---

## Features

- **Instant QR Code Delivery**: Automatically sends the visitor's admission QR code in PNG format as soon as they redirect from the CCAT booking submission confirmation.
- **Complete Visit Details**: Displays visitor name, date, time, venue, and event title within Telegram.
- **Change Booking Time**: Interactive inline buttons allow visitors to view available upcoming dates and slots, and reschedule their booking directly within Telegram.
- **Cancel Booking**: Interactive cancellation with confirmation, instantly freeing up the reserved slot and daily quota in the CCAT system.
- **Works for All Booking Systems**:
  - **General Visits**: Building B admission slots.
  - **Library Reading Room**: Service Building, 3rd floor time slots and durations.
  - **Programme Events**: Exhibition and workshop registrations.
- **Dual Operation Modes**:
  - **Long-Polling (`php artisan telegram:poll`)**: Runs locally without SSL, public domain, or tunneling services.
  - **Webhook (`POST /api/telegram/webhook`)**: Production-ready webhook endpoint for server deployments.

---

## Getting Started

### 1. Requirements
- PHP 8.2+
- Composer
- A Telegram Bot Token from [@BotFather](https://t.me/BotFather)

### 2. Configuration (`.env`)
Configure the environment variables in `.env`:

```env
TELEGRAM_BOT_TOKEN="your_bot_token_from_botfather"
TELEGRAM_BOT_USERNAME="ccat_booking_bot"
CCAT_API_URL="http://localhost:8000/api/bot"
```

> **Note**: Ensure `TELEGRAM_BOT_USERNAME=ccat_booking_bot` is also configured in the main CCAT project (`ccat_new/.env`).

---

## Running the Bot

### Option A: Local Development (Long Polling)
For local development, no public domain or tunneling service is required. Run:

```bash
php artisan telegram:poll
```

The bot connects to Telegram and outputs console logs for incoming messages, callback queries, and booking operations.

### Option B: Production Webhook
To register the production HTTPS webhook URL:

```bash
php artisan telegram:webhook set --url=https://your-domain.com/api/telegram/webhook
```

To inspect current webhook status:
```bash
php artisan telegram:webhook info
```

To delete the webhook (e.g., to return to long polling):
```bash
php artisan telegram:webhook delete
```

---

## Testing

### Test Connection to CCAT API
To test fetching booking details and the QR code independently of Telegram:

```bash
php artisan booking:check <booking_token_here>
```

### Run Automated Tests
```bash
php artisan test
```

---

## Workflow

1. **User Books on CCAT Website**:
   The user submits a booking form (General Visit, Library, or Programme).
2. **Confirmation Modal**:
   The confirmation modal presents the QR code, download link, and a button to send the pass to Telegram.
3. **Redirect to Bot**:
   Clicking the button opens Telegram with the deep-link:
   `https://t.me/<bot_username>?start=<token>`
4. **User Starts Bot**:
   The bot reads the token, queries the CCAT API, and delivers:
   - The QR code image (PNG format)
   - Full booking details (Name, Date, Time, Venue, Status)
   - Inline action buttons: `[ Change Time ]` and `[ Cancel Booking ]`
5. **Rescheduling / Cancellation**:
   - Selecting `[ Change Time ]` retrieves available upcoming dates and slots from CCAT, allowing the user to select a new time.
   - Selecting `[ Cancel Booking ]` requests confirmation and cancels the reservation in CCAT.
