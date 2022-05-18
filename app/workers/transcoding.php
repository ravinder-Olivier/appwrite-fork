<?php

use Appwrite\Resque\Worker;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;

require_once __DIR__ . '/../init.php';

Console::title('Transcoding V1 Worker');
Console::success(APP_NAME . ' transcoding worker v1 has started');

class TranscodingV1 extends Worker
{
    protected array $errors = [];

    public function getName(): string
    {
        return "transcoding";
    }

    public function init(): void
    {
    var_dump('init');
    }

    public function run(): void
    {
        var_dump('run');
    }


    public function shutdown(): void
    {
        var_dump('shutdown');
    }
}
