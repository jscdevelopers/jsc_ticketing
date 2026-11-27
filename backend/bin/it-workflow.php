<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
try{echo json_encode((new App\Support\ItSetup(App\Config\Database::connection()))->install(json_decode(file_get_contents(getenv('IT_PASSWORDS_FILE')?:''),true,8,JSON_THROW_ON_ERROR)),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;}
catch(Throwable$error){fwrite(STDERR,'IT workflow setup failed ('.$error::class.'). '.($error instanceof InvalidArgumentException?$error->getMessage():'Review directory and local configuration.').PHP_EOL);exit(1);}
