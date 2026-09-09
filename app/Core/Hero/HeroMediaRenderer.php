<?php

declare(strict_types=1);

namespace App\Core\Hero;

use App\Core\AppUrl;
use App\Core\Media;
use App\Core\MediaPosition;

/** Media layers and responsive posters; no layout or navigation policy. */
final class HeroMediaRenderer
{
    /**
     * Слой фона. Картинка-замена рендерится всегда и лежит под видео: это и
     * постер, и то, что остаётся, если видео показать нельзя.
     *
     * @param array<string, mixed> $d
     */
    public static function render(array $d, bool $first): string
    {
        $type = (string) $d['media_type'];
        if ($type === 'none') {
            return '';
        }

        $positions = MediaPosition::classes($d['image_position'], $d['image_position_mobile']);
        $fallback = HeroSlideData::fallbackImage($d);
        $layers = '';

        if ($fallback !== '') {
            $layers .= Media::picture(
                $fallback,
                '',
                null,
                null,
                'hero__image ' . $positions,
                !$first,
                '100vw',
                $first,
                'hero__fallback ' . $positions,
                $d['image_mobile'] !== '' ? (string) $d['image_mobile'] : null
            );
        }

        if ($type === 'video' && $d['video_url'] !== '') {
            $layers .= self::video($d);
        } elseif ($type === 'youtube' && $d['youtube_id'] !== '') {
            $layers .= self::youtube($d);
        }

        return $layers === '' ? '' : '<div class="hero__media" aria-hidden="true">' . $layers . '</div>';
    }

    /** @param array<string, mixed> $d */
    private static function video(array $d): string
    {
        $poster = HeroSlideData::fallbackImage($d);
        $sources = '<source data-hero-src="' . htmlspecialchars((string) $d['video_url'], ENT_QUOTES) . '" type="video/mp4">';

        // Ролик не стартует по атрибуту autoplay: старт даёт скрипт, когда слайд
        // действительно показан. Иначе карусель тянула бы все видео сразу — на
        // мобильном трафике это дороже, чем всё остальное на странице.
        return '<video class="hero__video" data-hero-video'
            . ' data-hero-mobile-media="' . htmlspecialchars((string) $d['mobile_media'], ENT_QUOTES) . '"'
            . ($d['video_mobile_url'] !== ''
                ? ' data-hero-video-mobile="' . htmlspecialchars((string) $d['video_mobile_url'], ENT_QUOTES) . '"'
                : '')
            . ' preload="none"'
            . ($poster !== '' ? ' poster="' . htmlspecialchars($poster, ENT_QUOTES) . '"' : '')
            . ' muted loop playsinline webkit-playsinline'
            . ' disablepictureinpicture disableremoteplayback'
            . ' controlslist="nodownload nofullscreen noremoteplayback noplaybackrate"'
            . ' tabindex="-1" aria-hidden="true" hidden>' . $sources . '</video>';
    }

    /**
     * YouTube как фон. Iframe создаёт скрипт: до его готовности (и навсегда,
     * если ролик недоступен или автовоспроизведение запрещено) виден постер.
     *
     * @param array<string, mixed> $d
     */
    private static function youtube(array $d): string
    {
        $params = [
            'autoplay' => '1',
            'mute' => '1',
            'loop' => '1',
            'playlist' => (string) $d['youtube_id'],
            'controls' => '0',
            'playsinline' => '1',
            'disablekb' => '1',
            'fs' => '0',
            'modestbranding' => '1',
            'rel' => '0',
            'iv_load_policy' => '3',
            'enablejsapi' => '1',
        ];
        $origin = AppUrl::base();
        if ($origin !== '') {
            $params['origin'] = $origin;
        }
        $src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode((string) $d['youtube_id'])
            . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return '<div class="hero__yt" data-hero-youtube'
            . ' data-hero-mobile-media="' . htmlspecialchars((string) $d['mobile_media'], ENT_QUOTES) . '"'
            . ' data-hero-yt-src="' . htmlspecialchars($src, ENT_QUOTES) . '" aria-hidden="true"></div>';
    }

}
