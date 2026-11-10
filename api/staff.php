<?php
    require_once __DIR__ . '/../includes/functions.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/json');
    
    function json_out(int $code, array $data): void {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

 
    if (!is_logged_in()) {
        json_out(401, ['error' => 'Please log in.', 'staff' => []]);
    }
    session_write_close();   // read-only endpoint: release the session lock

    $serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$serviceId) {
        json_out(400, ['error' => 'Invalid service.', 'staff' => []]);
    }

    try {
        $st = db()->prepare(
            "SELECT s.staff_id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.job_title
            FROM staff s
            JOIN staff_services ss ON ss.staff_id = s.staff_id
            JOIN services sv ON sv.service_id = ss.service_id
            JOIN users u ON u.user_id = s.user_id
            WHERE ss.service_id = ? AND sv.is_active = 1 AND u.is_active = 1
            AND EXISTS (SELECT 1 FROM staff_schedules sc WHERE sc.staff_id = s.staff_id)
            ORDER BY s.first_name, s.last_name"
        );
        $st->execute([$serviceId]);
        json_out(200, ['staff' => $st->fetchAll()]);
    } catch (PDOException $ex) {
        error_log('api/staff: ' . $ex->getMessage());
        json_out(500, ['error' => 'Could not load staff. Please try again.', 'staff' => []]);
    }

    // $serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    // if (!$serviceId) {
    //     http_response_code(400);
    //     echo json_encode(['error' => 'Invalid service.', 'staff' => []]);
    //     exit;
    // }
    // $st = db()->prepare(
    //     "SELECT s.staff_id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.job_title
    //     FROM staff s
    //     JOIN staff_services ss ON ss.staff_id = s.staff_id
    //     JOIN users u ON u.user_id = s.user_id
    //     WHERE ss.service_id = ? AND u.is_active = 1
    //     ORDER BY s.first_name"
    // );
    // $st->execute([$serviceId]);
    // echo json_encode(['staff' => $st->fetchAll()]);
?>