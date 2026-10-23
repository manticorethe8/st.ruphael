<?php
/* ============================================================
   Ethiopian (Ge'ez) calendar — exact JDN-based conversion.
   Months 1..13:  መስከረም … ነሐሴ (12) and ጳጉሜ (13).
   ጳጉሜ / Pagume is a first-class month of this calendar:
   5 days normally, 6 days when (year % 4 === 3).
   ============================================================ */

const SR_JD_EPOCH_AMETE_MIHRET = 1723856;

const SR_ET_MONTHS_AM = ['መስከረም','ጥቅምት','ኅዳር','ታህሣሥ','ጥር','የካቲት','መጋቢት','ሚያዝያ','ግንቦት','ሰኔ','ሐምሌ','ነሐሴ','ጳጉሜ'];
const SR_ET_MONTHS_EN = ['Meskerem','Tikemet','Hidar','Tahsas','Tir','Yekatit','Megabit','Miyazya','Ginbot','Sene','Hamle','Nehase','Pagume'];
const SR_ET_DAYS_AM   = ['እሁድ','ሰኞ','ማክሰኞ','ረቡዕ','ሐሙስ','ዓርብ','ቅዳሜ'];

/** Ethiopian leap year → ጳጉሜ has 6 days */
function et_is_leap(int $year): bool { return $year % 4 === 3; }

/** Number of days in an Ethiopian month (13 = ጳጉሜ) */
function et_month_days(int $year, int $month): int
{
    if ($month < 1 || $month > 13) return 0;
    if ($month <= 12) return 30;
    return et_is_leap($year) ? 6 : 5;
}

function greg_to_jdn(int $y, int $m, int $d): int
{
    $a = intdiv(14 - $m, 12);
    $y2 = $y + 4800 - $a;
    $m2 = $m + 12 * $a - 3;
    return $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
}

function jdn_to_greg(int $jdn): array
{
    $a = $jdn + 32044;
    $b = intdiv(4 * $a + 3, 146097);
    $c = $a - intdiv(146097 * $b, 4);
    $d = intdiv(4 * $c + 3, 1461);
    $e = $c - intdiv(1461 * $d, 4);
    $m = intdiv(5 * $e + 2, 153);
    return [
        'y' => 100 * $b + $d - 4800 + intdiv($m, 10),
        'm' => $m + 3 - 12 * intdiv($m, 10),
        'd' => $e - intdiv(153 * $m + 2, 5) + 1,
    ];
}

function et_to_jdn(int $y, int $m, int $d): int
{
    return (SR_JD_EPOCH_AMETE_MIHRET + 365) + 365 * ($y - 1) + intdiv($y, 4) + 30 * $m + $d - 31;
}

function jdn_to_et(int $jdn): array
{
    $r = ($jdn - SR_JD_EPOCH_AMETE_MIHRET) % 1461;
    if ($r < 0) $r += 1461;
    $n = ($r % 365) + 365 * intdiv($r, 1460);
    return [
        'y' => 4 * intdiv($jdn - SR_JD_EPOCH_AMETE_MIHRET, 1461) + intdiv($r, 365) - intdiv($r, 1460),
        'm' => intdiv($n, 30) + 1,
        'd' => ($n % 30) + 1,
    ];
}

/** '2026-08-17' → ['y'=>2018,'m'=>12,'d'=>11] */
function iso_to_et(string $iso): array
{
    [$y, $m, $d] = array_map('intval', explode('-', substr($iso, 0, 10)));
    return jdn_to_et(greg_to_jdn($y, $m, $d));
}

/** Ethiopian date → ISO Gregorian date string */
function et_to_iso(int $ey, int $em, int $ed): string
{
    $g = jdn_to_greg(et_to_jdn($ey, $em, $ed));
    return sprintf('%04d-%02d-%02d', $g['y'], $g['m'], $g['d']);
}

