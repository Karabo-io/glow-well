<?php
    require_once __DIR__ . '/includes/functions.php';

    if(is_logged_in()) {
        redirect(home_for_role());
    }
    $pdo = db();
    $errors = [];
    $old = ["first_name" => "", "last_name" => "", "email" => "", "phone" => "", "date_of_birth" => ""];

    if($_SERVER['REQUEST_METHOD'] === "POST") {
        csrf_check();
        foreach($old as $k => $_){
            $old[$k] = post($k);
        }

        $password = $_POST['password'] ?? "";
        $confirm = $_POST['confirm_password'] ?? "";
        $old["email"] = strtolower($old["email"]);

        if(!valid_name($old["first_name"])) { $errors[] = 'Please enter a valid first name (letters only).'; }
        if(!valid_name($old["last_name"])) {$errors[] =  'Please enter a valid last name (letters only).'; }
        if (!valid_email($old["email"]))     { $errors[] = 'Please enter a valid email address.'; }
        if (!valid_phone($old["-phone"]))     { $errors[] = 'Please enter a valid phone number (9-15 digits).'; }
        if($old["date_of_birth"] !==  "" && (!valid_date($old["date_of_birth"]) || $old["date_of_birth"] > date('Y-m-d'))) {
            $errors[] = 'Please enter a valid date of birth.' ;
        }
        if(!valid_password($password)) {$errors[] = 'Password must be at least 8 characters and contain a letter and a number.'; }
        if($password !== $confirm) {$errors[] = 'The two passowrds do not match.'; }

        if(!$errors) {
            $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
            $st->execute([$old['email']]);
            if($st->fetchColumn()) {
                $errors[] = 'An account with that email already exists. Try loggin in instead.';
            }
        }

    }
?>