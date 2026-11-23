<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
try{
    $passwords=json_decode(file_get_contents(getenv('JSC_PASSWORDS_FILE')?:''),true,8,JSON_THROW_ON_ERROR);
    echo json_encode((new App\Support\JscSamples(App\Config\Database::connection()))->install($passwords),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}catch(Throwable$error){fwrite(STDERR,'JSC sample setup failed ('.$error::class.'): '.($error instanceof InvalidArgumentException?$error->getMessage():'Check local configuration and dataset constraints.').PHP_EOL);exit(1);}