/** Day of week for an ISO date: 0=Sunday … 6=Saturday */
function iso_dow(string $iso): int
{
    [$y, $m, $d] = array_map('intval', explode('-', substr($iso, 0, 10)));
    return (greg_to_jdn($y, $m, $d) + 1) % 7;
}

/** 'sat' | 'sun' | 'other' */
function iso_day_kind(string $iso): string
{
    $w = iso_dow($iso);
    return $w === 6 ? 'sat' : ($w === 0 ? 'sun' : 'other');
}

/** Today's Ethiopian date */
function et_today(): array { return iso_to_et(date('Y-m-d')); }

/** Ethiopian academic year that a Gregorian ISO date belongs to */
function et_year_of(string $iso): int { return iso_to_et($iso)['y']; }

/**
 * All Saturday/Sunday columns of one Ethiopian month.
 * Works for ጳጉሜ (13) too — it simply has 5 or 6 days.
 * @return array<int,array{et_day:int,dow:string,iso:string}>
 */
function et_month_weekend_cols(int $etY, int $etM): array
{
    $cols = [];
    $days = et_month_days($etY, $etM);
    for ($d = 1; $d <= $days; $d++) {
        $iso  = et_to_iso($etY, $etM, $d);
        $kind = iso_day_kind($iso);
        if ($kind === 'sat' || $kind === 'sun') {
            $cols[] = ['et_day' => $d, 'dow' => $kind, 'iso' => $iso];
        }
    }
    return $cols;
}

/** Every day of an Ethiopian month (used by the calendar UI) */
function et_month_days_list(int $etY, int $etM): array
{
    $out  = [];
    $days = et_month_days($etY, $etM);
    for ($d = 1; $d <= $days; $d++) {
        $iso = et_to_iso($etY, $etM, $d);
        $out[] = ['et_day' => $d, 'iso' => $iso, 'dow' => iso_day_kind($iso), 'dow_index' => iso_dow($iso)];
    }
    return $out;
}

/** Month metadata for the API (13 entries — ጳጉሜ included) */
function et_months_meta(int $etY): array
{
    $out = [];
    for ($m = 1; $m <= 13; $m++) {
        $out[] = [
            'month'    => $m,
            'name_am'  => SR_ET_MONTHS_AM[$m - 1],
            'name_en'  => SR_ET_MONTHS_EN[$m - 1],
            'days'     => et_month_days($etY, $m),
            'is_pagume'=> $m === 13,
        ];
    }
    return $out;
}

/** Ge'ez numerals */
function to_geez(int $n): string
{
    if ($n === 0) return '0';
    $U = ['','፩','፪','፫','፬','፭','፮','፯','፰','፱'];
    $T = ['','፲','፳','፴','፵','፶','፷','፸','፹','፺'];
    $pairs = [];
    while ($n > 0) { $pairs[] = $n % 100; $n = intdiv($n, 100); }
    $pairs = array_reverse($pairs);
    $out = ''; $L = count($pairs);
    foreach ($pairs as $i => $p) {
        $pos = $L - 1 - $i;
        $s = $T[intdiv($p, 10)] . $U[$p % 10];
        if ($pos > 0) { if ($p === 1) $s = ''; $out .= $s . ($pos % 2 === 1 ? '፻' : '፼'); }
        else $out .= $s;
    }
    return $out;
}

/** Human label, e.g. "ቅዳሜ ፲፩ ነሐሴ ፳፻፲፰ ዓ.ም" or "Saturday, Nehase 11, 2018" */
function et_label(string $iso, string $lang = 'am'): string
{
    $et = iso_to_et($iso);
    $w  = iso_dow($iso);
    if ($lang === 'am') {
        return SR_ET_DAYS_AM[$w] . ' ' . to_geez($et['d']) . ' ' . SR_ET_MONTHS_AM[$et['m'] - 1] . ' ' . to_geez($et['y']) . ' ዓ.ም';
    }
    $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    return $days[$w] . ', ' . SR_ET_MONTHS_EN[$et['m'] - 1] . ' ' . $et['d'] . ', ' . $et['y'];
}
