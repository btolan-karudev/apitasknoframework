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
            $query = $readDb->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed  from tbltasks WHERE id =:taskId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
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
            $query = $writeDb->prepare('DELETE from tbltasks WHERE id = :taskId');
            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
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
                                        completed  from tbltasks WHERE completed =:completed');
            $query->bindParam(':completed', $completed, PDO::PARAM_INT);
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
            $query = $readDb->prepare('SELECT count(id) as totalNoOfTasks from tbltasks');
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
                                        completed  from tbltasks LIMIT :pglimit OFFSET :offset');
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
            $query = $readDb->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed  from tbltasks');
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

//        try {
//            $query = $writeDb->prepare('DELETE from tbltasks WHERE id = :taskId');
//            $query->bindParam(':taskId', $taskId, PDO::PARAM_INT);
//            $query->execute();
//
//            $rowCount = $query->rowCount();
//
//            if ($rowCount === 0) {
//                $response = new Response();
//                $response->setHttpStatusCode(404);
//                $response->setSuccess(false);
//                $response->addMessage("Task not Found!");
//                $response->send();
//
//                exit();
//            }
//            $response = new Response();
//            $response->setHttpStatusCode(200);
//            $response->setSuccess(true);
//            $response->addMessage("Task Created Succesfully!");
//            $response->send();
//
//            exit();
//        } catch (PDOException $exception) {
//            $response = new Response();
//            $response->setHttpStatusCode(500);
//            $response->setSuccess(false);
//            $response->addMessage("Failed to add new task!");
//            $response->send();
//
//            exit();
//        }
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