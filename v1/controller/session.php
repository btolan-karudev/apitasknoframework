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

if (array_key_exists("sessionid", $_GET)) {
    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    }
} elseif (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();
        exit();
    }

    sleep(2);

    if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Content type header is not set to JSON");
        $response->send();
        exit();
    }

    $rawPostData = file_get_contents('php://input');

    if (!$jsonData = json_decode($rawPostData)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Request body is not VALID json");
        $response->send();

        exit();
    }

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        !isset($jsonData->username) ? $response->addMessage("Username not supplied!") : false;
        !isset($jsonData->password) ? $response->addMessage("Password not supplied!") : false;
        $response->send();

        exit();
    }

    if ((strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255) ||
        (strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        (strlen($jsonData->username) < 1) ? $response->addMessage("Username cannot be blank !") : false;
        (strlen($jsonData->username) > 255) ? $response->addMessage("Username field cannot be greater than 255 characters!") : false;
        (strlen($jsonData->password) < 1) ? $response->addMessage("Password field cannot be blank!") : false;
        (strlen($jsonData->password) > 255) ? $response->addMessage("Password field cannot be greater than 255 characters!") : false;
        $response->send();

        exit();
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $query = $writeDb->prepare('SELECT id, fullname, username, password, seractive, loginattempts from
                         tblusers WHERE username =:username');
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or password is incorrect!");
            $response->send();

            exit();
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_id = $row['id'];
        $returned_fullname = $row['fullname'];
        $returned_username = $row['username'];
        $returned_password = $row['password'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];

        if ($returned_useractive !== 'Y') {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account not active!");
            $response->send();

            exit();
        }

        if ($returned_loginattempts >= 3) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account is currently locked out!");
            $response->send();

            exit();
        }


    } catch (PDOException $exception) {
        error_log("Database query error - " . $exception, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue login in!");
        $response->send();

        exit();
    }


} else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found!");
    $response->send();
    exit();
}