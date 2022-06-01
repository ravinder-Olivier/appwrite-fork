<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class VideoCustomServerTest extends Scope
{
    use StorageBase;
    use ProjectCustom;
    use SideServer;

    public function testTranscoding()
    {

//        $bucket = $this->client->call(Client::METHOD_POST, '/storage/buckets', [
//            'content-type' => 'application/json',
//            'x-appwrite-project' => $this->getProject()['$id'],
//            'x-appwrite-key' => $this->getProject()['apiKey'],
//        ], [
//            'bucketId' => 'unique()',
//            'name' => 'Test Bucket 2',
//            'permission' => 'file',
//            'read' => ['role:all'],
//            'write' => ['role:all']
//        ]);
//
//        $source = __DIR__ . "/../../../resources/disk-a/large-file.mp4";
//        $totalSize = \filesize($source);
//        $chunkSize = 5 * 1024 * 1024;
//        $handle = @fopen($source, "rb");
//        $fileId = 'unique()';
//        $mimeType = mime_content_type($source);
//        $counter = 0;
//        $size = filesize($source);
//        $headers = [
//            'content-type' => 'multipart/form-data',
//            'x-appwrite-project' => $this->getProject()['$id']
//        ];
//        $id = '';
//
//        while (!feof($handle)) {
//            $curlFile = new \CURLFile('data://' . $mimeType . ';base64,' . base64_encode(@fread($handle, $chunkSize)), $mimeType, 'in1.mp4');
//            $headers['content-range'] = 'bytes ' . ($counter * $chunkSize) . '-' . min(((($counter * $chunkSize) + $chunkSize) - 1), $size) . '/' . $size;
//
//            if (!empty($id)) {
//                $headers['x-appwrite-id'] = $id;
//            }
//
//            $file = $this->client->call(Client::METHOD_POST, '/storage/buckets/' . $bucket['body']['$id'] . '/files', array_merge($headers, $this->getHeaders()), [
//                'fileId' => $fileId,
//                'file' => $curlFile,
//                'read' => ['role:all'],
//                'write' => ['role:all'],
//            ]);
//            $counter++;
//            $id = $file['body']['$id'];
//        }
//        @fclose($handle);
//
//          $pid = $this->getProject()['$id'];
//          $key = $this->getProject()['apiKey'];
//          $fid = $id;
//          $bid = $bucket['body']['$id'];
//
//          var_dump($pid);
//          var_dump($key);
//          var_dump($fid);
//          var_dump($bid);


        $pid = '62978329ce7b1dda09b6';
        $key = '44dc30ee13439c08b74891f94bf1c6b10602682b13bafaa497cba3b1f7bb1003e95da4e0cbc4f94143198ca40b502281435b3671affdea372a24404edaf4df550ec94bb763205348becf9257ebbef43732bdbd7d9dac00011ba9fcb44c117c635de75dc9ae9e60ef4d34fa9c6fdc43acc67d9918c9e28ffb53b2d2f17daa6426';
        $fid = '6297832b75f59440ab52';
        $bid = '6297832a318cbed41894';

        $transcoding = $this->client->call(Client::METHOD_POST, '/video/buckets/' . $bid.'/files/'.  $fid, [
            'content-type' => 'application/json',
            'x-appwrite-project' => $pid,
            'x-appwrite-key' => $key,
        ], [
            'read' => ['role:all'],
            'write' => ['role:all']
        ]);

      var_dump($transcoding['body']);


    }

    }