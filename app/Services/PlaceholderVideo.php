<?php

namespace App\Services;

/**
 * Generates a minimal, valid, playable H.264/MP4 video — a single black frame
 * at 720x1280 (9:16, YouTube Shorts orientation) — entirely in pure PHP. No
 * ffmpeg, no external tools. This exists so videos whose upload silently failed
 * (e.g. a misconfigured FTP disk) can still get a real file instead of staying
 * file-less forever.
 *
 * The H.264 access unit (SPS + PPS + IDR) is a real x264 stream, generated once
 * with:
 *
 *   ffmpeg -f lavfi -i "color=c=black:s=720x1280:r=25:d=0.04" \
 *          -c:v libx264 -profile:v baseline -level 3.1 -pix_fmt yuv420p \
 *          -x264-params "cabac=0" ...
 *
 * and stored verbatim in resources/placeholder/black-720x1280.h264 (annex-B).
 * Using a proven bitstream sidesteps the risk of hand-encoded H.264; the MP4
 * container built here mirrors a standard ffmpeg layout and parses cleanly with
 * getID3 (already a dependency).
 */
class PlaceholderVideo
{
    public const WIDTH = 720;

    public const HEIGHT = 1280;

    /** Seconds the single frame is displayed. */
    public const DURATION_SECONDS = 1;

    /** Path of the annex-B reference stream (relative to this file). */
    private const STREAM = __DIR__.'/../../resources/placeholder/black-720x1280.h264';

    /**
     * The complete MP4 file as bytes.
     */
    public static function mp4(): string
    {
        [$sps, $pps, $slice] = self::nals();

        // Sample = length-prefixed NAL units (lengthSizeMinusOne = 3).
        $sample = pack('N', strlen($sps)).$sps
            .pack('N', strlen($pps)).$pps
            .pack('N', strlen($slice)).$slice;

        $ftyp = self::box('ftyp', 'isom'.pack('N', 0x00000200).'isomiso2avc1mp41');

        // Two passes so the stco chunk offset can point at the sample: moov
        // size is fixed, only the 4-byte offset value changes.
        $probe = self::moov(0, strlen($sample), $sps, $pps);
        $chunkOffset = strlen($ftyp) + strlen($probe) + 8; // +8 = mdat box header
        $moov = self::moov($chunkOffset, strlen($sample), $sps, $pps);

        $mdat = self::box('mdat', $sample);

        return $ftyp.$moov.$mdat;
    }

