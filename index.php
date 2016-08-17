<?php

// Include the SDK using the Composer autoloader
require 'vendor/autoload.php';

// Includes ;
require_once( 'config/database.php' );

$s3 = new Aws\S3\S3Client([
    'version'     => 'latest',
    'region'      => 'us-west-2'
]);

$app = new Slim\App();

$result['code'] = 200;
$result['messages'] = '';

$app->group('/v1', function() use ($app){
    $app->group('/users', function() use ($app){
        require_once 'api/user.php';
        $app->post('', 'createNewUser');
        $app->get('/login', 'login');
        $app->get('/fblogin', 'fbLogin');
        $app->get('/forgotpassword', 'forgotPassword');

        $app->group('/{id}', function() use ($app) {
            $app->post('', 'updateUser');
            $app->put('', 'changePassword');
            $app->patch('', 'logout');
            $app->group('/images', function() use ($app){
                $app->get('', 'getUserImages');
                $app->get('/share', 'getShareImages');
            });
            $app->group('/tag/{tag_id}', function() use ($app){
                $app->delete('', 'deleteMeFromTag');
                $app->get('/users', 'getTagUsers');
            });
            $app->group('/notifications', function() use ($app){
                $app->get('', 'getAllUserNotifications');
                $app->patch('', 'updateAllUserNotificationsAsRead');
            });
        });
    });

    $app->group('/tags', function() use($app){
        require_once 'api/tag.php';
        $app->post('', 'addTag');
        $app->patch('', 'markTagsAsInvisible');
        $app->group('/{id}', function() use($app){
            $app->delete('', 'deleteTag');
            $app->get('/images', 'getTagImages');
        });
    });

    $app->group('/images', function() use($app){
        require_once  'api/image.php';
        $app->post('', 'makeImage');
        $app->delete('', 'deleteImages');
        $app->group('/{id}', function() use($app){
            $app->get('', 'getImageWithId');
            $app->post('/likes', 'likeImage');
            $app->get('/comments', 'getImageComments');
            $app->get('/likers', 'getImageLikers');
        });
    });

    $app->group('/shares', function() use($app){
        require_once 'api/share.php';
        $app->post('', 'shareTagWithGroup');
        $app->delete('/{id}', 'removeUserFromShareTag');
        $app->patch('/{id}', 'responseShare');
    });
    $app->group('/notifications', function() use($app){
        require_once 'api/notification.php';
        $app->group('/{id}', function() use($app) {
            $app->get('', 'getNotification');
            $app->delete('', 'removeNotification');
        });
    });
    $app->group('/comments', function() use($app){
       require_once 'api/comment.php';
        $app->post('', 'addComment');
        $app->delete('/{id}', 'deleteComment');
    });
    $app->any('/api/explorer', 'getAPIDoc');
});

$app->run();

function getAPIDoc($req, $res) {
    $strJson = file_get_contents('docs/swagger.json');

    $newRes = $res->withStatus(200)
        ->withHeader('Content-Type', 'application/json;charset=utf-8')
        ->write($strJson);

    return $newRes;
}
