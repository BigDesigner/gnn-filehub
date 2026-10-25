"""Validate canonical VERSION, WordPress header, translations and optional archive."""
import argparse
import pathlib
import re
import zipfile
import subprocess
import sys
root = pathlib.Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--requested', default='')
parser.add_argument('--archive', type=pathlib.Path)
args = parser.parse_args()
version = (root / 'gnn-filehub/VERSION').read_text(encoding='utf-8').strip()
assert re.fullmatch(r'\d+\.\d+\.\d+', version), 'VERSION must be stable semver'
requested = args.requested.removeprefix('v') or version
assert requested == version, 'Tag/override must match committed VERSION'
header = (root / 'gnn-filehub/gnn-filehub-nextgen.php').read_text(encoding='utf-8')
assert re.search(r'^\s*\* Version:\s*' + re.escape(version) + r'\s*$', header, re.M), 'Plugin header differs from VERSION'
pot = (root / 'gnn-filehub/languages/gnn-filehub.pot').read_text(encoding='utf-8')
assert f'Project-Id-Version: GNN Filehub {version}' in pot, 'Translation header differs from VERSION'
if args.archive:
    with zipfile.ZipFile(args.archive) as archive:
        names = archive.namelist()
        assert 'gnn-filehub/gnn-filehub-nextgen.php' in names
        assert archive.read('gnn-filehub/VERSION').decode().strip() == version
        assert all(name.startswith('gnn-filehub/') and '..' not in pathlib.PurePosixPath(name).parts for name in names)
        assert not any(part in name for name in names for part in ['.memory-bank/', '.tests/', '.git/', '.env'])
subprocess.run([sys.executable,str(root/'.tests/update-pot.py'),'--check'],check=True,capture_output=True)
print(version)
