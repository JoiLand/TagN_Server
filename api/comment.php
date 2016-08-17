<?php
/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 2/14/16
 * Time: 7:37 AM
 */

require_once 'base.php';

function addComment($req, $res) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('insert into tblComment (comment_user_id, comment_image_id, comment_string, comment_created_at)
                                values (:comment_user_id, :comment_image_id, :comment_string, now())');
        $query->bindParam(':comment_user_id', $params['comment_user_id']);
        $query->bindParam(':comment_image_id', $params['comment_image_id']);
        $query->bindParam(':comment_string', $params['comment_string']);

        if($query->execute()) {
            $query = $db->prepare('select *, convert_tz(comment_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                    from viewComment where comment_id = :comment_id');
            $query->bindParam(':comment_id', $db->lastInsertId());
            $query->bindParam(':user_id', $params['comment_user_id']);
            if($query->execute()) {
                $comment = $query->fetch(PDO::FETCH_NAMED);

                $message = $comment['comment_user_name'].' commented:'.$params['comment_string'];
                $noti_type = TAGN_PUSH_ADD_COMMENT;

                $share_users = getShareUsersFromTagId($comment['comment_tag_id'], $comment['comment_user_id']);
                foreach($share_users as $share_user) {
                    $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_image_id, noti_string, noti_type, noti_created_at)
                                            values (:noti_from_user_id, :noti_to_user_id, :noti_share_image_id, :noti_string, :noti_type, now())');

                    $query->bindParam(':noti_from_user_id', $comment['comment_user_id']);
                    $query->bindParam(':noti_to_user_id', $share_user);
                    $query->bindParam(':noti_share_image_id', $comment['comment_image_id']);
                    $query->bindParam(':noti_string', $message);
                    $query->bindParam(':noti_type', $noti_type);

                    if($query->execute()) {
                        $noti_id = $db->lastInsertId();
                        sendNotification($share_user, $message, $noti_id);
                    }
                }

                $newRes = makeResultResponse($res, 200, $comment);
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

function deleteComment($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $query = $db->prepare('select * from viewComment where comment_id = :comment_id');
        $query->bindParam(':comment_id', $args['id']);

        if($query->execute()) {
            $comment = $query->fetch(PDO::FETCH_NAMED);

            $message = $comment['comment_user_name'].' removed comment:'.$comment['commet_string'];
            $noti_type = TAGN_PUSH_REMOVE_COMMENT;

            $share_users = getShareUsersFromTagId($comment['comment_tag_id'], $comment['comment_user_id']);
            foreach($share_users as $share_user) {
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_image_id, noti_string, noti_type, noti_created_at)
                                    values (:noti_from_user_id, :noti_to_user_id, :noti_share_image_id, :noti_string, :noti_type, now())');

                $query->bindParam(':noti_from_user_id', $comment['comment_user_id']);
                $query->bindParam(':noti_to_user_id', $share_user);
                $query->bindParam(':noti_share_image_id', $comment['comment_image_id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);

                if($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($share_user, $message, $noti_id);
                }
            }

            $query = $db->prepare('delete from tblComment where comment_id = :comment_id');
            $query->bindParam(':comment_id', $args['id']);
            if($query->execute()){
                $newRes = makeResultResponse($res, 200, 'Removed your comment');
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