<?php
    require_once __DIR__ . '/../includes/functions.php';
    
    header('Content-type: application/json');
    if(!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['error' => 'Please log in.', 'slots' => []]);
        exit;
    }

    $staffId = filter_input(INPUT_GET, 'staff_id', FILTER_VALIDATE_INT);
    $serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);
    $date = (string)($_GET['date'] ?? "");
    $exclude = (int)($_GET['exclude'] ?? "");

    if(!staffId || !$serviceId || !valid_date($date)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters.', 'slots' => []]);
        exit;
    }

    if($date < date('Y-m-d')) {
        echo  json_encode(['slots' => []]);
        exit;
    }
    echo json_encode(['slots' => get_available_slots(db(), $staffId, $serviceId, $date, $exclude)]);
?>