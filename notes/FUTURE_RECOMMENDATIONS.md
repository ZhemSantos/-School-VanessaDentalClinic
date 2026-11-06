# Future System Improvements & Recommendations

## 1. Real-Time Notification System (Booking Gap)
**Current State:**
- When a client books an appointment via the Patient Portal, the appointment is created with status `Pending`.
- The Receptionist does NOT receive an immediate alert, email, or system notification.
- The Receptionist must proactively refresh the "Bookings" page or "Dashboard" to see the new request.
- The Doctor is also not notified of client-initiated bookings until confirmed/reviewed.

**Recommendation:**
- Implement an Observer Pattern or simple Email/System Notification trigger in `api/api_appointments.php`.
- **Action**: When `createAppointment` is called successfully:
  1.  Insert a record into `tbl_notifications` targeting users with role `Receptionist`.
  2.  Send an email to the clinic's admin/reception email address (e.g., `admin@clinic.com`).
- **Benefit**: Reduces the time between a client booking and the clinic confirming/processing the payment, improving user experience and reducing missed appointments.

## 2. WebSocket / Live Updates
**Current State:**
- The Dashboard uses standard PHP page loads. New data only appears on refresh.
**Recommendation:**
- Implement AJAX polling (every 30-60 seconds) or WebSockets (Pusher/Socket.io) to update the "Pending Requests" counter on the Receptionist Dashboard in real-time.

## 3. Dynamic Doctor Schedule UI
**Current State:**
- The backend (`tbl_doctor_schedule`) supports dynamic shifts.
- Currently, there is no UI for Doctors to edit their own schedule; it relies on database defaults or manual SQL entry.
**Recommendation:**
- Create a "Manage Schedule" page in the **Doctor Portal**.
- Allow doctors to set their start/end times per day of the week.
- This will fully leverage the "Dynamic Time Slots" feature we implemented.
