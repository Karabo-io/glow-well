<?php
    require_once __DIR__ . '/../config/db.php';

    if(session_status === PHP_SESSION_NONE) {
        session_set_cookie_params(['httponly' => true, 'samesite' => "Lax"]);
        session_start();
    }

    // Output, navigation and flash messages
    // Escape text for safe HTML output  (prevent XSS).
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    function url(string $path = ""): string {
        return BASE_URL . '/' . ltrim($path, '/');
    }

    function redirect(string $path): void {
        header("Location: " . url($path));
        exit;
    }

    function flash(string $type, string $message): void {
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
    }

    function get_flashes(): array {
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
         return $items;
    }

    //CSRF protection and authentication
    function csrf_token():string {
        if(empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    function csrf_field(): string {
        return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    }

    // Stops the request when a POST form does not carry valid token.
    function csrf_check(): void {
        $sent = $_POST['csrf'] ?? ""; 

        if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? "", $sent)) {
            http_response_code(400);
            exit('Your session expired or the form was invalid. Please go back, refresh the page and try again.');
        }
    }

    function is_logged_in(): bool {
        return !empty($_SESSION['user_id']);
    }

    function home_for_role(): string {
        switch ($_SESSION['role'] ?? "") {
            case "admin": return "admin/dashboard.php";
            case "staff": return "staff/dashboard.php";
            case "customer": return "my_appointments.php";
            default: return "index.php";
        }
    }

    function require_login(array $roles = []): void {
        if(!is_logged_in()) {
            flash("error", "Please log in to continue.");
            redirect('login.php');
        }

        if($roles && !in_array($_SESSION['role'], $role, true)) {
            flash("error", "You do not have permission to open that page.");
            redirect(home_for_role());
        }
    }

    function load_user_name(PDO $pdo, int $userId, string $role): string {
        if($role === 'customer') {
            $sql = "SELECT CONCAT(first_name, ' ' , last_name) FROM customers WHERE user_id = ?";
        } elseif ($role === 'staff') {
            $sql = "SELECT CONCAT(first_name, ' ', laSt_name) FROM staff WHERE user_id = ?";
        } else {
            return 'Adminstrator';
        }
        $st = $pdo->prepare($sql);
        $st->execute([$userId]);
        return (string)($st->fetchColimn() ?: 'USER');
    }

    function customer_id_for_user(PDO $pdo, int $userId): int {
        $st = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    function staff_id_for_user(PDO $pdo, int $userId): int {
        $st = $pdo->prepare("SELECT staff_id FROM staff WHERE user_id = ?");
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    //Validation helpers
    function post(string $key): string {
        return trim((string)($_POST[$key] ?? ""));
    }

    function valid_email(string $v): bool {
        return (bool)filter_var($v, FILTER_VALIDATE_EMAIL) && strlen($v) <= 150;
    }

    function valid_phone(string $v): bool {
        return (bool)preg_match('/^[0-9+ ()\-]{9,15}$/', $v);
    }

    function valid_name(string $v): bool {
        return $v !== '' && mb_strlen($v) <= 60 && (bool)preg_match("/^[\p{L}][\p{L} '\-]*$/u", $v);
    }

    function valid_password(string $v): bool {
        return strlen($v) >= 8 && preg_match('/[A-Za-z]/', $v) && preg_match('/\d/', $v);
    }

    function validate_date(string $d): bool {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    function valid_time(string $t): bool {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }


    //Formatting helpers

    function money($amount): string {
        return 'R ' . number_format((float)$amount, 2, '.', ' ');
    }

    function fmt_date(string $d): string {
        return date('D, d M Y', strtotime($d));
    }

    function fmt_time(string $t): string {
        return substr($t, 0, 5);
    }

    function status_badge(string $status): string {
        $label = ucwords(str_replace('_', ' ', $status));
        return '<span class="badge badge-' . e($status) . '">' . e($label) . '</span>';
    }

    function day_name(int $n): string {
        $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
             5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        return $days[$n] ?? '';
    }

    function to_minutes(string $t): int {
        $parts = explode(':', $t);
        return ((int)$parts[0]) * 60 + (int)($parts[1] ?? 0);
    }

    function from_minutes(int $m): string {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    //Booking logic

    /**
     * Returns the free start times (H:i) for a staff member, service and date.
     * A time is free when the whole service fits inside the staff member's
     * working hours and does not overlap another non-cancelled appointment.
     * $excludeId lets an appointment ignore itself when being rescheduled.
     */

    function get_available_slots(PDO $pdo, int $staffId, int $serviceId, string $date, int $excludeId = 0): array {
        if (!valid_date($date)) {
            return [];
        }
        $st = $pdo->prepare('SELECT duration_minutes FROM services WHERE service_id = ? AND is_active = 1');
        $st->execute([$serviceId]);
        $duration = $st->fetchColumn();
    
        if ($duration === false) {
            return [];
        }
        $duration = (int)$duration;

        $dow = (int)date('N', strtotime($date));
        $st = $pdo->prepare('SELECT start_time, end_time FROM staff_schedules WHERE staff_id = ? AND day_of_week = ?');
        $st->execute([$staffId, $dow]);
        $schedule = $st->fetch();
        if (!$schedule) {
            return [];   // staff member does not work that day
        }

        $st = $pdo->prepare(
            "SELECT start_time, end_time FROM appointments
            WHERE staff_id = ? AND appointment_date = ? AND status <> 'cancelled' AND appointment_id <> ?"
        );
        $st->execute([$staffId, $date, $excludeId]);
        $booked = $st->fetchAll();

        $nowMinutes = ($date === date('Y-m-d')) ? to_minutes(date('H:i')) : -1;
        $open  = to_minutes($schedule['start_time']);
        $close = to_minutes($schedule['end_time']);
        $slots = [];

        for ($t = $open; $t + $duration <= $close; $t += SLOT_STEP) {
            if ($t <= $nowMinutes) {
                continue;
            }
            $free = true;
            foreach ($booked as $b) {
                if ($t < to_minutes($b['end_time']) && ($t + $duration) > to_minutes($b['start_time'])) {
                    $free = false;
                    break;
                }
            }
            if ($free) {
                $slots[] = from_minutes($t);
            }
        }
        return $slots;
    }

    /** Creates an appointment after validating it. Returns [ok, message, id]. */
    function create_appointment(PDO $pdo, int $customerId, int $staffId, int $serviceId,
                                string $date, string $time, string $status = 'pending', ?string $notes = null): array
    {
        if ($customerId <= 0 || $staffId <= 0 || $serviceId <= 0) {
            return [false, 'Please select a customer, a service and a staff member.', 0];
        }
        if (!valid_date($date) || !valid_time($time)) {
            return [false, 'Please choose a valid date and time.', 0];
        }
        if ($date < date('Y-m-d')) {
            return [false, 'You cannot book a date in the past.', 0];
        }
        if ($date > date('Y-m-d', strtotime('+180 days'))) {
            return [false, 'Bookings can only be made up to 6 months ahead.', 0];
        }
        if ($notes !== null && mb_strlen($notes) > 255) {
            return [false, 'Notes may not be longer than 255 characters.', 0];
        }

        $st = $pdo->prepare('SELECT duration_minutes, price FROM services WHERE service_id = ? AND is_active = 1');
        $st->execute([$serviceId]);
        $service = $st->fetch();
        if (!$service) {
            return [false, 'The selected service is not available.', 0];
        }
        $st = $pdo->prepare('SELECT 1 FROM staff_services WHERE staff_id = ? AND service_id = ?');
        $st->execute([$staffId, $serviceId]);
        if (!$st->fetchColumn()) {
            return [false, 'That staff member does not perform the selected service.', 0];
        }

        try {
            $pdo->beginTransaction();
            if (!in_array($time, get_available_slots($pdo, $staffId, $serviceId, $date), true)) {
                $pdo->rollBack();
                return [false, 'Sorry, that time is not available. Please choose another slot.', 0];
            }
            $end = from_minutes(to_minutes($time) + (int)$service['duration_minutes']);
            $ins = $pdo->prepare(
                'INSERT INTO appointments
                (customer_id, staff_id, service_id, appointment_date, start_time, end_time, status, price_charged, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$customerId, $staffId, $serviceId, $date, $time, $end, $status, $service['price'],
                        ($notes === '' ? null : $notes)]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            return [true, 'Appointment booked successfully.', $id];
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                return [false, 'That slot has just been taken by someone else. Please choose another time.', 0];
            }
            error_log('create_appointment: ' . $ex->getMessage());
            return [false, 'A system error occurred. Please try again.', 0];
        }
    }

    /** Moves an existing appointment to a new date/time (and optionally staff member). */
    function reschedule_appointment(PDO $pdo, int $id, string $date, string $time, ?int $newStaffId = null): array
    {
        if (!valid_date($date) || !valid_time($time)) {
            return [false, 'Please choose a valid date and time.'];
        }
        if ($date < date('Y-m-d')) {
            return [false, 'You cannot move an appointment to a past date.'];
        }
        $st = $pdo->prepare(
            'SELECT a.staff_id, a.service_id, sv.duration_minutes
            FROM appointments a JOIN services sv ON sv.service_id = a.service_id
            WHERE a.appointment_id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return [false, 'Appointment not found.'];
        }
        $staffId = $newStaffId ?: (int)$row['staff_id'];
        $serviceId = (int)$row['service_id'];

        $st = $pdo->prepare('SELECT 1 FROM staff_services WHERE staff_id = ? AND service_id = ?');
        $st->execute([$staffId, $serviceId]);
        if (!$st->fetchColumn()) {
            return [false, 'That staff member does not perform this service.'];
        }

        try {
            $pdo->beginTransaction();
            if (!in_array($time, get_available_slots($pdo, $staffId, $serviceId, $date, $id), true)) {
                $pdo->rollBack();
                return [false, 'Sorry, that time is not available. Please choose another slot.'];
            }
            $end = from_minutes(to_minutes($time) + (int)$row['duration_minutes']);
            $up = $pdo->prepare(
                'UPDATE appointments SET staff_id = ?, appointment_date = ?, start_time = ?, end_time = ?
                WHERE appointment_id = ?'
            );
            $up->execute([$staffId, $date, $time, $end, $id]);
            $pdo->commit();
            return [true, 'Appointment moved successfully.'];
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                return [false, 'That slot has just been taken. Please choose another time.'];
            }
            error_log('reschedule_appointment: ' . $ex->getMessage());
            return [false, 'A system error occurred. Please try again.'];
        }
    }

    
    // Email notifications (logged in the notifications table)
    
    /**
     * Sends an email to the customer and records it in `notifications`.
     * mail() needs a configured mail server; if none exists the attempt is
     * still logged so the feature can be demonstrated on a local machine.
     */

    function send_notification(PDO $pdo, int $appointmentId, string $type): void
    {
        $st = $pdo->prepare(
            "SELECT a.appointment_date, a.start_time, sv.service_name, u.email,
                    c.first_name, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
            FROM appointments a
            JOIN customers c ON c.customer_id = a.customer_id
            JOIN users u ON u.user_id = c.user_id
            JOIN services sv ON sv.service_id = a.service_id
            JOIN staff s ON s.staff_id = a.staff_id
            WHERE a.appointment_id = ?"
        );
        $st->execute([$appointmentId]);
        $r = $st->fetch();
        if (!$r) {
            return;
        }
        $when = fmt_date($r['appointment_date']) . ' at ' . fmt_time($r['start_time']);
        $lines = [
            'confirmation' => ['GlowWell booking received', "We have received your booking for {$r['service_name']} with {$r['staff_name']} on $when. 
                    You will be notified once it is confirmed."], 'rescheduled'  => 
                    ['GlowWell booking changed',  "Your {$r['service_name']} appointment with {$r['staff_name']} is now on $when."],
            'cancellation' => ['GlowWell booking cancelled', "Your {$r['service_name']} appointment on $when has been cancelled."],
            'reminder'     => ['GlowWell appointment reminder', "Reminder: {$r['service_name']} with {$r['staff_name']} on $when."],
        ];
        [$subject, $text] = $lines[$type];
        $body = "Hi {$r['first_name']},\r\n\r\n$text\r\n\r\nGlowWell Beauty Clinic";
        @mail($r['email'], $subject, $body, "From: bookings@glowwell.co.za\r\nContent-Type: text/plain; charset=UTF-8");

        $log = $pdo->prepare('INSERT INTO notifications (appointment_id, type, sent_to) VALUES (?, ?, ?)');
        $log->execute([$appointmentId, $type, $r['email']]);
    }