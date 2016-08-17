<?php
/**
 * Created by PhpStorm.
 * User: kevinlee0621
 * Date: 2/4/16
 * Time: 8:29 PM
 */

require_once 'base.php';

function createNewUser($req, $res) {
    global $db;

    $params = $req->getParams();
    $files = $req->getUploadedFiles();
    $avatar_file_name = '';
    if(isset($files['avatar'])){
        $avatar_file_name = "Avatar_" . generateRandomString(40) . '.jpg';
        saveImageToS3(AVATAR_TAGN_BUCKET_NAME, $files['avatar']->file, $avatar_file_name);
    }

    $query = $db->prepare('insert into tblUser (user_name,
                                                user_username,
                                                user_email,
                                                user_pass,
                                                user_phone,
                                                user_avatar_url,
                                                user_device_token,
                                                user_time_zone,
                                                user_device_type) values
                                                (:user_name,
                                                :user_username,
                                                :user_email,
                                                HEX(AES_ENCRYPT(:user_pass, \'' . DB_USER_PASSWORD . '\')),
                                                :user_phone,
                                                :user_avatar_url,
                                                :user_device_token,
                                                :user_time_zone,
                                                :user_device_type)');

    $query->bindParam(':user_name', $params['user_name']);
    $query->bindParam(':user_username', $params['user_username']);
    $query->bindParam(':user_email', $params['user_email']);
    $query->bindParam(':user_pass', $params['user_pass']);
    $query->bindParam(':user_phone', $params['user_phone']);
    $query->bindParam(':user_avatar_url', $avatar_file_name);
    $query->bindParam(':user_device_token', $params['user_device_token']);
    $query->bindParam(':user_time_zone', $params['user_time_zone']);
    $query->bindParam(':user_device_type', $params['user_device_type']);

    if($query->execute()) {
        $newRes = login($req, $res);
    } else {
        if($query->errorInfo()[1] == 1062) {
            $newRes = makeResultResponse($res, 400, 'This email is already used in TagN');
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    }

    return $newRes;
}

function login($req, $res) {
    global $db;

    $params = $req->getParams();

    $query = $db->prepare('select * from tblUser where
                            (user_email = :user_email and user_pass = HEX(AES_ENCRYPT(:user_pass, \'' . DB_USER_PASSWORD . '\')))
                            or (user_username = :user_email and user_pass = HEX(AES_ENCRYPT(:user_pass, \'' . DB_USER_PASSWORD . '\')))');
    $query->bindParam(':user_email', $params['user_email']);
    $query->bindParam(':user_pass', $params['user_pass']);
    $query->execute();
    $user = $query->fetch(PDO::FETCH_NAMED);
    if($user) {
        $query = $db->prepare('update tblUser set user_device_token = :user_device_token,
                                                  user_time_zone = :user_time_zone,
                                                  user_device_type = :user_device_type
                               where user_id = :user_id');
        $query->bindParam(':user_device_token', $params['user_device_token']);
        $query->bindParam(':user_time_zone', $params['user_time_zone']);
        $query->bindParam(':user_device_type', $params['user_device_type']);
        $query->bindParam(':user_id', $user['user_id']);
        if($query->execute()) {
            $user['user_device_token'] = $params['user_device_token'];
            $user['user_time_zone'] = $params['user_time_zone'];
            $user['user_device_type'] = $params['user_device_type'];
            $user['user_access_token'] = createUserAccessToken($user['user_id'], $user['user_email']);
            $user['user_tags'] = getUserTags($user['user_id']);
            $user['user_recent_tags'] = array_slice($user['user_tags'], 0, 10);

            if($params['user_device_token'] != '') {
                //check device token
                $query = $db->prepare('update tblUser set user_device_token = "" where user_id <> :user_id and user_device_token = :user_device_token');
                $query->bindParam(':user_id', $user['user_id']);
                $query->bindParam(':user_device_token', $params['user_device_token']);
                if($query->execute()) {
                    $newRes = makeResultResponse($res, 200, $user);
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }
            } else {
                $newRes = makeResultResponse($res, 200, $user);
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 400, 'Your email or password is invalid');
    }

    return $newRes;
}

function createUserAccessToken($user_id, $user_email) {
    global $db;

    $query = $db->prepare('delete from tokens where token_user_id = :user_id');
    $query->bindParam(':user_id', $user_id);
    $query->execute();

    $token_key = base64_encode('TagNAccessToken=>Start:'.$user_email.'at'.time().':End');
    $query = $db->prepare('insert into tblToken (token_user_id,
                                                  token_key,
                                                  token_expire_at) values
                                                  (:token_user_id,
                                                  HEX(AES_ENCRYPT(:token_key, \'' . DB_USER_PASSWORD . '\')),
                                                  adddate(now(), INTERVAL 1 MONTH))');
    $query->bindParam(':token_user_id', $user_id);
    $query->bindParam(':token_key', $token_key);

    if($query->execute()) {
        $user_access_token = $token_key;
    } else {
        $user_access_token = $query->errorInfo()[2];
    }

    return $user_access_token;
}

function getUserTags($user_id) {
    global $db;

    $query = $db->prepare('select * from tblTag where tag_user_id = :user_id order by tag_id desc');
    $query->bindParam(':user_id', $user_id);
    if($query->execute()) {
        $tags = $query->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $tags = [];
    }

    return $tags;
}

function fbLogin($req, $res) {
    global $db;

    $params = $req->getParams();
    $query = $db->prepare('insert into tblUser (user_name,
                                                user_username,
                                                user_email,
                                                user_pass,
                                                user_phone,
                                                user_avatar_url,
                                                user_device_token,
                                                user_time_zone,
                                                user_device_type) values
                                                (:user_name,
                                                :user_username,
                                                :user_email,
                                                HEX(AES_ENCRYPT(:user_pass, \'' . DB_USER_PASSWORD . '\')),
                                                :user_phone,
                                                :user_avatar_url,
                                                :user_device_token,
                                                :user_time_zone,
                                                :user_device_type)');

    $query->bindParam(':user_name', $params['user_name']);
    $query->bindParam(':user_username', $params['user_username']);
    $query->bindParam(':user_email', $params['user_email']);
    $query->bindParam(':user_pass', $params['user_pass']);
    $query->bindParam(':user_phone', $params['user_phone']);
    $query->bindParam(':user_avatar_url', $params['user_avatar_url']);
    $query->bindParam(':user_device_token', $params['user_device_token']);
    $query->bindParam(':user_time_zone', $params['user_time_zone']);
    $query->bindParam(':user_device_type', $params['user_device_type']);

    if($query->execute()) {
        $newRes = login($req, $res);
    } else {
        if($query->errorInfo()[1] == 1062) {
            $newRes = login($req, $res);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    }

    return $newRes;
}

function forgotPassword($req, $res) {
    global $db, $result;

    $params = $req->getParams();

    $query = $db->prepare('select AES_DECRYPT(UNHEX(user_pass), \'' . DB_USER_PASSWORD . '\') as user_pass from tblUser where user_email = :user_email');
    $query->bindParam(':user_email', $params['user_email']);

    if($query->execute()) {

        $result = $query->fetch(PDO::FETCH_NAMED);
        if($result['user_pass']) {
            $html = '
			<h1>Forgot Password</h1>
			<hr>
			<br>
			<h4>Your email : ' . $params['user_email'] . '</h4>
			<h4>Your Password is ' . $result['user_pass'] . '</h4>
			<hr>
			';
            sendEmail('Forgot your password', $html, $params['user_email']);

            $newRes = makeResultResponse($res, 200, 'Sent password to your email');
        } else {
            $newRes = makeResultResponse($res, 400, 'Invalid email address');
        }
    } else {
        $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
    }

    return $newRes;
}

function updateUser($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();
        $files = $req->getUploadedFiles();

        if (isset($files['avatar'])) {
            //remove original avatar
            deleteImageFromS3(AVATAR_TAGN_BUCKET_NAME, $params['user_avatar_url']);
            $avatar_file_name = "Avatar_" . generateRandomString(40) . '.jpg';
            saveImageToS3(AVATAR_TAGN_BUCKET_NAME, $files['avatar']->file, $avatar_file_name);
        } else {
            $avatar_file_name = $params['user_avatar_url'];
        }

        $query = $db->prepare('update tblUser set user_name = :user_name,
                                                  user_username = :user_username,
                                                  user_phone = :user_phone,
                                                  user_avatar_url = :user_avatar_url
                                where user_id = :user_id');

        $query->bindParam(':user_name', $params['user_name']);
        $query->bindParam(':user_username', $params['user_username']);
        $query->bindParam(':user_phone', $params['user_phone']);
        $query->bindParam(':user_avatar_url', $avatar_file_name);
        $query->bindParam(':user_id', $args['id']);
        if ($query->execute()) {

            $query = $db->prepare('select * from tblUser where user_id = :user_id');
            $query->bindParam(':user_id', $args['id']);
            if($query->execute()) {
                $user = $query->fetch(PDO::FETCH_NAMED);
                $newRes = makeResultResponse($res, 200, $user);
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

function changePassword($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();

        $query = $db->prepare('select AES_DECRYPT(UNHEX(user_pass), \'' . DB_USER_PASSWORD . '\') as user_pass from tblUser where user_id = :user_id');
        $query->bindParam(':user_id', $args['id']);
        if($query->execute()) {
            $result = $query->fetch(PDO::FETCH_NAMED);
            if ($result['user_pass'] == $params['user_current_pass']) {
                $query = $db->prepare('update tblUser set
                                    user_pass = HEX(AES_ENCRYPT(:user_pass, \'' . DB_USER_PASSWORD . '\'))
                                    where user_id = :user_id');
                $query->bindParam(':user_id', $args['id']);
                $query->bindParam(':user_pass', $params['user_new_pass']);

                if ($query->execute()) {
                    $newRes = makeResultResponse($res, 200, 'Your password was changed successfully');
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }

            } else {
                $newRes = makeResultResponse($res, 400, 'Your current password is wrong.');
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function logout($req, $res, $args = []) {
    global $db;

    $query = $db->prepare('update tblUser set user_device_token = "" where user_id = :user_id');
    $query->bindParam(':user_id', $args['id']);
    if($query->execute()) {
        $newRes = makeResultResponse($res, 200, 'Logged out successfully');
    } else {
        $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
    }

    return $newRes;
}

function makeImageInfoObj($tags, $images) {
    $result = [];
    foreach($tags as $tag) {
        $image_info['imageinfo_tag'] = $tag;
        $image_info['imageinfo_images'] = [];
        foreach($images as $image) {
            if($tag['tag_id'] == $image['image_tag_id']) {
                array_push($image_info['imageinfo_images'], $image);
            }
        }

        array_push($result, $image_info);
    }

    return $result;
}

function getUserImages($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();
        $page_num = (int)$params['page_num'];
        $page_count = (int)$params['page_count'];
        $page_offset = ($page_num - 1) * $page_count;

        $query = $db->prepare('select * from tblTag where tag_user_id = :user_id order by tag_id desc limit :page_count offset :page_offset');
        $query->bindParam(':user_id', $args['id']);
        $query->bindParam(':page_count', $page_count, PDO::PARAM_INT);
        $query->bindParam(':page_offset', $page_offset, PDO::PARAM_INT);
        if($query->execute()) {
            $tags = $query->fetchAll(PDO::FETCH_ASSOC);
            $str_tag_ids = '';
            foreach ($tags as $tag) {
                $str_tag_ids .= $tag['tag_id'] . ',';
            }
            if (strlen($str_tag_ids) > 0) {
                $str_tag_ids = substr($str_tag_ids, 0, strlen($str_tag_ids) - 1);

                $query = $db->prepare('select *, convert_tz(image_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                    from viewImage where image_user_id = :user_id and image_tag_id in (' . $str_tag_ids . ')
                                    order by user_created_at desc');
                $query->bindParam(':user_id', $args['id']);

                if ($query->execute()) {

                    $share_images = [];

                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($images as $image) {
                        //image_is_like
                        $query = $db->prepare('select * from tblImageLike where like_user_id = :user_id and like_image_id = :image_id');
                        $query->bindParam(':user_id', $args['id']);
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
                        $query->bindParam(':user_id', $args['id']);
                        $query->bindParam(':image_id', $image['image_id']);
                        $query->execute();

                        $comments = $query->fetchAll(PDO::FETCH_ASSOC);
                        $image['image_last_2_comments'] = $comments;

                        array_push($share_images, $image);
                    }
                    $newRes = makeResultResponse($res, 200, makeImageInfoObj($tags, $share_images));
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }
            } else {
                $newRes = makeResultResponse($res, 200, []);
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function getShareImages($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $params = $req->getParams();
        $page_num = (int)$params['page_num'];
        $page_count = (int)$params['page_count'];
        $page_offset = ($page_num - 1) * $page_count;

        $query = $db->prepare('select * from tblTag where tag_id in
                                (select distinct(share_tag_id) FROM tblShare where (share_from_user_id = :user_id or share_to_user_id = :user_id) and share_status = 1)
                                order by tag_id desc limit :page_count offset :page_offset');
        $query->bindParam(':user_id', $args['id']);
        $query->bindParam(':page_count', $page_count, PDO::PARAM_INT);
        $query->bindParam(':page_offset', $page_offset, PDO::PARAM_INT);
        if($query->execute()) {
            $tags = $query->fetchAll(PDO::FETCH_ASSOC);
            $str_tag_ids = '';
            foreach ($tags as $tag) {
                $str_tag_ids .= $tag['tag_id'] . ',';
            }
            if (strlen($str_tag_ids) > 0) {
                $str_tag_ids = substr($str_tag_ids, 0, strlen($str_tag_ids) - 1);

                $query = $db->prepare('select *, convert_tz(image_created_at, "SYSTEM", (select user_time_zone from tblUser where user_id = :user_id)) as user_created_at
                                    from viewImage where image_tag_id in (' . $str_tag_ids . ')
                                    order by user_created_at desc');
                $query->bindParam(':user_id', $args['id']);

                if ($query->execute()) {

                    $share_images = [];

                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($images as $image) {
                        //image_is_like
                        $query = $db->prepare('select * from tblImageLike where like_user_id = :user_id and like_image_id = :image_id');
                        $query->bindParam(':user_id', $args['id']);
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
                        $query->bindParam(':user_id', $args['id']);
                        $query->bindParam(':image_id', $image['image_id']);
                        $query->execute();

                        $comments = $query->fetchAll(PDO::FETCH_ASSOC);
                        $image['image_last_2_comments'] = $comments;

                        array_push($share_images, $image);
                    }
                    $newRes = makeResultResponse($res, 200, makeImageInfoObj($tags, $share_images));
                } else {
                    $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
                }
            } else {
                $newRes = makeResultResponse($res, 200, []);
            }
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function deleteMeFromTag($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {

        $query = $db->prepare('delete from tblShare where share_to_user_id = :user_id and share_tag_id = :tag_id');
        $query->bindParam(':user_id', $args['id']);
        $query->bindParam(':tag_id', $args['tag_id']);
        if($query->execute()) {
            $newRes = makeResultResponse($res, 200, 'Removed you from share tag');
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function getTagUsers($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {

        $friend_ids = getFriends($args['id']);

        $query = $db->prepare('select * from tblUser where user_id <> :user_id');
        $query->bindParam(':user_id', $args['id']);
        if($query->execute()) {
            $users = $query->fetchAll(PDO::FETCH_ASSOC);

            $tag_users[0] = [];
            $tag_users[1] = [];

            $query = $db->prepare('select * from tblShare where share_tag_id = :tag_id');
            $query->bindParam(':tag_id', $args['tag_id']);
            if($query->execute()) {
                $shares = $query->fetchAll(PDO::FETCH_ASSOC);

                foreach($users as $user) {
                    $is_unknown_user = true;
                    foreach($shares as $share) {
                        if(($user['user_id'] == $share['share_from_user_id'])
                        || ($user['user_id'] == $share['share_to_user_id'])) {
                            $user['user_share_id'] = $share['share_id'];
                            $user['user_share_status'] = $share['share_status'];

                            $is_unknown_user = false;
                            break;
                        }
                    }

                    if($is_unknown_user) {
                        $user['user_share_id'] = 0;
                        $user['user_share_status'] = -10;   //unknown user
                    }

                    $is_friend = false;
                    foreach($friend_ids as $friend_id) {
                        if($user['user_id'] == $friend_id) {
                            $is_friend = true;
                            break;
                        }
                    }

                    if($is_friend) {
                        array_push($tag_users[0], $user);
                    } else {
                        array_push($tag_users[1], $user);
                    }
                }

                $newRes = makeResultResponse($res, 200, $tag_users);
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

function getFriends($user_id) {
    global $db;

    $friend_ids = [];
    $query = $db->prepare('select * from tblShare where share_status = 1 order by share_id desc');
    if($query->execute()) {
        $shares = $query->fetchAll(PDO::FETCH_ASSOC);

        foreach($shares as $share) {
            if($share['share_from_user_id'] == $user_id) {
                array_push($friend_ids, $share['share_to_user_id']);
            }

            if($share['share_to_user_id'] == $user_id) {
                array_push($friend_ids, $share['share_from_user_id']);
            }

            $friend_ids = array_unique($friend_ids);

            if(count($friend_ids) < FRIEND_COUNT) {
                continue;
            } else {
                break;
            }
        }
    }

    return $friend_ids;
}

function getAllUserNotifications($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $query = $db->prepare('select * from viewNotification where noti_to_user_id = :user_id order by user_created_at desc limit 50');
        $query->bindParam(':user_id', $args['id']);
        if($query->execute()) {
            $notis = $query->fetchAll(PDO::FETCH_ASSOC);
            $newRes = makeResultResponse($res, 200, $notis);
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

function updateAllUserNotificationsAsRead($req, $res, $args = []) {
    global $db;

    if(validateUserAuthentication($req)) {
        $query = $db->prepare('update tblNotification set noti_is_read = 1 where noti_to_user_id = :user_id');
        $query->bindParam(':user_id', $args['id']);
        if($query->execute()) {
            $newRes = makeResultResponse($res, 200, 'Marked all notifications as read');
        } else {
            $newRes = makeResultResponse($res, 400, $query->errorInfo()[2]);
        }
    } else {
        $newRes = makeResultResponse($res, 401, 'Your token has expired. Please login again.');
    }

    return $newRes;
}

