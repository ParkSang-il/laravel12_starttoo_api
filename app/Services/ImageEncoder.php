<?php
namespace App\Services;
use RuntimeException;
class ImageEncoder
{
    public function encode(\GdImage $source, int $limit): string
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $limit / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        try {
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
            ob_start();
            try {
                if (! (function_exists('imagejpeg') ? imagejpeg($target, null, 82) : imagepng($target, null, 6))) {
                    throw new RuntimeException('Profile image encoding failed.');
                }

                return ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($target);
        }
    }

}
