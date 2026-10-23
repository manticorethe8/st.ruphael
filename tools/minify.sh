#!/bin/sh
# ---------------------------------------------------------------------------
# tools/minify.sh — regenerate the minified copies the browser actually loads.
#
# The commented sources in js/ and css/ remain the real code (the repo stays
# the source of truth). Every time you change js/app.js, js/admin.js,
# js/api.js or js/features.js, run this once and commit the updated *.min.*
# files — the pages then serve the compact, comment-free build.
#
# Requires Node.js + terser (installed once with: npm i -g terser)
# ---------------------------------------------------------------------------
set -e
cd "$(dirname "$0")/.."

TERSER="$(command -v terser || true)"
if [ -z "$TERSER" ]; then
  echo "terser not found — run: npm i -g terser" >&2
  exit 1
fi

for f in js/features.js js/api.js js/app.js js/admin.js; do
  out="${f%.js}.min.js"
  echo "minifying $f -> $out"
  $TERSER "$f" -c -o "$out"
done

echo "stripping CSS comments (styles.css, fonts.css)"
php -r '
foreach (["css/styles.css", "css/fonts.css"] as $src) {
  $s  = file_get_contents($src);
  $o  = ""; $i = 0; $n = strlen($s);
  while ($i < $n) {
    if ($s[$i] === "/" && ($s[$i+1] ?? "") === "*") {
      $j = strpos($s, "*/", $i + 2);
      if ($j === false) { $o .= substr($s, $i); break; }
      $i = $j + 2;
    } else { $o .= $s[$i++]; }
  }
  $out = preg_replace("/\.css$/", ".min.css", $src);
  file_put_contents($out, $o);
  echo $src . " -> " . $out . " (" . strlen($s) . " -> " . strlen($o) . " bytes)\n";
}
'

echo "done."
