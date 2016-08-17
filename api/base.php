<?php

/**
 * Created by PhpStorm.
 * User: jhpassion0621
 * Date: 11/16/14
 * Time: 9:34 AM
 */

//notification types

define('TAGN_PUSH_SHARE_REQUEST',       0);
define('TAGN_PUSH_ACCEPT_SHARE',        1);
define('TAGN_PUSH_REJECT_SHARE',        2);
define('TAGN_PUSH_UNSHARE_REQUEST',     3);
define('TAGN_PUSH_REMOVE_SHARE_TAG',    4);
define('TAGN_PUSH_UPLOAD_PHOTO',        5);
define('TAGN_PUSH_REMOVE_PHOTO',        6);
define('TAGN_PUSH_ADD_COMMENT',         7);
define('TAGN_PUSH_REMOVE_COMMENT',      8);
define('TAGN_PUSH_LIKED_IMAGE',         9);
define('TAGN_PUSH_DISLIKED_IMAGE',      10);

/**
 * @param $deviceToken
 * @param $msg
 * @param $badge
 * @return string
 */

function sendNotification($receiver_id, $msg, $noti_id) {

    global $db;
    $query = $db->prepare('select * from viewUser where user_id = :receiver_id');
    $query->bindParam(':receiver_id', $receiver_id);
    if($query->execute()) {
        $user = $query->fetch(PDO::FETCH_NAMED);

        $deviceToken = $user['user_device_token'];
        $badge = $user['user_noti_badges'];

        if (isset($deviceToken)) {
            sendNotificationToMobiles($deviceToken, $msg, $badge, $noti_id);
        }
    }
}

function sendNotificationToMobiles($deviceToken, $msg, $badge, $noti_id, $noti_type) {
    $data = array(
        'noti_id' => $noti_id
    );

    $fields = array(
        'app_id' => ONESIGNAL_APP_ID,
        'data' => $data,
        'include_player_ids' => array($deviceToken),
        'ios_badgeType' => 'SetTo',
        'ios_badgeCount' => (int) $badge,
        'contents' => array("en" => $msg)
    );

    $fields = json_encode($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Basic '.ONESIGNAL_RESTAPI_KEY));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function sendEmail($title, $message, $toEmail)
{
	$headers = "From:no-reply@TagN.com \r\n";
	$headers .= "Content-type:text/html \r\n";

	mail($toEmail, $title, $message, $headers);
}

function generateRandomString($length = 10)
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

function saveImageToS3($bucketName, $sourceFile, $imageFileName)
{
    global $s3;

    $s3->putObject(array(
        'Bucket' => $bucketName,
        'Key' => $imageFileName,
        'SourceFile' => $sourceFile,
        'ContentType' => 'image/jpeg',
        'ACL' => 'public-read'
    ));
}

function saveThumbToS3($bucketName, $thumbBody, $thumbFileName) {
    global $s3;

    $s3->putObject(array(
        'Bucket' => $bucketName,
        'Key'    => $thumbFileName,
        'Body'   => $thumbBody,
        'ContentType' => 'image/jpeg',
        'ACL' => 'public-read'
    ));
}

function deleteImageFromS3($buckectName, $key) {
    global $s3;

    $s3->deleteObject(array(
        'Bucket'  => $buckectName,
        'Key' => $key
    ));
}

function makeResultResponse($res, $code, $messages) {
    global $result;

    $result['code'] = $code;
    $result['messages'] = $messages;
    $newRes = $res->withStatus(200)
        ->withHeader('Content-Type', 'application/json;charset=utf-8')
        ->write(json_encode($result));

    return $newRes;
}

function validateUserAuthentication($req) {
    global $db;

    $isResult = false;

    $access_token = $req->getHeaderLine(HTTP_HEADER_ACCESS_TOKEN);
    $query = $db->prepare('select * from tblToken where token_key = HEX(AES_ENCRYPT(:token_key, \'' . DB_USER_PASSWORD . '\')) and token_expire_at > now()');
    $query->bindParam(':token_key', $access_token);
    if($query->execute()) {
        $user_access_token = $query->fetch(PDO::FETCH_NAMED);
        if($user_access_token) {
            $query = $db->prepare('update tblToken set token_expire_at = adddate(now(), INTERVAL 1 MONTH) where token_id = :token_id');
            $query->bindParam(':token_id', $user_access_token['token_id']);
            if($query->execute()) {
                $isResult = true;
            }
        }
    }

    return $isResult;
}


function getShareUsersFromTagId($tag_id, $user_id) {
    global $db;

    $share_users = [];
    $query = $db->prepare('select * from tblShare where share_tag_id = :tag_id
                           and share_status = 1');
    $query->bindParam(':tag_id', $tag_id);
    if($query->execute()) {
        $shares = $query->fetchAll(PDO::FETCH_ASSOC);

        $share_tmp_users = [];
        foreach($shares as $share) {
            if($share['share_from_user_id'] != $user_id) {
                array_push($share_tmp_users, $share['share_from_user_id']);
            }

            if($share['share_to_user_id'] != $user_id) {
                array_push($share_tmp_users, $share['share_to_user_id']);
            }
        }

        $share_users = array_unique($share_tmp_users);
    }

    return $share_users;
}
