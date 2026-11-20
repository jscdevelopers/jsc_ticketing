<?php
declare(strict_types=1);

// Same App namespace as local Nite; standalone fallback keeps this dependency-free.
$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}
return $root;
