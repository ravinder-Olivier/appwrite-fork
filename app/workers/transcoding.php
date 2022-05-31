<?php

use Appwrite\Extend\Exception;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Resque\Worker;
use Streaming\Representation;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use \FFMpeg\FFProbe\DataMapping\StreamCollection;
use Utopia\Storage\Compression\Algorithms\GZIP;
require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{

    const HLS_BASE_URL = '';

    protected array $errors = [];

    protected string $basePath = '/tmp/';

    protected string $inDir;

    protected string $outDir;

    protected string $outPath;


    public function getName(): string
    {
        return "Transcoding";
    }


    public function init(): void
    {

        $this->basePath .=  $this->args['fileId'];
        $this->inDir  =  $this->basePath . '/in/';
        $this->outDir =  $this->basePath . '/out/';
        @mkdir($this->inDir, 0755, true);
        @mkdir($this->outDir,0755,true);
        $this->outPath = $this->outDir . $this->args['fileId']; /** TODO figure a way to write dir tree without this **/

    }

    public function run(): void
    {
        $project  = new Document($this->args['project']);
        $user     = new Document($this->args['user'] ?? []);
        $database = $this->getProjectDB($project->getId());

        $bucket = Authorization::skip(fn () => $database->getDocument('buckets', $this->args['bucketId']));

        if ($bucket->getAttribute('permission') === 'bucket') {
            $file = Authorization::skip(fn () => $database->getDocument('bucket_' . $bucket->getInternalId(), $this->args['fileId']));
        } else {
            $file = $database->getDocument('bucket_' . $bucket->getInternalId(), $this->args['fileId']);
        }

        $data = $this->getFilesDevice($project->getId())->read($file->getAttribute('path'));
        $fileName    = basename($file->getAttribute('path'));
        $inPath      =  $this->inDir . $fileName;

        if (!empty($file->getAttribute('openSSLCipher'))) { // Decrypt
            $data = OpenSSL::decrypt(
                $data,
                $file->getAttribute('openSSLCipher'),
                App::getEnv('_APP_OPENSSL_KEY_V' . $file->getAttribute('openSSLVersion')),
                0,
                \hex2bin($file->getAttribute('openSSLIV')),
                \hex2bin($file->getAttribute('openSSLTag'))
            );
        }

        if (!empty($file->getAttribute('algorithm', ''))) {
            $compressor = new GZIP();
            $data = $compressor->decompress($data);
        }

        $this->getFilesDevice($project->getId())->write($this->inDir. $fileName, $data, $file->getAttribute('mimeType'));

        $ffprobe = FFMpeg\FFProbe::create([]);
        $ffmpeg = Streaming\FFMpeg::create([]);

        $valid = $ffprobe->isValid($inPath);
        $sourceInfo = $this->getVideoInfo($ffprobe->streams($inPath));

        $video = $ffmpeg->open($inPath);
        $renditions = [];
        /** renditions loop */

        foreach (Config::getParam('renditions', []) as $rendition) {
            $query = Authorization::skip(function () use($database, $bucket, $rendition) {
                return $database->createDocument('bucket_' . $bucket->getInternalId() . '_video_renditions', new Document([
                    'bucketId' => $this->args['bucketId'],
                    'fileId' => $this->args['fileId'],
                    'renditionId' => $rendition['id'],
                    'renditionName' => $rendition['name'],
                    'timeStarted' => time(),
                    'metadata' => json_encode([
                        'width' => $rendition['width'],
                        'height' => $rendition['height'],
                        'videoBitrate' => $rendition['videoBitrate']
                    ]),
                    'status' => 'transcoding started',
                ]));
            });

            $representation = (new Representation)->
                    setKiloBitrate($rendition['videoBitrate'])->
                    setAudioKiloBitrate($rendition['audioBitrate'])->
                    setResize($rendition['width'], $rendition['height']);

                $format = new Streaming\Format\X264();
                $format->on('progress', function ($video, $format, $percentage) use ($database){});

            /** Create HLS */
            $hls = $video->hls()
                ->setFormat($format)
                ->setAdditionalParams(['-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'])
                ->setHlsBaseUrl(self::HLS_BASE_URL)
                ->setHlsTime(10)
                ->setHlsAllowCache(false)
                ->addRepresentations([$representation])
                ->save($this->outPath);

            /** m3u8 master playlist */
            $renditions[] = $rendition;
            $m3u8 = $this->getHlsMasterPlaylist($renditions, $this->args['fileId']);
            file_put_contents($this->outDir . $this->args['fileId'].'.m3u8', $m3u8, LOCK_EX);

            $metadata = $hls->metadata()->export();
            if(!empty($metadata['stream']['resolutions'][0])){
                $info = $metadata['stream']['resolutions'][0];
                $query->setAttribute('metadata', json_encode([
                    'resolution'    => $info['dimension'],
                    'videoBitrate' => $info['video_kilo_bitrate'],
                    'audioBitrate' => $info['audio_kilo_bitrate'],
                ]));
            }

            $query->setAttribute('status', 'transcoding ended');
            $query->setAttribute('timeEnded', time());
            Authorization::skip(fn () => $database->updateDocument(
                'bucket_' . $bucket->getInternalId() . '_video_renditions',
                $query->getId(),
                $query
                ));


            /** Upload & remove files **/
            $start = 0;
            $fileNames = scandir($this->outDir);
            
            foreach($fileNames as $fileName) {

                if($fileName === '.' || $fileName === '..'){
                    continue;
                }

                $deviceFiles  = $this->getVideoDevice($project->getId());
                $devicePath   = $deviceFiles->getPath($this->args['fileId']);
                $devicePath   = str_ireplace($deviceFiles->getRoot(), $deviceFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $devicePath);
                $data = $this->getFilesDevice($project->getId())->read($this->outDir. $fileName);
                $this->getVideoDevice($project->getId())->write($devicePath. DIRECTORY_SEPARATOR . $fileName, $data, \mime_content_type($this->outDir. $fileName));

                if($start === 0){
                    $query->setAttribute('status', 'uploading');
                    Authorization::skip(fn () => $database->updateDocument(
                       'bucket_' . $bucket->getInternalId() . '_video_renditions',
                       $query->getId(),
                       $query
                    ));
                   $start = 1;
                }

                //$metadata=[];
                //$chunksUploaded = $deviceFiles->upload($file, $path, -1, 1, $metadata);
                //var_dump($chunksUploaded);
                // if (empty($chunksUploaded)) {
                //  throw new Exception('Failed uploading file', 500, Exception::GENERAL_SERVER_ERROR);
                //}
                // }

                @unlink($this->outDir. $fileName);
            }

            $query->setAttribute('status', 'ready');
            Authorization::skip(fn () => $database->updateDocument(
                'bucket_' . $bucket->getInternalId() . '_video_renditions',
                $query->getId(),
                $query
            ));
        }
    }

    /**
     * @param $renditions array
     * @param string $path
     * @return string
     */
    private function getHlsMasterPlaylist(array $renditions, string $path): string
    {
        $m3u8 = '#EXTM3U'. PHP_EOL . '#EXT-X-VERSION:3'. PHP_EOL;
        foreach ($renditions as $rendition) {
            $m3u8 .= '#EXT-X-STREAM-INF:BANDWIDTH=' .
                (($rendition['videoBitrate']+$rendition['audioBitrate'])*1024) .
                ',RESOLUTION=' . $rendition['width'] . 'x'. $rendition['height'] .
                ',NAME="' . $rendition['height'] . '"' . PHP_EOL .
                 $path . '_' . $rendition['height']. 'p.m3u8' . PHP_EOL;
        }
        return $m3u8;
    }


    /**
     * @param $streams StreamCollection
     * @return array
     */
    private function getVideoInfo(StreamCollection $streams): array
    {

        return [
            'duration' => $streams->videos()->first()->get('duration'),
            'height' => $streams->videos()->first()->get('height'),
            'width' => $streams->videos()->first()->get('width'),
            'frame_rate' => $streams->videos()->first()->get('r_frame_rate'),
            'bitrateKb' => $streams->videos()->first()->get('bit_rate')/1000,
            'bitrateMb' =>  $streams->videos()->first()->get('bit_rate')/1000/1000,
        ];
    }

    private function cleanup(): bool
    {
        return \exec("rm -rf {$this->basePath}");
    }

    public function shutdown(): void
    {
        $this->cleanup();
    }
}
