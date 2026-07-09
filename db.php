<?php
// Bangladesh Timezone (UTC+6)
date_default_timezone_set('Asia/Dhaka');

// ডেটাবেসের তথ্য
$servername = "localhost";
$username   = "bdfollow_arafaty";
$password   = "bdfollow_arafaty";
$dbname     = "bdfollow_arafaty";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+06:00'"); // Bangladesh Standard Time

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]));
}

// Session শুরু করা (যদি না থাকে)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper: JSON Response
function jsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Helper: Sanitize Input
function clean($conn, $value) {
    return $conn->real_escape_string(trim($value));
}
?>
