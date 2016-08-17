<?php
/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 2/8/16
 * Time: 8:37 PM
 */

require_once 'base.php';

function addTag($req, $res) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();
        $query = $db->prepare('insert into tblTag (tag_user_id, tag_text) values (:tag_user_id, :tag_text)');
        $query->bindParam(':tag_user_id', $params['tag_user_id']);
        $query->bindParam(':tag_text', $params['tag_text']);

        if($query->execute()) {
            $tag_id = $db->lastInsertId();

            $newRes = makeResultResponse($res, 200, $tag_id);
        } else {
            $newRes = makeResultResponse($res, 400, 'Bad Request');
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }
    return $newRes;
}

function deleteTag($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        makeNotification($args['id'], $params['user_id']);

        //remove shares from tblShare
        $query = $db->prepare('delete from tblShare where share_tag_id = :tag_id');
        $query->bindParam(':tag_id', $args['id']);
        if($query->execute()) {

            //remove tag from tblTag
            $query = $db->prepare('delete from tblTag where tag_id = :tag_id');
            $query->bindParam(':tag_id', $args['id']);
            if ($query->execute()) {
                //remove s3 objects
                $query = $db->prepare('select * from tblImage where image_tag_id = :tag_id');
                $query->bindParam(':tag_id', $args['id']);
                if ($query->execute()) {
                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($images as $image) {
                        deleteImageFromS3(IMAGE_TAGN_BUCKET_NAME, $image['image_url']);
                        deleteImageFromS3(THUMB_TAGN_BUCKET_NAME, $image['image_thumb_url']);
                    }

                    //remove images from tblImage
                    $query = $db->prepare('delete from tblImage where image_tag_id = :tag_id');
                    $query->bindParam(':tag_id', $args['id']);
                    if ($query->execute()) {
                        //send notification to sharing users
                        $newRes = makeResultResponse($res, 200, 'Removed Tag');
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

function makeNotification($tag_id, $user_id) {
    global $db;

    $query = $db->prepare('select share.share_to_user_id as receiver_id, user.user_name as sender_name, share.share_tag_text as tag_text
                            from viewShare as share
                            inner join tblUser as user
                            on share.share_from_user_id = user.user_id
                            where share.share_from_user_id = :user_id and share.share_tag_id = :tag_id and share.share_status = 1');
    $query->bindParam(':user_id', $user_id);
    $query->bindParam(':tag_id', $tag_id);

    if($query->execute()) {
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row) {
            $message = $row['sender_name'] . ' deleted ' . $row['tag_text'];
            //add notification
            $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_string, noti_type, noti_created_at)
                                    values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_string, :noti_type, now())');

            $noti_type = TAGN_PUSH_REMOVE_SHARE_TAG;

            $query->bindParam(':noti_from_user_id', $user_id);
            $query->bindParam(':noti_to_user_id', $row['receiver_id']);
            $query->bindParam(':noti_share_tag_id', $tag_id);
            $query->bindParam(':noti_string', $message);
            $query->bindParam(':noti_type', $noti_type);

            if ($query->execute()) {
                $noti_id = $db->lastInsertId();
                sendNotification($row['receiver_id'], $message, $noti_id);
            }
        }
    }
}

function getTagImages($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select *, convert_tz(image_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                from viewImage where image_tag_id = :tag_id order by user_created_at desc');
        $query->bindParam(':tag_id', $args['id']);
        $query->bindParam(':user_id', $params['user_id']);
        if($query->execute()) {
            $images = $query->fetchAll(PDO::FETCH_ASSOC);

            $share_images = @[];
            foreach($images as $image) {
                //image_is_like
                $query = $db->prepare('select * from tblImageLike where like_user_id = :user_id and like_image_id = :image_id');
                $query->bindParam(':user_id', $params['user_id']);
                $query->bindParam(':image_id', $image['image_id']);
                $query->execute();
                $likes = $query->fetchAll(PDO::FETCH_ASSOC);

                if(count($likes) > 0) {
                    $image['image_is_like'] = true;
                } else {
                    $image['image_is_like'] = false;
                }

                //image_last_2_comments
                $query = $db->prepare('select *, convert_tz(comment_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                        from viewComment where comment_image_id = :image_id order by comment_created_at desc limit 2');
                $query->bindParam(':user_id', $params['user_id']);
                $query->bindParam(':image_id', $image['image_id']);
                $query->execute();

                $comments = $query->fetchAll(PDO::FETCH_ASSOC);
                $image['image_last_2_comments'] = $comments;

                array_push($share_images, $image);
            }

            $newRes = makeResultResponse($res, 200, $share_images);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }

    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}