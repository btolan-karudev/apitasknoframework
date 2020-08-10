<?php

require_once('../service/Database.php');
require_once('../model/Response.php');

try {
    $writeDb = Database::connectWriteDb();
} catch (PDOException $exception) {
    error_log("Connection error - " . $exception, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error!");
    $response->send();
    exit();
}