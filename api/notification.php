<?php
/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 2/12/16
 * Time: 7:06 PM
 */

require_once 'base.php';

function getNotification($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $query = $db->prepare('select * from viewNotification where noti_id = :noti_id');
        $query->bindParam(':noti_id', $args['id']);
        if($query->execute()) {
            $noti = $query->fetch(PDO::FETCH_NAMED);
            $newRes = makeResultResponse($res, 200, $noti);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function removeNotification($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $query = $db->prepare('delete from tblNotification where noti_id = :noti_id');
        $query->bindParam(':noti_id', $args['id']);
        if($query->execute()) {
            $newRes = makeResultResponse($res, 200, 'Removed notification');
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}