<?php
    require_once __DIR__ . '/../includes/functions.php';
    header('Content-Type: application/json');
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'Please log in.', 'staff' => []]);
        exit;
    }
    $serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    if (!$serviceId) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid service.', 'staff' => []]);
        exit;
    }
    $st = db()->prepare(
        "SELECT s.staff_id, CONCAT(s.first_name, ' ', s.last_name) AS name, s.job_title
        FROM staff s
        JOIN staff_services ss ON ss.staff_id = s.staff_id
        JOIN users u ON u.user_id = s.user_id
        WHERE ss.service_id = ? AND u.is_active = 1
        ORDER BY s.first_name"
    );
    $st->execute([$serviceId]);
    echo json_encode(['staff' => $st->fetchAll()]);
?>