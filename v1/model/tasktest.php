<?php

require_once('Task.php');

try {

    $task = new Task(10, 'Title here', 'Description here', '01/12/2020 12:00', 'YES');

    header('Content-Type: application/json;charset=UTF-8');

    echo json_encode($task->returnTaskAsArray());

} catch (TaskException $exception) {

    echo "Error: " . $exception->getMessage();

}