<?php
/**
 * db_connect.php
 * This file contains the database connection logic.
 * Include this file in any PHP script that needs to access the database.
 */

$servername = "localhost";
$username = "root"; // Your default XAMPP username
$password = "";     // Your default XAMPP password
$dbname = "inventory_db"; // The name of your database

// Create and check the connection
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>