<?php
// Twig autoloader + factory for Forma
spl_autoload_register(function ($class) {
    if (strpos($class, 'Twig\\') !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

function formax_twig(): \Twig\Environment {
    static $env = null;
    if ($env instanceof \Twig\Environment) {
        return $env;
    }
    $loader = new \Twig\Loader\ArrayLoader();
    $env = new \Twig\Environment($loader, [
        'autoescape' => 'html',
        'debug'      => false,
        'cache'      => false,
    ]);
    $env->addFunction(new \Twig\TwigFunction('ends_with', function ($haystack, $needle) {
        return str_ends_with($haystack, $needle);
    }));
    return $env;
}

// Back-compat for any code expecting $twig global
$twig = formax_twig();
$GLOBALS['twig'] = $twig;
