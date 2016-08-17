<?php
/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 2/9/16
 * Time: 2:23 PM
 */

require_once 'base.php';

function makeImage($req, $res) {

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();
        $image_id = $params['image_id'];
        if($image_id > 0) { //edit
            $newRes = updateImage($req, $res);
        } else {
            $newRes = uploadImage($req, $res);
        }

    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function uploadImage($req, $res) {
    global $db;

    $params = $req->getParams();
    $files = $req->getUploadedFiles();
    if (isset($files['image'])) {
        saveImageToS3(IMAGE_TAGN_BUCKET_NAME, $files['image']->file, $params['image_url']);
        saveThumbToS3(THUMB_TAGN_BUCKET_NAME, getThumbImage($files['image']->file), $params['image_thumb_url']);
    }

    $query =$db->prepare('insert into tblImage (image_user_id, image_tag_id, image_url, image_thumb_url, image_width, image_height, image_created_at)
                          values
                          (:image_user_id, :image_tag_id, :image_url, :image_thumb_url, :image_width, :image_height, now())');
    $query->bindParam(':image_user_id', $params['image_user_id']);
    $query->bindParam(':image_tag_id', $params['image_tag_id']);
    $query->bindParam(':image_url', $params['image_url']);
    $query->bindParam(':image_thumb_url', $params['image_thumb_url']);
    $query->bindParam(':image_width', $params['image_width']);
    $query->bindParam(':image_height', $params['image_height']);

    if($query->execute()) {

        $image_id = $db->lastInsertId();

        $query = $db->prepare('select * from viewImage where image_id = :image_id');
        $query->bindParam(':image_id', $image_id);
        if($query->execute()) {
            $image = $query->fetch(PDO::FETCH_NAMED);

            $message = $image['image_user_name'].' added a photo to '.$image['tag_text'];
            $noti_type = TAGN_PUSH_UPLOAD_PHOTO;

            $share_users = getShareUsersFromTagId($image['image_tag_id'], $image['image_user_id']);

            foreach($share_users as $share_user) {
                //add notification
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_share_image_id, noti_string, noti_type, noti_created_at)
                                        values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_share_image_id, :noti_string, :noti_type, now())');

                $query->bindParam(':noti_from_user_id', $image['image_user_id']);
                $query->bindParam(':noti_to_user_id', $share_user);
                $query->bindParam(':noti_share_tag_id', $image['image_tag_id']);
                $query->bindParam(':noti_share_image_id', $image['image_id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);

                if ($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($share_user, $message, $noti_id);
                }
            }

            $newRes = makeResultResponse($res, 200, $image_id);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
    }

    return $newRes;
}

function updateImage($req, $res) {
    global $db;

    $params = $req->getParams();
    $files = $req->getUploadedFiles();

    $query = $db->prepare('select * from viewImage where image_id = :image_id');
    $query->bindParam(':image_id', $params['image_id']);
    if($query->execute()) {
        $image = $query->fetch(PDO::FETCH_NAMED);

        deleteImageFromS3(IMAGE_TAGN_BUCKET_NAME, $image['image_url']);
        deleteImageFromS3(THUMB_TAGN_BUCKET_NAME, $image['image_thumb_url']);

        if (isset($files['image'])) {

            saveImageToS3(IMAGE_TAGN_BUCKET_NAME, $files['image']->file, $params['image_url']);
            saveThumbToS3(THUMB_TAGN_BUCKET_NAME, getThumbImage($files['image']->file), $params['image_thumb_url']);
        }

        $query = $db->prepare('update tblImage set image_url = :image_url,
                                                    image_thumb_url = :image_thumb_url,
                                                    image_width = :image_width,
                                                    image_height = :image_height,
                                                    image_created_at = now()
                               where image_id = :image_id');

        $query->bindParam(':image_url', $params['image_url']);
        $query->bindParam(':image_thumb_url', $params['image_thumb_url']);
        $query->bindParam(':image_width', $params['image_width']);
        $query->bindParam(':image_height', $params['image_height']);
        $query->bindParam(':image_id', $params['image_id']);

        if($query->execute()) {

            $message = $image['image_user_name'] . ' updated a photo on ' . $image['tag_text'];
            $noti_type = TAGN_PUSH_UPLOAD_PHOTO;

            $share_users = getShareUsersFromTagId($image['image_tag_id'], $image['image_user_id']);

            foreach ($share_users as $share_user) {
                //add notification
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_share_image_id, noti_string, noti_type, noti_created_at)
                                    values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_share_image_id, :noti_string, :noti_type, now())');

                $query->bindParam(':noti_from_user_id', $image['image_user_id']);
                $query->bindParam(':noti_to_user_id', $share_user);
                $query->bindParam(':noti_share_tag_id', $image['image_tag_id']);
                $query->bindParam(':noti_share_image_id', $image['image_id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);

                if ($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($share_user, $message, $noti_id);
                }
            }

            $newRes = makeResultResponse($res, 200, $params['image_id']);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
    }

    return $newRes;
}

function deleteImages($req, $res) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        //remove s3 objects
        $sql = 'select * from viewImage where image_id in ('.$params["image_ids"].')';
        $query = $db->prepare($sql);

        if($query->execute()) {
            $images = $query->fetchAll(PDO::FETCH_ASSOC);
            foreach($images as $image) {
                deleteImageFromS3(IMAGE_TAGN_BUCKET_NAME, $image['image_url']);
                deleteImageFromS3(THUMB_TAGN_BUCKET_NAME, $image['image_thumb_url']);
            }

            $sql = 'delete from tblImage where image_id in ('.$params["image_ids"].')';
            $query = $db->prepare($sql);
            if($query->execute()) {

                $message = $params['image_user_name'].' deleted a photo from '.$images[0]['tag_text'];
                $noti_type = TAGN_PUSH_REMOVE_PHOTO;

                $share_users = getShareUsersFromTagId($images[0]['image_tag_id'], $params['image_user_id']);

                foreach($share_users as $share_user) {
                    //add notification
                    $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_tag_id, noti_string, noti_type, noti_created_at)
                                            values (:noti_from_user_id, :noti_to_user_id, :noti_share_tag_id, :noti_string, :noti_type, now())');

                    $query->bindParam(':noti_from_user_id', $params['image_user_id']);
                    $query->bindParam(':noti_to_user_id', $share_user);
                    $query->bindParam(':noti_share_tag_id', $images[0]['image_tag_id']);
                    $query->bindParam(':noti_string', $message);
                    $query->bindParam(':noti_type', $noti_type);

                    if ($query->execute()) {
                        $noti_id = $db->lastInsertId();
                        sendNotification($share_user, $message, $noti_id);
                    }
                }

                $newRes = makeResultResponse($res, 200, 'Removed images');

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

function getImageWithId($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select *, convert_tz(image_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                from viewImage where image_id = :image_id');
        $query->bindParam(':image_id', $args['id']);
        $query->bindParam(':user_id', $params['user_id']);

        if ($query->execute()) {

            $image = $query->fetch(PDO::FETCH_NAMED);

            if($image) {
                //image_is_like
                $query = $db->prepare('select * from tblImageLike where like_user_id = :user_id and like_image_id = :image_id');
                $query->bindParam(':user_id', $params['user_id']);
                $query->bindParam(':image_id', $image['image_id']);
                $query->execute();
                $likes = $query->fetchAll(PDO::FETCH_ASSOC);

                if (count($likes) > 0) {
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

                $newRes = makeResultResponse($res, 200, $image);
            } else {
                $newRes = makeResultResponse($res, 403, 'This image is not exist');
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function likeImage($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        if($params['is_like'] == true) {
            $query = $db->prepare('insert into tblImageLike (like_user_id, like_image_id, like_created_at)
                                    values (:user_id, :image_id, now())');
        } else {
            $query = $db->prepare('delete from tblImageLike where like_user_id = :user_id and like_image_id = :image_id');
        }
        $query->bindParam(':user_id', $params['user_id']);
        $query->bindParam(':image_id', $args['id']);

        if($query->execute()) {

            if($params['is_like'] == true) {
                $message = $params['user_name'] . ' liked a photo';
                $noti_type = TAGN_PUSH_LIKED_IMAGE;
            } else {
                $message = $params['user_name'] . ' disliked a photo';
                $noti_type = TAGN_PUSH_DISLIKED_IMAGE;
            }

            $share_users = getShareUsersFromTagId($params['image_tag_id'], $params['user_id']);
            foreach($share_users as $share_user) {
                $query = $db->prepare('insert into tblNotification (noti_from_user_id, noti_to_user_id, noti_share_image_id, noti_string, noti_type, noti_created_at)
                                        values (:noti_from_user_id, :noti_to_user_id, :noti_share_image_id, :noti_string, :noti_type, now())');

                $query->bindParam(':noti_from_user_id', $params['user_id']);
                $query->bindParam(':noti_to_user_id', $share_user);
                $query->bindParam(':noti_share_image_id', $args['id']);
                $query->bindParam(':noti_string', $message);
                $query->bindParam(':noti_type', $noti_type);

                if($query->execute()) {
                    $noti_id = $db->lastInsertId();
                    sendNotification($share_user, $message, $noti_id);
                }
            }

            $newRes = makeResultResponse($res, 200, 'update image like status');
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function getImageComments($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select *, convert_tz(comment_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                from viewComment where comment_image_id = :image_id order by user_created_at desc');

        $query->bindParam(':image_id', $args['id']);
        $query->bindParam(':user_id', $params['user_id']);

        if($query->execute()) {
            $comments = $query->fetchAll(PDO::FETCH_ASSOC);
            $newRes = makeResultResponse($res, 200, $comments);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function getImageLikers($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select *, convert_tz(like_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                from viewImageLike where like_image_id = :image_id order by user_created_at desc');

        $query->bindParam(':image_id', $args['id']);
        $query->bindParam(':user_id', $params['user_id']);

        if($query->execute()) {
            $likers = $query->fetchAll(PDO::FETCH_ASSOC);
            $newRes = makeResultResponse($res, 200, $likers);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function getThumbImage($filename)
{
    list($width, $height) = getimagesize($filename);

    $x_ratio = THUMB_IMAGE_WIDTH / $width;
    $y_ratio = THUMB_IMAGE_HEIGHT / $height;

    if( ($width <= THUMB_IMAGE_WIDTH) && ($height <= THUMB_IMAGE_HEIGHT) ){
        $tn_width = $width;
        $tn_height = $height;
    }elseif (($x_ratio * $height) < THUMB_IMAGE_HEIGHT){
        $tn_height = ceil($x_ratio * $height);
        $tn_width = THUMB_IMAGE_WIDTH;
    }else{
        $tn_width = ceil($y_ratio * $width);
        $tn_height = THUMB_IMAGE_HEIGHT;
    }

    $image_p = imagecreatetruecolor($tn_width, $tn_height);

    $image = imagecreatefromjpeg($filename);

    imagecopyresampled($image_p, $image, 0, 0, 0, 0, $tn_width, $tn_height, $width, $height);

    ob_start();

    imagejpeg($image_p);

    $final_image = ob_get_contents();

    ob_end_clean();

    return $final_image;

}
