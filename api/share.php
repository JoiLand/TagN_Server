<?php
/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 2/12/16
 * Time: 6:18 AM
 */

require_once 'base.php';

define('SHARE_REQUEST_ACCEPT',          1);
define('SHARE_REQUEST_DECLINE',         -1);

function shareTagWithGroup($req, $res) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $aryUserIds = explode(',', $params['share_to_user_ids']);
        $aryShareIds = [];

        foreach ($aryUserIds as $share_to_user_id) {
            $query = $db->prepare('insert into tblShare (share_from_user_id, share_to_user_id, share_tag_id, share_created_at)
                                    values (:share_from_user_id, :share_to_user_id, :share_tag_id, now())');
            $query->bindParam(':share_from_user_id', $params['share_from_user_id']);
            $query->bindParam(':share_to_user_id', $share_to_user_id);
            $query->bindParam(':share_tag_id', $params['share_tag_id']);
            if ($query->execute()) {
                $share_id = $db->lastInsertId();
                array_push($aryShareIds, $share_id);

                $message = $params['share_from_user_name'] . ' added you to ' . $params['share_tag_text'];
                //add notification
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_id, noti_share_tag_id, noti_string, noti_type, noti_created_at)
                                        values (:noti_from_user_id, :noti_to_user_id, :noti_share_id, :noti_share_tag_id, :noti_string, :noti_type, now())');

                $noti_type = TAGN_PUSH_SHARE_REQUEST;

                $query->bindParam(':noti_from_user_id', $params['share_from_user_id']);
                $query->bindParam(':noti_to_user_id', $share_to_user_id);
                $query->bindParam(':noti_share_id', $share_id);
                $query->bindParam(':noti_share_tag_id', $params['share_tag_id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);
                if ($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($params['share_to_user_id'], $message, $noti_id);
                } else {
                    continue;
                }
            } else {
                continue;
            }
        }

        $newRes = makeResultResponse($res, 200, $aryShareIds);
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function removeUserFromShareTag($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select * from tblShare where share_id = :share_id');
        $query->bindParam(':share_id', $args['id']);
        if($query->execute()) {
            $share = $query->fetch(PDO::FETCH_NAMED);

            $query = $db->prepare('delete from tblShare where share_id = :share_id');
            $query->bindParam(':share_id', $args['id']);
            if ($query->execute()) {

                $query = $db->prepare('delete from tblNotification where noti_from_user_id = :noti_from_user_id and noti_to_user_id = :noti_to_user_id and noti_share_id = :noti_share_id');
                $query->bindParam(':noti_from_user_id', $params['sender_id']);
                $query->bindParam(':noti_to_user_id', $params['receiver_id']);
                $query->bindParam(':noti_share_id', $args['id']);
                if($query->execute()) {
                    $message = $params['sender_user_name'] . ' removed you from ' . $params['tag_text'];
                    //add notification
                    $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_string, noti_type, noti_created_at)
                                            values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_string, :noti_type, now())');

                    $noti_type = TAGN_PUSH_UNSHARE_REQUEST;

                    $query->bindParam(':noti_from_user_id', $params['sender_id']);
                    $query->bindParam(':noti_to_user_id', $params['receiver_id']);
                    $query->bindParam(':noti_share_tag_id', $share['share_tag_id']);
                    $query->bindParam(':noti_string', $message);
                    $query->bindParam(':noti_type', $noti_type);

                    if ($query->execute()) {
                        $noti_id = $db->lastInsertId();
                        sendNotification($params['receiver_id'], $message, $noti_id);
                        $newRes = makeResultResponse($res, 200, 'Sent your request successfully');
                    } else {
                        $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                    }
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }

            } else {
                $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }

    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function responseShare($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        if ($params['share_status'] == SHARE_REQUEST_ACCEPT) {
            $query = $db->prepare('update tblShare set share_status = :share_status where share_id = :share_id');
            $query->bindParam(':share_id', $args['id']);
            $query->bindParam(':share_status', $params['share_status']);
        } else {
            $query = $db->prepare('delete from tblShare where share_id = :share_id');
            $query->bindParam(':share_id', $args['id']);
        }
        if($query->execute()) {

            $query = $db->prepare('select * from viewShare where share_id = :share_id');
            $query->bindParam(':share_id', $args['id']);
            if($query->execute()) {
                $share = $query->fetch(PDO::FETCH_NAMED);

                if($params['share_status'] == SHARE_REQUEST_ACCEPT) {
                    $noti_type = TAGN_PUSH_ACCEPT_SHARE;
                    $message = $params['sender_user_name'].' was added to tag '.$share['share_tag_text'];
                } else {
                    $noti_type = TAGN_PUSH_REJECT_SHARE;
                    $message = $params['sender_user_name'].' denied sharing '.$share['share_tag_text'];
                }

                //add notification
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_string, noti_type, noti_created_at)
                                    values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_string, :noti_type, now())');

                $query->bindParam(':noti_from_user_id', $share['share_to_user_id']);
                $query->bindParam(':noti_to_user_id', $share['share_from_user_id']);
                $query->bindParam(':noti_share_tag_id', $share['share_tag_id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);

                if($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($share['share_from_user_id'], $message, $noti_id);
                    $newRes = makeResultResponse($res, 200, 'Sent your response successfully');
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }
            } else {
                $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }

    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}