<?php

declare(strict_types=1);

// Image processing with Pillow. Needs Pillow:
//   python -m pip install pillow      (or: vendor/bin/pyhp setup)
// Run with: php examples/10_pillow_images.php [path/to/photo.jpg]

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();
$Image = $py->import('Image', from: 'PIL');
$ImageDraw = $py->import('ImageDraw', from: 'PIL');
$ImageOps = $py->import('ImageOps', from: 'PIL');
$out = sys_get_temp_dir().DIRECTORY_SEPARATOR;

// Use the photo given on the command line, or draw a demo picture.
$source = $argv[1] ?? null;
if ($source !== null) {
    $photo = $Image->open($source);
} else {
    $photo = $Image->new('RGB', Py::tuple(1600, 1000), color: '#2E6F95');
    $draw = $ImageDraw->Draw($photo);
    foreach (range(0, 9) as $i) {
        $draw->ellipse([100 + $i * 130, 300, 300 + $i * 130, 500 + ($i % 3) * 120], fill: $i % 2 ? '#F2C14E' : '#F78154');
    }
    $source = 'a generated demo picture';
}
// Phone photos store their rotation in EXIF; apply it so the result isn't sideways.
$photo = $ImageOps->exif_transpose($photo)->convert('RGB');
[$width, $height] = $photo->size;
echo "Loaded {$source}: {$width}x{$height}\n";

// 1. A thumbnail that keeps the aspect ratio (thumbnail() changes the image in place).
$thumb = $photo->copy();
$thumb->thumbnail(Py::tuple(400, 400));
$thumb->save($out.'pyhp-thumb.jpg', quality: 85);
echo 'Thumbnail: '.implode('x', $thumb->size)." -> {$out}pyhp-thumb.jpg\n";

// 2. A square avatar, cropped from the centre.
$avatar = $ImageOps->fit($photo, Py::tuple(256, 256));
$avatar->save($out.'pyhp-avatar.png');
echo "Square avatar: {$out}pyhp-avatar.png\n";

// 3. A watermark: semi-transparent text in the corner.
$marked = $photo->convert('RGBA');
$layer = $Image->new('RGBA', $marked->size, Py::tuple(0, 0, 0, 0));
$ImageDraw->Draw($layer)->text(Py::tuple($width - 260, $height - 70), '(c) PyHP demo', fill: Py::tuple(255, 255, 255, 180), font_size: 36);
$Image->alpha_composite($marked, $layer)->convert('RGB')->save($out.'pyhp-watermarked.jpg', quality: 90);
echo "Watermarked copy: {$out}pyhp-watermarked.jpg\n";

// 4. WebP bytes in memory, e.g. for an HTTP response or object storage.
//    Python bytes come back to PHP as a binary string.
$buffer = $py->import('io')->BytesIO();
$thumb->save($buffer, format: 'WEBP', quality: 80);
$webp = $buffer->getvalue();
echo 'WebP thumbnail in memory: '.strlen($webp).' bytes ('.substr($webp, 0, 4).'...'.substr($webp, 8, 4).")\n";
// In a web controller: header('Content-Type: image/webp'); echo $webp;

// 5. Bytes from PHP (an upload, a download) can go the other way too.
$fromPhp = $Image->open($py->import('io')->BytesIO(file_get_contents($out.'pyhp-avatar.png')));
echo 'Re-opened from PHP bytes: '.$fromPhp->format.' '.implode('x', $fromPhp->size)."\n";

$py->close();
