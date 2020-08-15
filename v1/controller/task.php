<?php

require_once('../service/Database.php');
require_once('../model/Task.php');
require_once('../model/Response.php');

try {
    $writeDb = Database::connectWriteDb();
    $readDb = Database::connectReadDb();
} catch (PDOException $exception) {
    error_log("Connection error - " . $exception, 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection Error!");
    $response->send();
    exit();
}

//begin auth script

if ((!isset($_SERVER['HTTP_AUTHORIZATION'])) || (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1)) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access Token is missing from the header!") : false);
    (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access Token cannot be blank!") : false);
    $response->send();
    exit();
}

$accessToken = $_SERVER['HTTP_AUTHORIZATION'];

try {
    $query = $writeDb->prepare('SELECT userid, accestokenexpiry, useractive, loginattempts 
                                        FROM tblsessions, tblusers
                                        WHERE tblsessions.userid = tblusers.id AND accestoken =:accesToken');
    $query->bindParam(':accesToken', $accessToken, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if ($rowCount === 0) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Invalid access token provided!");
        $response->send();
        exit();
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];
    $returned_accestokenexpiry = $row['accestokenexpiry'];


    if ($returned_useractive !== 'Y') {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is not active!");
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

    if (strtotime($returned_accestokenexpiry) < time()) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Token has expired - please log in again!");
        $response->send();
        exit();
    }
} catch (PDOException $exception) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue authenticating - Please log in again!");
    $response->send();
    exit();
}

//end auth script

if (array_key_exists("taskId", $_GET)) {

    $taskId = $_GET['taskId'];

    if ($taskId == '' || !is_numeric($taskId)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task ID must be numeric and cannot be blank!");
        $response->send();
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, title, description,
            DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
            completed from tbltasks WHERE id =:taskId AND userid =:userId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();

            exit();

        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task!");
            $response->send();
            exit();
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

        try {
            $query = $writeDb->prepare('DELETE from tbltasks WHERE id = :taskId AND userid =:userId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not Found!");
                $response->send();
                exit();

            }
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task Deleted!");
            $response->send();
            exit();

        } catch (PDOException $exception) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete task!");
            $response->send();

            exit();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

        try {
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to json");
                $response->send();
                exit();
            }

            $rawPatchData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPatchData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not VALID json");
                $response->send();
                exit();
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->deadline)) {
                $deadline_updated = true;
                $queryFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), ";
            }

            if (isset($jsonData->completed)) {
                $completed_updated = true;
                $queryFields .= "completed = :completed, ";
            }

            $queryFields = rtrim($queryFields, ", ");

            if ($title_updated === false && $description_updated === false &&
                $deadline_updated === false && $completed_updated === false) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No task field provided to change!");
                $response->send();
                exit();
            }

            $query = $writeDb->prepare('SELECT id, title, description,
                    DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                    completed  from tbltasks WHERE id =:taskId AND userid =:userId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Update Task not Found!");
                $response->send();

                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
            }

            $queryString = "UPDATE tbltasks SET " . $queryFields . " WHERE id = :taskId AND userid =:userId";
            $query = $writeDb->prepare($queryString);

            if ($title_updated === true) {
                $task->setTitle($jsonData->title);
                $up_title = $task->getTitle();
                $query->bindParam(":title", $up_title, PDO::PARAM_STR);
            }

            if ($description_updated === true) {
                $task->setDescription($jsonData->description);
                $up_description = $task->getDescription();
                $query->bindParam(":description", $up_description, PDO::PARAM_STR);
            }

            if ($deadline_updated === true) {
                $task->setDeadline($jsonData->deadline);
                $up_deadline = $task->getDeadline();
                $query->bindParam(":deadline", $up_deadline, PDO::PARAM_STR);
            }

            if ($completed_updated === true) {
                $task->setCompleted($jsonData->completed);
                $up_completed = $task->getCompleted();
                $query->bindParam(":completed", $up_completed, PDO::PARAM_STR);
            }

            $query->bindParam(":taskId", $taskId, PDO::PARAM_INT);
            $query->bindParam(":userID", $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to update task!");
                $response->send();

                exit();
            }

            $query = $writeDb->prepare('SELECT id, title, description,
                    DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                     completed  from tbltasks WHERE id =:taskId AND userid =:userId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not Found after update!");
                $response->send();

                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task updated successfully");
            $response->setData($returnData);
            $response->send();

            exit();

        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();

            exit();

        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update task into database - check submitted data for errors!");
            $response->send();

            exit();
        }


    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();

        exit();
    }

} elseif (array_key_exists("completed", $_GET)) {

    $completed = $_GET['completed'];

    if ($completed !== 'YES' && $completed !== 'NO') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Completed must be 'YES' or 'NO'");
        $response->send();
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                                        completed  from tbltasks WHERE completed =:completed AND userid =:userId');
            $query->bindParam(':completed', $completed, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task!");
            $response->send();
            exit();
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();
        exit();
    }
} elseif (array_key_exists("page", $_GET)) {

    $page = $_GET['page'];

    if ($page == '' || !is_numeric($page)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Page must be numeric and cannot be blank!");
        $response->send();
        exit();
    }

    $limitPerPage = 5;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT count(id) as totalNoOfTasks from tbltasks WHERE userid =:userId');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $tasksCount = intval($row['totalNoOfTasks']);

            $numOfPages = ceil($tasksCount / $limitPerPage);

            if ($numOfPages == 0) {
                $numOfPages = 1;
            }

            if ($page > $numOfPages) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Page Not Found!");
                $response->send();
                exit();
            }

            $offset = ($page == 1 ? 0 : ($limitPerPage * ($page - 1)));

            $query = $readDb->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                                        completed  from tbltasks WHERE userid =:userId
                                        LIMIT :pglimit OFFSET :offset');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pglimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam(':offset', $offset, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }

            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfPages;
            ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
            ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Tasks!");
            $response->send();
            exit();
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
        $response->send();
        exit();
    }
} elseif (empty($_GET)) {

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        try {
            $query = $readDb->prepare('SELECT id, title, description,
                                DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                                completed  from tbltasks WHERE userid =:userId');
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Tasks not Found!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Tasks!");
            $response->send();
            exit();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

        try {
            if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] != 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to json");
                $response->send();
                exit();
            }

            $rawPOSTData = file_get_contents('php://input');

            if (!$jsonData = json_decode($rawPOSTData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not VALID json");
                $response->send();
                exit();
            }

            if (!isset($jsonData->title) || !isset($jsonData->completed)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                !isset($jsonData->title) ? $response->addMessage("Title field is mandatory and must be provided!") : false;
                !isset($jsonData->completed) ? $response->addMessage("Completed field is mandatory and must be provided!") : false;
                $response->send();
                exit();
            }

            $newTask = new Task(null, $jsonData->title,
                (isset($jsonData->description) ? $jsonData->description : null),
                (isset($jsonData->deadline) ? $jsonData->deadline : null),
                $jsonData->completed);

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getCompleted();

            $query = $writeDb->prepare("INSERT INTO tbltasks
                    (title, description, deadline, completed, userid)
                    VALUES (:title, :description, STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'), :completed, :userId)");
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Failed to create task!");
                $response->send();
                exit();
            }

            $lastTaskID = $writeDb->lastInsertId();

            $query = $writeDb->prepare('SELECT id, title, description,
                        DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline,
                        completed  from tbltasks WHERE id =:lastTaskId AND userid = :userId');
            $query->bindParam(':lastTaskId', $lastTaskID, PDO::PARAM_INT);
            $query->bindParam(':userId', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve task after creation!");
                $response->send();
                exit();
            }

            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

                $tasksArray[] = $task->returnTaskAsArray();
            }
            $returnData = [];
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $tasksArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Task created successfully");
            $response->setData($returnData);
            $response->send();
            exit();
        } catch (TaskException $taskException) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($taskException->getMessage());
            $response->send();
            exit();
        } catch (PDOException $exception) {
            error_log("Database query error - " . $exception, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert task into database - check submitted data for errors!");
            $response->send();
            exit();
        }
    } else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed!");
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