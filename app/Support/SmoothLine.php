<?php

namespace App\Support;

/** SVG path through points with a monotone cubic (Fritsch-Carlson): smooth, never overshoots the real values. */
class SmoothLine
{
    /** @param list<array{0: float, 1: float}> $pts [x, y] in the chart's 0-100 box */
    public static function path(array $pts): string
    {
        $n = count($pts);
        if ($n < 2) {
            return 'M'.$pts[0][0].','.$pts[0][1];
        }

        $d = [];
        $m = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $d[$i] = ($pts[$i + 1][1] - $pts[$i][1]) / ($pts[$i + 1][0] - $pts[$i][0]);
        }
        $m[0] = $d[0];
        $m[$n - 1] = $d[$n - 2];
        for ($i = 1; $i < $n - 1; $i++) {
            $m[$i] = $d[$i - 1] * $d[$i] <= 0 ? 0 : ($d[$i - 1] + $d[$i]) / 2;
        }
        for ($i = 0; $i < $n - 1; $i++) {
            if ($d[$i] == 0) {
                $m[$i] = $m[$i + 1] = 0;

                continue;
            }
            $a = $m[$i] / $d[$i];
            $b = $m[$i + 1] / $d[$i];
            if ($a * $a + $b * $b > 9) {
                $t = 3 / sqrt($a * $a + $b * $b);
                $m[$i] = $t * $a * $d[$i];
                $m[$i + 1] = $t * $b * $d[$i];
            }
        }

        $path = 'M'.round($pts[0][0], 2).','.round($pts[0][1], 2);
        for ($i = 0; $i < $n - 1; $i++) {
            $h = ($pts[$i + 1][0] - $pts[$i][0]) / 3;
            $path .= sprintf(' C%.2f,%.2f %.2f,%.2f %.2f,%.2f', $pts[$i][0] + $h, $pts[$i][1] + $m[$i] * $h, $pts[$i + 1][0] - $h, $pts[$i + 1][1] - $m[$i + 1] * $h, $pts[$i + 1][0], $pts[$i + 1][1]);
        }

        return $path;
    }
}
