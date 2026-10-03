<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Rendu des QR codes en PNG / SVG, avec logo D-Money optionnel au centre.
 *
 * Correction d'erreur H (jusqu'à 30 % du code récupérable) : le logo couvre
 * environ 22 % de la largeur, soit ~5 % de la surface, et le QR reste lisible
 * même imprimé sur un support un peu abîmé.
 */
class QrImageGenerator
{
    /** Logo à déposer dans public/ (PNG, idéalement carré et à fond transparent) */
    public const LOGO_PATH = 'images/qr-logo.png';

    private const LOGO_RATIO = 0.22;

    public const SIZES = [
        'small'  => ['Petit (≈ 300 px)', 6],
        'medium' => ['Moyen (≈ 500 px)', 10],
        'large'  => ['Grand (≈ 1000 px, impression)', 20],
    ];

    public static function logoAvailable(): bool
    {
        return is_file(public_path(self::LOGO_PATH));
    }

    public function png(string $payload, bool $withLogo = false, string $size = 'medium'): string
    {
        $png = (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG, [
            'scale' => self::SIZES[$size][1] ?? self::SIZES['medium'][1],
        ])))->render($payload);

        return $withLogo && self::logoAvailable() ? $this->addLogoToPng($png) : $png;
    }

    public function svg(string $payload, bool $withLogo = false, bool $xmlHeader = true): string
    {
        $svg = (new QRCode($this->options(QROutputInterface::MARKUP_SVG, [
            'svgAddXmlHeader' => $xmlHeader,
        ])))->render($payload);

        return $withLogo && self::logoAvailable() ? $this->addLogoToSvg($svg) : $svg;
    }

    public function pngDataUri(string $payload, bool $withLogo = false, string $size = 'small'): string
    {
        return 'data:image/png;base64,' . base64_encode($this->png($payload, $withLogo, $size));
    }

    private function options(string $outputType, array $extra = []): QROptions
    {
        return new QROptions(array_merge([
            'outputType'    => $outputType,
            'outputBase64'  => false,
            'eccLevel'      => EccLevel::H,
            'addQuietzone'  => true,
            'quietzoneSize' => 4,
        ], $extra));
    }

    private function addLogoToPng(string $png): string
    {
        $img  = imagecreatefromstring($png);
        $logo = imagecreatefrompng(public_path(self::LOGO_PATH));

        $width = imagesx($img);
        $box   = (int) round($width * self::LOGO_RATIO);
        $pad   = (int) round($box * 0.12);
        $x     = (int) round(($width - $box) / 2);

        // Fond blanc sous le logo pour qu'il ne se mélange pas aux modules
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, $x - $pad, $x - $pad, $x + $box + $pad, $x + $box + $pad, $white);

        // Logo redimensionné dans le carré, proportions conservées
        [$lw, $lh] = [imagesx($logo), imagesy($logo)];
        $scale = min($box / $lw, $box / $lh);
        $dw    = (int) round($lw * $scale);
        $dh    = (int) round($lh * $scale);

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $x + intdiv($box - $dw, 2), $x + intdiv($box - $dh, 2), 0, 0, $dw, $dh, $lw, $lh);

        ob_start();
        imagepng($img);
        $out = ob_get_clean();

        imagedestroy($img);
        imagedestroy($logo);

        return $out;
    }

    private function addLogoToSvg(string $svg): string
    {
        if (!preg_match('/viewBox="([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+)"/', $svg, $m)) {
            return $svg;
        }

        $size = (float) $m[3];
        $box  = $size * self::LOGO_RATIO;
        $pad  = $box * 0.12;
        $x    = ($size - $box) / 2;
        $logo = base64_encode(file_get_contents(public_path(self::LOGO_PATH)));

        $overlay = sprintf(
            '<rect x="%1$.2f" y="%1$.2f" width="%2$.2f" height="%2$.2f" fill="#ffffff"/>'
            . '<image x="%3$.2f" y="%3$.2f" width="%4$.2f" height="%4$.2f" preserveAspectRatio="xMidYMid meet" href="data:image/png;base64,%5$s"/>',
            $x - $pad, $box + 2 * $pad, $x, $box, $logo
        );

        return str_replace('</svg>', $overlay . '</svg>', $svg);
    }
}
