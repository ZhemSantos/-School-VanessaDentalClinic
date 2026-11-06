# Email Notification System Setup

## Overview
The Vanessa Nicolas Dental Clinic system now includes automated email notifications for:
- Appointment scheduling
- Appointment confirmation
- Appointment cancellation (late arrivals)
- Payment confirmations

## Gmail Account Configuration
- **Email:** vanessadentalclinictesting@gmail.com
- **Password:** vanessadentalclinic21

## Features Implemented

### 1. Late Appointment Cancellation
- **Grace Period:** 15 minutes
- **Automated Check:** Runs via cron job every minute
- **Action:** Automatically cancels appointments if patient is more than 15 minutes late
- **Notification:** Sends cancellation email to patient

### 2. Email Notifications
All emails are sent using the clinic's Gmail account with professional HTML templates.

#### Appointment Scheduled
- Sent when a new appointment is created
- Includes appointment details and reminders

#### Appointment Confirmed
- Sent when receptionist confirms an appointment
- Includes confirmed appointment details

#### Appointment Cancelled
- Sent when appointment is cancelled due to late arrival
- Includes reason and instructions for rescheduling

#### Payment Confirmation
- Sent when payment is processed
- Includes payment details and receipt information

## Setup Instructions

### 1. Cron Job Setup (Linux/Unix)
To automatically check for late appointments, set up a cron job:

```bash
# Edit crontab
crontab -e

# Add this line to run every minute
* * * * * /usr/bin/php /path/to/your/project/cron/check_late_appointments.php >> /path/to/logs/late_appointments.log 2>&1
```

### 2. Windows Task Scheduler
For Windows servers:

1. Open Task Scheduler
2. Create Basic Task
3. Set trigger to "Daily" or "When computer starts"
4. Set action to run: `php.exe`
5. Add arguments: `C:\path\to\your\project\cron\check_late_appointments.php`
6. Set to repeat every 1 minute

### 3. Manual Execution (Testing)
You can manually run the late appointment checker:

```bash
php cron/check_late_appointments.php
```

## File Structure

```
/includes
  - email_config.php          # Email configuration and SMTP settings
  - email_notifications.php   # Email notification functions

/cron
  - check_late_appointments.php  # Cron job script for late appointments

/receptionist
  - receptionist_create_booking.php  # Sends email on appointment creation
  - receptionist_booking.php         # Sends email on appointment confirmation
  - receptionist_payment.php         # Sends email on payment processing
```

## Email Template
All emails use a professional HTML template with:
- Clinic branding (purple/gold theme)
- Responsive design
- Clear information display
- Professional footer

## Troubleshooting

### Emails Not Sending
1. Check PHP `mail()` function is enabled
2. Verify Gmail credentials in `includes/email_config.php`
3. Check server logs for errors
4. For production, consider using PHPMailer library for better reliability

### Late Appointments Not Being Cancelled
1. Verify cron job is running: `crontab -l`
2. Check cron job logs
3. Manually run the script to test
4. Verify database connection in the script

### Gmail Authentication Issues
If Gmail blocks the login:
1. Enable "Less secure app access" in Gmail settings (not recommended for production)
2. Use App Password instead (recommended)
3. Consider using PHPMailer with OAuth2 for better security

## Production Recommendations

1. **Use PHPMailer Library:**
   ```bash
   composer require phpmailer/phpmailer
   ```
   Then update `email_config.php` to use PHPMailer instead of native `mail()`

2. **Use App Passwords:**
   - Generate Gmail App Password instead of using account password
   - More secure and recommended by Google

3. **Queue System:**
   - For high volume, consider implementing an email queue
   - Prevents blocking and improves reliability

4. **Email Service Provider:**
   - Consider using services like SendGrid, Mailgun, or AWS SES
   - Better deliverability and tracking

## Testing

To test email functionality:
1. Create a test appointment
2. Check patient email for "Appointment Scheduled" email
3. Confirm the appointment
4. Check for "Appointment Confirmed" email
5. Process a payment
6. Check for "Payment Confirmation" email

To test late appointment cancellation:
1. Create an appointment for a past time
2. Run `php cron/check_late_appointments.php`
3. Check that appointment status changed to "Cancelled"
4. Check patient email for cancellation notice

