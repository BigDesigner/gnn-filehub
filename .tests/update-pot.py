"""Generate/check the plugin's deterministic PHP gettext catalog without runtime dependencies."""
import json
import pathlib
import re
import sys
root = pathlib.Path(__file__).resolve().parents[1]
messages = set()
pattern = r"(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'gnn-filehub'"
for path in (root / 'gnn-filehub').rglob('*.php'):
    for match in re.finditer(pattern, path.read_text(encoding='utf-8')):
        messages.add(match.group(1).replace("\\'", "'").replace('\\\\', '\\'))
version = (root / 'gnn-filehub/VERSION').read_text().strip()
catalog = 'msgid ""\nmsgstr ""\n' + json.dumps('Project-Id-Version: GNN Filehub ' + version + '\n') + '\n"MIME-Version: 1.0\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n'
for message in sorted(messages):
    catalog += '\nmsgid ' + json.dumps(message, ensure_ascii=False) + '\nmsgstr ""\n'
target = root / 'gnn-filehub/languages/gnn-filehub.pot'
if '--check' in sys.argv:
    assert target.read_text(encoding='utf-8') == catalog, 'Run python .tests/update-pot.py to refresh translations'
else:
    target.write_text(catalog, encoding='utf-8')
print('Translation messages:', len(messages))