    /**
     * Parse the reference stream into [SPS, PPS, IDR-slice] NAL units.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function nals(): array
    {
        $stream = file_get_contents(self::STREAM);
        if ($stream === false) {
            throw new \RuntimeException('Placeholder video stream missing: '.self::STREAM);
        }

        $nals = [];
        $pos = 0;
        while ($pos < strlen($stream)) {
            if (substr($stream, $pos, 4) === "\x00\x00\x00\x01") {
                $start = $pos + 4;
            } elseif (substr($stream, $pos, 3) === "\x00\x00\x01") {
                $start = $pos + 3;
            } else {
                $pos++;

                continue;
            }

            $end = $start;
            while ($end < strlen($stream)
                && ! (substr($stream, $end, 4) === "\x00\x00\x00\x01"
                    || substr($stream, $end, 3) === "\x00\x00\x01")) {
                $end++;
            }

            $nals[] = substr($stream, $start, $end - $start);
            $pos = $end;
        }

        if (count($nals) !== 3) {
            throw new \RuntimeException('Placeholder stream malformed: expected SPS+PPS+IDR, got '.count($nals).' NAL(s).');
        }

        return $nals;
    }

    private static function moov(int $chunkOffset, int $sampleSize, string $sps, string $pps): string
    {
        $mvhd = self::box('mvhd', pack('N', 0)
            .pack('N', 0)              // creation_time
            .pack('N', 0)              // modification_time
            .pack('N', 1000)           // timescale
            .pack('N', 1000 * self::DURATION_SECONDS)
            .pack('N', 0x00010000)     // rate
            .pack('n', 0x0100)         // volume
            .pack('n', 0)              // reserved
            .pack('N', 0).pack('N', 0) // reserved
            .self::identityMatrix()
            .str_repeat("\0", 24)      // pre_defined
            .pack('N', 2));            // next_track_ID

        $tkhd = self::box('tkhd', pack('N', 0x00000003)
            .pack('N', 0).pack('N', 0)
            .pack('N', 1)              // track_ID
            .pack('N', 0)              // reserved
            .pack('N', 1000 * self::DURATION_SECONDS)
            .pack('N', 0).pack('N', 0) // reserved
            .pack('n', 0).pack('n', 0) // layer, alternate_group
            .pack('n', 0x0100).pack('n', 0)
            .self::identityMatrix()
            .pack('N', self::WIDTH << 16)
            .pack('N', self::HEIGHT << 16));

        $mdhd = self::box('mdhd', pack('N', 0)
            .pack('N', 0).pack('N', 0)
            .pack('N', 1000)           // timescale
            .pack('N', 1000 * self::DURATION_SECONDS)
            .pack('n', 0x55C4)         // language "und"
            .pack('n', 0));

        $hdlr = self::box('hdlr', pack('N', 0)
            .pack('N', 0)
            .'vide'
            .str_repeat("\0", 12)
            ."VideoHandler\0");

        $vmhd = self::box('vmhd', pack('N', 1).pack('n', 0).str_repeat("\0", 6));

        $dref = self::box('dref', pack('N', 0).pack('N', 1).self::box('url ', pack('N', 1)));
        $dinf = self::box('dinf', $dref);

        // avcC profile/compatibility/level are read straight out of the SPS so
        // they can never drift from the actual bitstream.
        $avcC = self::box('avcC', "\x01".$sps[1].$sps[2].$sps[3]
            ."\xff\xe1".pack('n', strlen($sps)).$sps
            ."\x01".pack('n', strlen($pps)).$pps);

        $avc1 = self::box('avc1', str_repeat("\0", 6).pack('n', 1)
            .str_repeat("\0", 2).str_repeat("\0", 2).str_repeat("\0", 12)
            .pack('n', self::WIDTH).pack('n', self::HEIGHT)
            .pack('N', 0x00480000).pack('N', 0x00480000)
            .pack('N', 0).pack('n', 1)
            .str_repeat("\0", 32)
            .pack('n', 0x0018).pack('n', 0xFFFF)
            .$avcC);

        $stsd = self::box('stsd', pack('N', 0).pack('N', 1).$avc1);
        $stts = self::box('stts', pack('N', 0).pack('N', 1).pack('N', 1).pack('N', 1000 * self::DURATION_SECONDS));
        $stss = self::box('stss', pack('N', 0).pack('N', 1).pack('N', 1));
        $stsc = self::box('stsc', pack('N', 0).pack('N', 1).pack('N', 1).pack('N', 1).pack('N', 1));
        $stsz = self::box('stsz', pack('N', 0).pack('N', 0).pack('N', 1).pack('N', $sampleSize));
        $stco = self::box('stco', pack('N', 0).pack('N', 1).pack('N', $chunkOffset));

        $stbl = self::box('stbl', $stsd.$stts.$stss.$stsc.$stsz.$stco);
        $minf = self::box('minf', $vmhd.$dinf.$stbl);
        $mdia = self::box('mdia', $mdhd.$hdlr.$minf);
        $trak = self::box('trak', $tkhd.$mdia);

        return self::box('moov', $mvhd.$trak);
    }

    private static function identityMatrix(): string
    {
        return pack('N', 0x00010000).pack('N', 0).pack('N', 0)
            .pack('N', 0).pack('N', 0x00010000).pack('N', 0)
            .pack('N', 0).pack('N', 0).pack('N', 0x40000000);
    }

    private static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)).$type.$payload;
    }
}
