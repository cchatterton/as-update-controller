#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
slug=as-update-controller
mkdir -p dist
python3 - "$slug" <<'PY'
import pathlib,sys,zipfile,shutil
slug=sys.argv[1]
with zipfile.ZipFile('dist/'+slug+'.zip','w',zipfile.ZIP_DEFLATED) as z:
 for p in sorted(pathlib.Path(slug).rglob('*')):
  if p.is_file() and p.name!='.DS_Store': z.write(p,p.as_posix())
shutil.copyfile('dist/'+slug+'.zip',slug+'.zip')
PY
