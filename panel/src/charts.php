<?php
// Sunucuda çizilen sade SVG grafikler (JavaScript gerektirmez).
// Renkler tema değişkenlerinden gelir: var(--aci-chart-1) vb.
declare(strict_types=1);

/** Grafikte okunaklı eksen değerleri için "yuvarlak" üst sınır (1, 2, 5 × 10^n). */
function chart_nice_max(float $v): float {
    if ($v <= 0) return 1;
    $exp = 10 ** floor(log10($v));
    foreach ([1, 2, 5, 10] as $m) if ($v <= $m * $exp) return $m * $exp;
    return 10 * $exp;
}

/**
 * Gruplu çubuk grafik.
 * $series: [['ad', [değerler], 'css renk değişkeni'], ...] — her seri aynı uzunlukta.
 */
/** $w: çizim genişliği; dar kartlarda küçük verin ki yazılar okunaklı kalsın. */
function svg_bars(array $labels, array $series, string $title, array $notes = [], int $w = 640): string {
    $h = 240; $left = 36; $right = 8; $top = 18; $bottom = 28;
    $plotW = $w - $left - $right; $plotH = $h - $top - $bottom;
    // 4 eşit aralık, her biri tam sayı: adım "yuvarlak" bir sayı, üst sınır = 4 × adım
    $peak = (float)max(1, ...array_merge(...array_map(fn($s) => $s[1], $series)));
    $step = max(1, chart_nice_max($peak / 4));
    $max = $step * 4;
    $n = count($labels); $k = count($series);
    $groupW = $plotW / max(1, $n);
    $barW = min(22, ($groupW - 10) / max(1, $k));

    $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="aci-chart" role="img" aria-label="' . h($title) . '">';
    // yatay kılavuz çizgileri ve eksen değerleri
    for ($i = 0; $i <= 4; $i++) {
        $val = $max * $i / 4;
        $y = $top + $plotH - $plotH * $i / 4;
        $o .= '<line x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . $y . '" y2="' . $y . '" style="stroke:var(--aci-line)" />';
        $o .= '<text x="' . ($left - 6) . '" y="' . ($y + 4) . '" text-anchor="end" class="aci-chart-tick">' . number_format($val, 0, ',', '.') . '</text>';
    }
    foreach ($labels as $i => $label) {
        $gx = $left + $groupW * $i + ($groupW - $barW * $k) / 2;
        foreach ($series as $j => [$name, $values, $color]) {
            $v = (float)($values[$i] ?? 0);
            $bh = $plotH * $v / $max;
            $x = $gx + $barW * $j;
            $y = $top + $plotH - $bh;
            $o .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW - 2, 1) . '" height="' . round($bh, 1) . '" rx="2" style="fill:var(' . h($color) . ')">'
                . '<title>' . h("$label · $name: " . (int)$v) . '</title></rect>';
        }
        $o .= '<text x="' . round($left + $groupW * $i + $groupW / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="aci-chart-tick">' . h($label) . '</text>';
        // isteğe bağlı not (ör. "4 tahta"): grubun en yüksek çubuğunun üstüne
        if (($notes[$i] ?? '') !== '') {
            $top_v = max(array_map(fn($s) => (float)($s[1][$i] ?? 0), $series));
            $ny = $top + $plotH - $plotH * $top_v / $max - 5;
            $o .= '<text x="' . round($left + $groupW * $i + $groupW / 2, 1) . '" y="' . round($ny, 1) . '" text-anchor="middle" class="aci-chart-note">' . h($notes[$i]) . '</text>';
        }
    }
    $o .= '</svg>';
    return $o . chart_legend(array_map(fn($s) => [$s[0], null, $s[2]], $series));
}

/**
 * Halka grafik. $segments: [['ad', değer, 'css renk değişkeni'], ...]
 * Ortada toplam yazar; boş segmentler göstergede yine listelenir.
 */
function svg_donut(array $segments, string $centerLabel, string $title): string {
    $total = array_sum(array_column($segments, 1));
    $r = 60; $cx = 80; $cy = 80; $sw = 22;
    $circ = 2 * M_PI * $r;
    $o = '<div class="d-flex align-items-center gap-3 flex-wrap"><svg viewBox="0 0 160 160" class="aci-donut" role="img" aria-label="' . h($title) . '">';
    $o .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" style="stroke:var(--aci-tertiary)" stroke-width="' . $sw . '" />';
    $offset = 0;
    if ($total > 0) {
        foreach ($segments as [$name, $value, $color]) {
            if ($value <= 0) continue;
            $len = $circ * $value / $total;
            $o .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke-width="' . $sw . '"'
                . ' style="stroke:var(' . h($color) . ')" stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '"'
                . ' stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')">'
                . '<title>' . h("$name: $value") . '</title></circle>';
            $offset += $len;
        }
    }
    $o .= '<text x="' . $cx . '" y="' . ($cy + 2) . '" text-anchor="middle" class="aci-donut-total">' . (int)$total . '</text>';
    $o .= '<text x="' . $cx . '" y="' . ($cy + 20) . '" text-anchor="middle" class="aci-chart-tick">' . h($centerLabel) . '</text>';
    $o .= '</svg>';
    return $o . chart_legend($segments, $total) . '</div>';
}

/** Gösterge: [['ad', değer|null, 'css renk değişkeni'], ...] */
function chart_legend(array $items, ?int $total = null): string {
    $o = '<ul class="aci-legend">';
    foreach ($items as [$name, $value, $color]) {
        $o .= '<li><span class="aci-swatch" style="background:var(' . h($color) . ')"></span>' . h($name);
        if ($value !== null) {
            $o .= ' <span class="text-secondary">' . (int)$value . ($total ? ' · %' . round($value / $total * 100) : '') . '</span>';
        }
        $o .= '</li>';
    }
    return $o . '</ul>';
}
