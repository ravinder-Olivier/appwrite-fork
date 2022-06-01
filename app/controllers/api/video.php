<?php

use Appwrite\Auth\Auth;
use Appwrite\ClamAV\Network;
use Appwrite\Event\Audit;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Transcoding;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Swoole\Request;
use Streaming\Representation;



App::get('/v1/video/buckets/:bucketId/files/:fileId')
    ->alias('/v1/video/files', ['bucketId' => 'default'])
    ->desc('Start transcoding video')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
//    ->label('event', 'buckets.[bucketId].files.[fileId].create')
//    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
//    ->label('sdk.namespace', 'storage')
//    ->label('sdk.method', 'createFile')
//    ->label('sdk.description', '/docs/references/storage/create-file.md')
//    ->label('sdk.request.type', 'multipart/form-data')
//    ->label('sdk.methodType', 'upload')
//    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
//    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
//    ->label('sdk.response.model', Response::MODEL_FILE)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
//    ->param('file', [], new File(), 'Binary file.', false)
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(action: function (string $bucketId, string $fileId, ?array $read, ?array $write, Request $request, Response $response, Database $dbForProject, $project, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        if ($bucket->isEmpty()
            || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
            throw new Exception('Bucket not found', 404, Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        // Check bucket permissions when enforced
        $permissionBucket = $bucket->getAttribute('permission') === 'bucket';
        if ($permissionBucket) {
            $validator = new Authorization('write');
            if (!$validator->isValid($bucket->getWrite())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        $read = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? []; // By default set read permissions for user
        $write = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [];

        // Users can only add their roles to files, API keys and Admin users can add any
        $roles = Authorization::getRoles();

        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
            foreach ($read as $role) {
                if (!Authorization::isRole($role)) {
                    throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
            foreach ($write as $role) {
                if (!Authorization::isRole($role)) {
                    throw new Exception('Write permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
        }

        $transcoder = new Transcoding();
        $transcoder
            ->setUser($user)
            ->setProject($project)
            ->setBucketId($bucketId)
            ->setFileId($fileId)
            ->trigger();



//        $sourceDir   = 'tests/video_tmp_in/';
//        $sourceFile  =  'in2.MOV' ;
//        $sourcePath  =  $sourceDir . $sourceFile;
//        $destDir     = 'tests/video_tmp_out/'. $fileId;
//
//        $ffmpeg = Streaming\FFMpeg::create([]);
//        $ffprobe = FFMpeg\FFProbe::create([]);
//
//        //$ffprobe->isValid($filePath); // returns bool
//
//        $renditions = Config::getParam('renditions', []);
//
//        $ffprobe = FFMpeg\FFProbe::create();
//        $width = $ffprobe->streams($sourcePath)->videos()->first()->get('width');
//        $height = $ffprobe->streams($sourcePath)->videos()->first()->get('height');
//        $bitrateKb = $ffprobe->streams($sourcePath)->videos()->first()->get('bit_rate');
//        $bitrateMb =  $bitrateKb*1000;
//        $duration = $ffprobe->streams($sourcePath)->videos()->first()->get('duration');
//
//        $renditions = [];
//        foreach (Config::getParam('renditions', []) as $rendition) {
//            $renditions[] = (new Representation)->
//            setKiloBitrate($rendition['videoBitrate'])->
//            setAudioKiloBitrate($rendition['audioBitrate'])->
//            setResize($rendition['width'], $rendition['height']);
//
//        }
//
//
//        $video = $ffmpeg->open($sourcePath);
//        $format = new Streaming\Format\X264();
//        $format->on('progress', function ($video, $format, $percentage) use ($destDir){
//            if($percentage % 10 === 0) {
//                file_put_contents($destDir . '_progress.txt', $percentage . PHP_EOL, FILE_APPEND | LOCK_EX);
//            }
//            //echo sprintf("\rTranscoding...(%s%%) [%s%s]", $percentage, str_repeat('#', $percentage), str_repeat('-', (100 - $percentage)));
//        });
//
//        $video->hls()
//            ->setFormat($format)
//            ->setHlsBaseUrl('')
//            ->setFlags(['single_file'])
//            ->setHlsTime(5)
//            ->setHlsAllowCache(false)
//            ->addRepresentations($renditions)
//            ->save($destDir);



        $response->noContent();
        //$response->json(['result' => 'ok']);
    });
