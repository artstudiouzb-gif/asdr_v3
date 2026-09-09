<?php

declare(strict_types=1);

use App\Core\Uploader;

test('Медиабиблиотека принимает настоящий MP4 и отклоняет подмену расширения', function (): void {
    $mp4 = tempnam(sys_get_temp_dir(), 'art-mp4-');
    $fake = tempnam(sys_get_temp_dir(), 'art-fake-');
    assert_true(is_string($mp4) && is_string($fake), 'временные файлы созданы');

    try {
        file_put_contents($mp4, "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isommp41");
        file_put_contents($fake, '<?php echo "not a video";');

        assert_true(Uploader::mimeMatches('mp4', 'video/mp4', $mp4), 'стандартный MIME MP4 разрешён');
        assert_true(Uploader::mimeMatches('mp4', 'application/octet-stream', $mp4), 'неуверенный libmagic подтверждается сигнатурой');
        assert_false(Uploader::mimeMatches('mp4', 'text/x-php', $fake), 'опасный MIME отклонён');
        assert_false(Uploader::mimeMatches('mp4', 'application/octet-stream', $fake), 'переименование без MP4-сигнатуры отклонено');
        assert_false(Uploader::mimeMatches('exe', 'application/octet-stream', $fake), 'неразрешённое расширение отклонено');
    } finally {
        @unlink($mp4);
        @unlink($fake);
    }
});
