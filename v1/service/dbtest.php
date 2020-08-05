<?php

require_once('Database.php');
require_once('../model/Response.php');

try {
    $writeDb = Database::connectWriteDb();
    $readDb = Database::connectReadDb();
} catch (PDOException $exception) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error!");
    $response->send();

    exit();
}


