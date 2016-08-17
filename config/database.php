<?php
    
    // --- Database --- ;
    require_once('configure.php');
   	$db = new PDO('mysql:host='.DB_SERVER.' ;dbname='.DB_DATABASE.';charset=utf8mb4', DB_SERVER_USERNAME, DB_SERVER_PASSWORD);

