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
    protected array $errors = [];

    protected string $basePath = 'tests/tmp/';

    protected string $inDir;

    protected string $outDir;

    public function getName(): string
    {
        return "transcoding";
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

        $this->getFilesDevice($project->getId())->write('/usr/src/code/'. $this->inDir. $fileName, $data, $file->getAttribute('mimeType'));

        $ffprobe = FFMpeg\FFProbe::create([]);
        $ffmpeg = Streaming\FFMpeg::create([]);

        $valid = $ffprobe->isValid($inPath);

        $sourceInfo = $this->getVideoInfo($ffprobe->streams($inPath));
        $renditions = [];
        foreach (Config::getParam('renditions', []) as $rendition) {
            $renditions[] = (new Representation)->
            setKiloBitrate($rendition['videoBitrate'])->
            setAudioKiloBitrate($rendition['audioBitrate'])->
            setResize($rendition['width'], $rendition['height']);

        }

        $video = $ffmpeg->open($inPath);
        $format = new Streaming\Format\X264();
        $path = $this->outDir;
        $format->on('progress', function ($video, $format, $percentage) use ($path){
            file_put_contents($path . 'progress_row.txt', $percentage . PHP_EOL, FILE_APPEND | LOCK_EX);
            if($percentage % 3 === 0) {
                file_put_contents($path . 'progress.txt', $percentage . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        });

        $video->hls()
            #->setSegSubDirectory('ts')
            ->setFormat($format)
            ->setAdditionalParams(['-vf', 'scale=iw:-2:force_original_aspect_ratio=increase,setsar=1:1'])
            ->setHlsBaseUrl('')
            #->setFlags(['single_file'])
            ->setHlsTime(5)
            ->setHlsAllowCache(false)
            ->addRepresentations($renditions)
            ->save($this->outPath);

          $deviceFiles  = $this->getVideoDevice($project->getId());

        if ($handle = opendir($this->outDir)) {
            while (false !== ($fileName = readdir($handle))) {
                if ('.' === $fileName) continue;
                if ('..' === $fileName) continue;

                $path = $deviceFiles->getPath($this->args['fileId']);
                $path = str_ireplace($deviceFiles->getRoot(), $deviceFiles->getRoot() . DIRECTORY_SEPARATOR . $bucket->getId(), $path); // Add bucket id to path after root
                $data = $this->getFilesDevice($project->getId())->read($this->outDir. $fileName);
                $this->getVideoDevice($project->getId())->write($path. DIRECTORY_SEPARATOR . $fileName, $data, \mime_content_type($path. DIRECTORY_SEPARATOR . $fileName));

                //$metadata=[];
                //$chunksUploaded = $deviceFiles->upload($file, $path, -1, 1, $metadata);
                //var_dump($chunksUploaded);
//                 if (empty($chunksUploaded)) {
//                    throw new Exception('Failed uploading file', 500, Exception::GENERAL_SERVER_ERROR);
//                  }
            }
            closedir($handle);
        }
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
