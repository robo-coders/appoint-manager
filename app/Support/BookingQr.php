<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

final class BookingQr
{
    /** @var list<int> */
    private const TOTAL = [0, 26, 44, 70, 100, 134, 172, 196, 242, 292, 346];

    /** @var list<int> */
    private const EC = [0, 10, 16, 26, 36, 48, 64, 72, 88, 110, 130];

    /** @var list<int> */
    private const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    /** @var list<list<int>> */
    private const ALIGN = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** @var list<int> */
    private const VERSION_BITS = [
        7 => 0x07C94,
        8 => 0x085BC,
        9 => 0x09A99,
        10 => 0x0A4D3,
    ];

    public static function png(string $url, int $size = 256): string
    {
        $matrix = self::matrix($url);
        $modules = count($matrix);
        $quiet = 4;
        $total = $modules + ($quiet * 2);
        $scale = max(1, intdiv($size, $total));
        $pixels = $scale * $total;

        $image = imagecreatetruecolor($pixels, $pixels);

        if ($image === false) {
            throw new RuntimeException('Could not create the QR image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        if ($white === false || $black === false) {
            imagedestroy($image);

            throw new RuntimeException('Could not create the QR image.');
        }

        imagefill($image, 0, 0, $white);

        for ($r = 0; $r < $modules; $r++) {
            for ($c = 0; $c < $modules; $c++) {
                if ($matrix[$r][$c] !== 1) {
                    continue;
                }

                $x = ($c + $quiet) * $scale;
                $y = ($r + $quiet) * $scale;
                imagefilledrectangle($image, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        if (! is_string($png) || $png === '') {
            throw new RuntimeException('Could not create the QR image.');
        }

        return $png;
    }

    /**
     * @return list<list<int>>
     */
    public static function matrix(string $url): array
    {
        $bytes = array_values(unpack('C*', $url) ?: []);
        $count = count($bytes);
        $version = self::versionFor($count);
        $countBits = $version < 10 ? 8 : 16;
        $dataBits = 4 + $countBits + ($count * 8);
        $dataCapacity = (self::TOTAL[$version] - self::EC[$version]) * 8;

        if ($dataBits + 4 > $dataCapacity) {
            throw new InvalidArgumentException('That booking link is too long for a QR code.');
        }

        $bits = '0100'.str_pad(decbin($count), $countBits, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $remain = $dataCapacity - strlen($bits);
        $bits .= substr('0000', 0, min(4, $remain));

        while (strlen($bits) % 8 !== 0) {
            $bits .= '0';
        }

        $pad = true;

        while (strlen($bits) < $dataCapacity) {
            $bits .= $pad ? '11101100' : '00010001';
            $pad = ! $pad;
        }

        $data = [];

        for ($i = 0; $i < strlen($bits); $i += 8) {
            $data[] = bindec(substr($bits, $i, 8));
        }

        $codewords = self::errorCorrect($data, $version);
        $modules = 21 + (($version - 1) * 4);
        $reserved = self::blank($modules);
        $matrix = self::blank($modules);
        self::drawFunction($matrix, $reserved, $version);

        $best = null;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $matrix;
            self::drawData($candidate, $reserved, $codewords, $mask);
            self::drawFormat($candidate, $mask);
            $score = self::penalty($candidate);

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best ?? $matrix;
    }

    private static function versionFor(int $bytes): int
    {
        for ($version = 1; $version <= 10; $version++) {
            $countBits = $version < 10 ? 8 : 16;
            $needed = 4 + $countBits + ($bytes * 8) + 4;
            $capacity = (self::TOTAL[$version] - self::EC[$version]) * 8;

            if ($needed <= $capacity) {
                return $version;
            }
        }

        throw new InvalidArgumentException('That booking link is too long for a QR code.');
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function errorCorrect(array $data, int $version): array
    {
        $blocks = self::BLOCKS[$version];
        $ecTotal = self::EC[$version];
        $dataTotal = self::TOTAL[$version] - $ecTotal;
        $ecPer = intdiv($ecTotal, $blocks);
        $short = $blocks - ($dataTotal % $blocks);
        $shortLen = intdiv($dataTotal, $blocks);
        $longLen = $shortLen + 1;
        $offset = 0;
        $groups = [];

        for ($i = 0; $i < $blocks; $i++) {
            $len = $i < $short ? $shortLen : $longLen;
            $block = array_slice($data, $offset, $len);
            $offset += $len;
            $groups[] = ['data' => $block, 'ec' => self::rs($block, $ecPer)];
        }

        $out = [];
        $maxData = $longLen;

        for ($i = 0; $i < $maxData; $i++) {
            foreach ($groups as $group) {
                if (isset($group['data'][$i])) {
                    $out[] = $group['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $ecPer; $i++) {
            foreach ($groups as $group) {
                $out[] = $group['ec'][$i];
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function rs(array $data, int $ec): array
    {
        [$exp, $log] = self::gf();
        $gen = [1];

        for ($i = 0; $i < $ec; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);

            for ($j = 0; $j < count($gen); $j++) {
                $next[$j] ^= $gen[$j];
                $next[$j + 1] ^= self::mul($gen[$j], $exp[$i], $exp, $log);
            }

            $gen = $next;
        }

        $ecc = array_fill(0, $ec, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $ecc[0];
            array_shift($ecc);
            $ecc[] = 0;

            if ($factor === 0) {
                continue;
            }

            for ($i = 0; $i < $ec; $i++) {
                $ecc[$i] ^= self::mul($factor, $gen[$i + 1], $exp, $log);
            }
        }

        return $ecc;
    }

    /**
     * @return array{0: list<int>, 1: array<int, int>}
     */
    private static function gf(): array
    {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;

            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }

        return [$exp, $log];
    }

    /**
     * @param  list<int>  $exp
     * @param  array<int, int>  $log
     */
    private static function mul(int $a, int $b, array $exp, array $log): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $exp[$log[$a] + $log[$b]];
    }

    /**
     * @return list<list<int>>
     */
    private static function blank(int $n): array
    {
        return array_fill(0, $n, array_fill(0, $n, 0));
    }

    /**
     * @param  list<list<int>>  $matrix
     * @param  list<list<int>>  $reserved
     */
    private static function drawFunction(array &$matrix, array &$reserved, int $version): void
    {
        $n = count($matrix);
        self::finder($matrix, $reserved, 0, 0);
        self::finder($matrix, $reserved, $n - 7, 0);
        self::finder($matrix, $reserved, 0, $n - 7);

        for ($i = 0; $i < 8; $i++) {
            self::reserve($matrix, $reserved, 7, $i, 0);
            self::reserve($matrix, $reserved, $i, 7, 0);
            self::reserve($matrix, $reserved, $n - 8, $i, 0);
            self::reserve($matrix, $reserved, $n - 8 + $i, 7, 0);
            self::reserve($matrix, $reserved, 7, $n - 8 + $i, 0);
            self::reserve($matrix, $reserved, $i, $n - 8, 0);
        }

        self::reserve($matrix, $reserved, 7, 7, 0);
        self::reserve($matrix, $reserved, 7, $n - 8, 0);
        self::reserve($matrix, $reserved, $n - 8, 7, 0);

        for ($i = 8; $i < $n - 8; $i++) {
            $bit = $i % 2 === 0 ? 1 : 0;
            self::reserve($matrix, $reserved, 6, $i, $bit);
            self::reserve($matrix, $reserved, $i, 6, $bit);
        }

        foreach (self::ALIGN[$version] as $y) {
            foreach (self::ALIGN[$version] as $x) {
                if (self::finderOverlap($x, $y, $n)) {
                    continue;
                }

                self::alignment($matrix, $reserved, $x, $y);
            }
        }

        self::reserve($matrix, $reserved, ($version * 4) + 9, 8, 1);

        for ($i = 0; $i < 9; $i++) {
            self::reserve($matrix, $reserved, 8, $i, 0);
            self::reserve($matrix, $reserved, $i, 8, 0);
        }

        for ($i = 0; $i < 8; $i++) {
            self::reserve($matrix, $reserved, 8, $n - 1 - $i, 0);
            self::reserve($matrix, $reserved, $n - 1 - $i, 8, 0);
        }

        self::reserve($matrix, $reserved, 8, 8, 0);

        if ($version >= 7) {
            $bits = self::VERSION_BITS[$version];

            for ($i = 0; $i < 18; $i++) {
                $bit = ($bits >> $i) & 1;
                $r = intdiv($i, 3);
                $c = $i % 3;
                self::reserve($matrix, $reserved, $r, $n - 11 + $c, $bit);
                self::reserve($matrix, $reserved, $n - 11 + $c, $r, $bit);
            }
        }
    }

    /**
     * @param  list<list<int>>  $matrix
     * @param  list<list<int>>  $reserved
     */
    private static function finder(array &$matrix, array &$reserved, int $row, int $col): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $row + $r;
                $cc = $col + $c;

                if ($rr < 0 || $cc < 0 || $rr >= count($matrix) || $cc >= count($matrix)) {
                    continue;
                }

                $on = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6
                    && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                self::reserve($matrix, $reserved, $rr, $cc, $on ? 1 : 0);
            }
        }
    }

    private static function finderOverlap(int $x, int $y, int $n): bool
    {
        return ($x <= 8 && $y <= 8)
            || ($x <= 8 && $y >= $n - 9)
            || ($x >= $n - 9 && $y <= 8);
    }

    /**
     * @param  list<list<int>>  $matrix
     * @param  list<list<int>>  $reserved
     */
    private static function alignment(array &$matrix, array &$reserved, int $cx, int $cy): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $on = $r === -2 || $r === 2 || $c === -2 || $c === 2 || ($r === 0 && $c === 0);
                self::reserve($matrix, $reserved, $cy + $r, $cx + $c, $on ? 1 : 0);
            }
        }
    }

    /**
     * @param  list<list<int>>  $matrix
     * @param  list<list<int>>  $reserved
     */
    private static function reserve(array &$matrix, array &$reserved, int $r, int $c, int $value): void
    {
        $matrix[$r][$c] = $value;
        $reserved[$r][$c] = 1;
    }

    /**
     * @param  list<list<int>>  $matrix
     * @param  list<list<int>>  $reserved
     * @param  list<int>  $codewords
     */
    private static function drawData(array &$matrix, array $reserved, array $codewords, int $mask): void
    {
        $n = count($matrix);
        $bits = '';

        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $i = 0;
        $len = strlen($bits);
        $up = true;

        for ($col = $n - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            for ($row = 0; $row < $n; $row++) {
                $r = $up ? $n - 1 - $row : $row;

                foreach ([$col, $col - 1] as $c) {
                    if ($reserved[$r][$c] === 1) {
                        continue;
                    }

                    $bit = $i < $len ? (int) $bits[$i] : 0;
                    $i++;

                    if (self::masked($mask, $r, $c)) {
                        $bit ^= 1;
                    }

                    $matrix[$r][$c] = $bit;
                }
            }

            $up = ! $up;
        }
    }

    private static function masked(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            default => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
        };
    }

    /**
     * @param  list<list<int>>  $matrix
     */
    private static function drawFormat(array &$matrix, int $mask): void
    {
        $n = count($matrix);
        $data = $mask;
        $rem = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if ((($rem >> $i) & 1) === 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }

        $bits = ($data << 10 | ($rem & 0x3FF)) ^ 0x5412;
        $positions = [
            [[8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]],
            [[$n - 1, 8], [$n - 2, 8], [$n - 3, 8], [$n - 4, 8], [$n - 5, 8], [$n - 6, 8], [$n - 7, 8], [8, $n - 8], [8, $n - 7], [8, $n - 6], [8, $n - 5], [8, $n - 4], [8, $n - 3], [8, $n - 2], [8, $n - 1]],
        ];

        foreach ($positions as $pair) {
            for ($i = 0; $i < 15; $i++) {
                [$r, $c] = $pair[$i];
                $matrix[$r][$c] = ($bits >> $i) & 1;
            }
        }
    }

    /**
     * @param  list<list<int>>  $matrix
     */
    private static function penalty(array $matrix): int
    {
        $n = count($matrix);
        $score = 0;

        for ($r = 0; $r < $n; $r++) {
            $score += self::runPenalty($matrix[$r]);
            $col = [];

            for ($c = 0; $c < $n; $c++) {
                $col[] = $matrix[$c][$r];
            }

            $score += self::runPenalty($col);
        }

        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                $v = $matrix[$r][$c];

                if ($v === $matrix[$r][$c + 1] && $v === $matrix[$r + 1][$c] && $v === $matrix[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        $pattern = '1011101';

        for ($r = 0; $r < $n; $r++) {
            $row = implode('', $matrix[$r]);
            $col = '';

            for ($c = 0; $c < $n; $c++) {
                $col .= (string) $matrix[$c][$r];
            }

            $score += self::finderPenalty($row, $pattern);
            $score += self::finderPenalty($col, $pattern);
        }

        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                $dark += $cell;
            }
        }

        $percent = (int) (($dark * 100) / ($n * $n));
        $score += intdiv(abs($percent - 50), 5) * 10;

        return $score;
    }

    /**
     * @param  list<int>  $line
     */
    private static function runPenalty(array $line): int
    {
        $score = 0;
        $n = count($line);
        $run = 1;

        for ($i = 1; $i <= $n; $i++) {
            if ($i < $n && $line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $score += $run - 2;
            }

            $run = 1;
        }

        return $score;
    }

    private static function finderPenalty(string $line, string $pattern): int
    {
        $score = 0;
        $n = strlen($line);

        for ($i = 0; $i <= $n - 7; $i++) {
            if (substr($line, $i, 7) !== $pattern) {
                continue;
            }

            $left = $i >= 4 && substr($line, $i - 4, 4) === '0000';
            $right = $i + 11 <= $n && substr($line, $i + 7, 4) === '0000';

            if ($left || $right) {
                $score += 40;
            }
        }

        return $score;
    }
}
