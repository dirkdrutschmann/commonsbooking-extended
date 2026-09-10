<?php
namespace DirkDrutschmann\CommonbookingsAdditionalFeatures\Helper;

class TemplateLoader{
    public static function load()
    {
        $loader = new \Twig\Loader\FilesystemLoader(
            dirname(__DIR__, 2) . '/assets/templates'
        );
        $twig = new \Twig\Environment($loader);
        $twig->addFilter(new \Twig\TwigFilter('picture', [self::class, 'getThePicture']));
        $twig->addFilter(new \Twig\TwigFilter('dump', [self::class, 'dump']));
        return $twig;
    }

    public static function dump($arr){
        return print_r($arr, true);
    }
    public static function getThePicture($id){
        return get_the_post_thumbnail_url($id);
    }
}
